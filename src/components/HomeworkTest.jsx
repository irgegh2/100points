import { useEffect, useState, useRef } from 'react'
import { ChevronLeft, Check, Lock, Printer } from 'lucide-react'
import TestResult from './TestResult.jsx'
import { homeworkGet, homeworkSubmit, mockGet, mockSubmit, draftSave } from '../api.js'
import { printQuestions } from '../printQuestions.js'

export function isAnswered(type, v) {
  if (type === 'single') return v != null
  if (type === 'multi') return Array.isArray(v) && v.length > 0
  return typeof v === 'string' && v.trim() !== ''
}

// kind: 'homework' (по умолчанию) | 'mock'. Формат ответа эндпоинтов одинаковый.
export default function HomeworkTest({ homeworkId, kind = 'homework', onBack }) {
  const apiGet = kind === 'mock' ? mockGet : homeworkGet
  const apiSubmit = kind === 'mock' ? mockSubmit : homeworkSubmit
  const [data, setData] = useState(null)       // { locked, homework, questions, submission }
  const [error, setError] = useState('')
  const [cur, setCur] = useState(0)
  const [answers, setAnswers] = useState({})   // { [questionId]: value }
  const [submitted, setSubmitted] = useState(false)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    let alive = true
    apiGet(homeworkId)
      .then((d) => {
        if (!alive) return
        setData(d)
        if (d.submission) setSubmitted(true)
        // Восстанавливаем незаконченные ответы (черновик) — переживает перезагрузку/другое устройство.
        else if (d.draft && typeof d.draft === 'object') setAnswers(d.draft)
      })
      .catch((e) => { if (alive) setError(e.message) })
    return () => { alive = false }
  }, [homeworkId])

  // Автосохранение черновика (дебаунс ~700мс) — пока не отправлено.
  const dirty = useRef(false)
  useEffect(() => {
    if (!data || submitted || !dirty.current) return
    const t = setTimeout(() => { draftSave(kind, homeworkId, answers).catch(() => {}) }, 700)
    return () => clearTimeout(t)
  }, [answers, data, submitted, kind, homeworkId])

  if (error) return <div className="test"><button className="test__back" onClick={onBack}><ChevronLeft size={20} /></button><div className="hw__empty">Ошибка: {error}</div></div>
  if (!data) return <div className="test"><div className="hw__empty">Загрузка…</div></div>

  // Замок: нет доступа к курсу или повышенный без подписки.
  if (data.locked) return (
    <div className="test">
      <button className="test__back" onClick={onBack}><ChevronLeft size={20} /></button>
      <div className="test__locked">
        <Lock size={40} />
        <h3>{data.locked === 'subscription' ? 'Повышенный уровень доступен по подписке «Стандарт»' : 'Нет доступа к курсу'}</h3>
        <p>{data.locked === 'subscription' ? 'Оформи тариф «Стандарт» или выше — повышенные задания откроются.' : 'Доступ к курсу выдаёт администратор.'}</p>
      </div>
    </div>
  )

  if (submitted) return <TestResult homeworkId={homeworkId} kind={kind} onBack={onBack} onRetry={() => { setSubmitted(false); setAnswers({}); setCur(0) }} />

  const questions = data.questions
  const total = questions.length
  const q = questions[cur]
  const value = answers[q.id]
  const required = questions.filter((qq) => !qq.optional)
  const requiredDone = required.every((qq) => isAnswered(qq.type, answers[qq.id]))
  const answeredCount = questions.filter((qq) => isAnswered(qq.type, answers[qq.id])).length
  const canNext = isAnswered(q.type, value) || q.optional

  const setChoice = (v) => { dirty.current = true; setAnswers((a) => ({ ...a, [q.id]: v })) }
  const toggleMulti = (oi) => { dirty.current = true; setAnswers((a) => {
    const arr = Array.isArray(a[q.id]) ? a[q.id] : []
    return { ...a, [q.id]: arr.includes(oi) ? arr.filter((x) => x !== oi) : [...arr, oi] }
  }) }

  const submit = async () => {
    setBusy(true)
    try {
      const payload = questions
        .filter((qq) => isAnswered(qq.type, answers[qq.id]))
        .map((qq) => ({ question_id: qq.id, value: answers[qq.id] }))
      await apiSubmit(homeworkId, payload)
      setSubmitted(true)
    } catch (e) { alert(e.message) }
    finally { setBusy(false) }
  }
  const next = () => { if (cur < total - 1) setCur(cur + 1); else if (requiredDone) submit() }

  return (
    <div className="test">
      <div className="test__head">
        <button className="test__back" onClick={onBack}><ChevronLeft size={20} /></button>
        <div className="test__head-info">
          <h2>{data.homework.title}</h2>
          <span>Отвечено {answeredCount} из {total}{data.homework.level === 'hard' && ' · повышенный'}</span>
        </div>
        <button className="btn btn-light btn-sm" style={{ marginLeft: 'auto' }} onClick={() => printQuestions(data.homework.title, questions)}><Printer size={14} /> Печать</button>
      </div>

      <div className="test__grid">
        <div className="test__q">
          <div className="test__num">№ {cur + 1}{q.optional && <span className="test__opt-tag"> · необязательный</span>}</div>
          <div className="test__question">{q.text}</div>

          {q.type === 'single' && (
            <div className="test__opts">
              {q.options.map((o, oi) => (
                <button key={oi} className={`test__opt ${value === oi ? 'selected' : ''}`} onClick={() => setChoice(oi)}>
                  <span className="test__check test__check--round">{value === oi && <span className="test__dot" />}</span>
                  <span>{o}</span>
                </button>
              ))}
            </div>
          )}

          {q.type === 'multi' && (
            <div className="test__opts">
              {q.options.map((o, oi) => {
                const on = Array.isArray(value) && value.includes(oi)
                return (
                  <button key={oi} className={`test__opt ${on ? 'selected' : ''}`} onClick={() => toggleMulti(oi)}>
                    <span className="test__check">{on && <Check size={14} />}</span>
                    <span>{o}</span>
                  </button>
                )
              })}
            </div>
          )}

          {q.type === 'text' && (
            <input className="test__input" type="text" placeholder="Введите ответ" value={value || ''} onChange={(e) => setChoice(e.target.value)} />
          )}

          {q.type === 'textarea' && (
            <>
              <textarea className="test__textarea" placeholder="Напиши развёрнутый ответ…" rows={6} value={value || ''} onChange={(e) => setChoice(e.target.value)} />
              <p className="test__hint">Развёрнутый ответ проверит эксперт вручную.</p>
            </>
          )}

          <button className="btn btn-blue test__next" disabled={!canNext} onClick={next}>
            {cur === total - 1 ? (busy ? 'Отправляем…' : 'Завершить') : 'Далее'}
          </button>
        </div>

        <aside className="test__side">
          <div className="test__progress">
            <div className="test__progress-top"><b>Прогресс</b><span>{answeredCount}/{total}</span></div>
            <div className="test__progress-bar"><span style={{ width: Math.round((answeredCount / total) * 100) + '%' }} /></div>
          </div>
          <div className="test__sidesteps">
            {questions.map((qq, i) => {
              const done = isAnswered(qq.type, answers[qq.id])
              const active = i === cur
              return (
                <button key={qq.id} className={`test__step ${active ? 'active' : ''} ${done ? 'done' : ''} ${qq.optional ? 'opt' : ''}`} onClick={() => setCur(i)}>
                  {done && !active ? <Check size={14} /> : i + 1}
                </button>
              )
            })}
          </div>
          <button className="btn btn-orange btn-block" disabled={!requiredDone || busy} onClick={submit}>{busy ? 'Отправляем…' : 'Завершить работу'}</button>
          {!requiredDone && <p className="test__hint" style={{ textAlign: 'center' }}>Ответь на все обязательные вопросы</p>}
          <p className="test__hint" style={{ textAlign: 'center', opacity: 0.7 }}>Ответы сохраняются автоматически — можно вернуться позже</p>
        </aside>
      </div>
    </div>
  )
}
