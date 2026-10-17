import { useState } from 'react'
import { ChevronLeft, Check, X, ChevronDown, RefreshCw, Printer } from 'lucide-react'
import { bankCheck } from '../api.js'
import { canonicalTextAnswer } from '../answerDisplay.js'

const isAnswered = (type, v) => type === 'single' ? v != null : type === 'multi' ? (Array.isArray(v) && v.length > 0) : (typeof v === 'string' && v.trim() !== '')
const ansText = (r) => {
  const v = r.your_value
  if (r.type === 'single') return v != null ? r.options[v] : '— нет ответа'
  if (r.type === 'multi') return Array.isArray(v) && v.length ? v.map((i) => r.options[i]).join(', ') : '— нет ответа'
  return v && String(v).trim() ? v : '— нет ответа'
}
const correctText = (r) => {
  if (r.type === 'single') return r.options[r.correct]
  if (r.type === 'multi') return (r.correct || []).map((i) => r.options[i]).join(', ')
  return canonicalTextAnswer(r.correct)
}

export default function BankTrainer({ questions, title, onExit, onPrint }) {
  const [answers, setAnswers] = useState({})
  const [cur, setCur] = useState(0)
  const [results, setResults] = useState(null)
  const [busy, setBusy] = useState(false)
  const [open, setOpen] = useState(() => new Set())

  const total = questions.length
  const q = questions[cur]
  const value = answers[q?.id]
  const answered = questions.filter((qq) => isAnswered(qq.type, answers[qq.id])).length

  const setChoice = (v) => setAnswers((a) => ({ ...a, [q.id]: v }))
  const toggleMulti = (oi) => setAnswers((a) => {
    const arr = Array.isArray(a[q.id]) ? a[q.id] : []
    return { ...a, [q.id]: arr.includes(oi) ? arr.filter((x) => x !== oi) : [...arr, oi] }
  })

  const finish = async () => {
    setBusy(true)
    try {
      const payload = questions.map((qq) => ({ question_id: qq.id, value: answers[qq.id] ?? null }))
      const r = await bankCheck(payload)
      setResults(r)
    } catch (e) { alert(e.message) } finally { setBusy(false) }
  }
  const retry = () => { setResults(null); setAnswers({}); setCur(0); setOpen(new Set()) }
  const toggle = (i) => setOpen((s) => { const x = new Set(s); x.has(i) ? x.delete(i) : x.add(i); return x })

  // ── Результаты ──
  if (results) {
    return (
      <div className="result">
        <button className="result__back" onClick={onExit}><ChevronLeft size={18} /> К банку заданий</button>
        <div className="result__grid">
          <div className="result__main">
            <h2 className="result__title">Разбор тренировки</h2>
            {results.results.map((r, i) => (
              <div className="rescard" key={r.id}>
                <div className="rescard__top">
                  <span className={`rescard__badge ${r.status}`}>
                    {r.status === 'correct' && <><Check size={13} /> Верно</>}
                    {r.status === 'wrong' && <><X size={13} /> Неверно</>}
                    {r.status === 'partial' && <>{r.earned} из {r.points} б.</>}
                  </span>
                  <div className="rescard__q">{i + 1}. {r.text}</div>
                </div>
                <div className="rescard__answer"><span>Твой ответ:</span> {ansText(r)}</div>
                <button className="rescard__toggle" onClick={() => toggle(r.id)}>Показать решение <ChevronDown size={15} className={open.has(r.id) ? 'rot' : ''} /></button>
                {open.has(r.id) && (
                  <div className="rescard__solution">
                    {r.status !== 'correct' && <div className="rescard__correct">Правильный ответ: <b>{correctText(r)}</b></div>}
                    <p>{r.solution}</p>
                  </div>
                )}
              </div>
            ))}
          </div>
          <aside className="result__side">
            <div className="result__panel">
              <div className="result__score"><b>{results.score}</b><span>/{results.max_score}</span></div>
              <h3>Тренировка пройдена</h3>
              <p>Можно перерешать для прокачки скилов.</p>
              <button className="btn btn-blue btn-block" onClick={retry}><RefreshCw size={16} /> Пройти заново</button>
              <button className="btn btn-light btn-block" onClick={onExit}>К банку заданий</button>
            </div>
          </aside>
        </div>
      </div>
    )
  }

  // ── Прохождение ──
  return (
    <div className="test">
      <div className="test__head">
        <button className="test__back" onClick={onExit}><ChevronLeft size={20} /></button>
        <div className="test__head-info">
          <h2>{title || 'Тренировка'}</h2>
          <span>Отвечено {answered} из {total}</span>
        </div>
        {onPrint && <button className="btn btn-light btn-sm" style={{ marginLeft: 'auto' }} onClick={onPrint}><Printer size={14} /> Печать</button>}
      </div>

      <div className="test__grid">
        <div className="test__q">
          <div className="test__num">№ {cur + 1}{q.difficulty ? <span className="test__opt-tag"> · сложность {q.difficulty}</span> : null}</div>
          <div className="test__question">{q.text}</div>

          {q.type === 'single' && (
            <div className="test__opts">
              {q.options.map((o, oi) => (
                <button key={oi} className={`test__opt ${value === oi ? 'selected' : ''}`} onClick={() => setChoice(oi)}>
                  <span className="test__check test__check--round">{value === oi && <span className="test__dot" />}</span><span>{o}</span>
                </button>
              ))}
            </div>
          )}
          {q.type === 'multi' && (
            <div className="test__opts">
              {q.options.map((o, oi) => {
                const on = Array.isArray(value) && value.includes(oi)
                return <button key={oi} className={`test__opt ${on ? 'selected' : ''}`} onClick={() => toggleMulti(oi)}><span className="test__check">{on && <Check size={14} />}</span><span>{o}</span></button>
              })}
            </div>
          )}
          {(q.type === 'text') && <input className="test__input" type="text" placeholder="Введите ответ" value={value || ''} onChange={(e) => setChoice(e.target.value)} />}
          {q.type === 'textarea' && <textarea className="test__textarea" rows={5} placeholder="Ответ…" value={value || ''} onChange={(e) => setChoice(e.target.value)} />}

          <button className="btn btn-blue test__next" onClick={() => cur < total - 1 ? setCur(cur + 1) : finish()} disabled={busy}>
            {cur === total - 1 ? (busy ? 'Проверяем…' : 'Проверить') : 'Далее'}
          </button>
        </div>

        <aside className="test__side">
          <div className="test__progress">
            <div className="test__progress-top"><b>Прогресс</b><span>{answered}/{total}</span></div>
            <div className="test__progress-bar"><span style={{ width: Math.round((answered / total) * 100) + '%' }} /></div>
          </div>
          <div className="test__sidesteps">
            {questions.map((qq, i) => (
              <button key={qq.id} className={`test__step ${i === cur ? 'active' : ''} ${isAnswered(qq.type, answers[qq.id]) ? 'done' : ''}`} onClick={() => setCur(i)}>
                {isAnswered(qq.type, answers[qq.id]) && i !== cur ? <Check size={14} /> : i + 1}
              </button>
            ))}
          </div>
          <button className="btn btn-orange btn-block" onClick={finish} disabled={busy}>{busy ? 'Проверяем…' : 'Проверить тренировку'}</button>
        </aside>
      </div>
    </div>
  )
}
