import { useEffect, useState } from 'react'
import { ChevronLeft, Check, X, Clock, ChevronDown, RefreshCw, GraduationCap } from 'lucide-react'
import { homeworkResult, mockResult } from '../api.js'

function answerText(r) {
  const v = r.your_value
  if (r.type === 'single') return v != null ? r.options[v] : '— нет ответа'
  if (r.type === 'multi') return Array.isArray(v) && v.length ? v.map((i) => r.options[i]).join(', ') : '— нет ответа'
  return v && String(v).trim() ? v : '— нет ответа'
}
function correctText(r) {
  if (r.type === 'single') return r.options[r.correct]
  if (r.type === 'multi') return (r.correct || []).map((i) => r.options[i]).join(', ')
  if (r.type === 'text') return (r.correct || []).join(' / ')
  return ''
}

export default function TestResult({ homeworkId, kind = 'homework', onBack, onRetry, allowRetry = true }) {
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [open, setOpen] = useState(() => new Set())
  const toggle = (i) => setOpen((s) => { const x = new Set(s); x.has(i) ? x.delete(i) : x.add(i); return x })

  useEffect(() => {
    let alive = true
    ;(kind === 'mock' ? mockResult : homeworkResult)(homeworkId)
      .then((d) => { if (alive) setData(d) })
      .catch((e) => { if (alive) setError(e.message) })
    return () => { alive = false }
  }, [homeworkId])

  if (error) return <div className="result"><button className="result__back" onClick={onBack}><ChevronLeft size={18} /> Назад</button><div className="hw__empty">Ошибка: {error}</div></div>
  if (!data) return <div className="result"><div className="hw__empty">Загрузка результата…</div></div>

  const { results, submission, expert } = data
  const total = results.reduce((s, r) => s + r.points, 0)
  const earned = results.reduce((s, r) => s + r.earned, 0)
  const pending = submission.status === 'review'

  return (
    <div className="result">
      <button className="result__back" onClick={onBack}><ChevronLeft size={18} /> {kind === 'mock' ? 'К пробникам' : 'Вернуться к уроку'}</button>

      <div className="result__grid">
        <div className="result__main">
          <h2 className="result__title">Ответы на задания</h2>

          {results.map((r, idx) => (
            <div className="rescard" key={r.id}>
              <div className="rescard__top">
                <span className={`rescard__badge ${r.status}`}>
                  {r.status === 'correct' && <><Check size={13} /> Верно</>}
                  {r.status === 'wrong' && <><X size={13} /> Неверно</>}
                  {r.status === 'partial' && <>{r.earned} из {r.points} б.</>}
                  {r.status === 'pending' && <><Clock size={13} /> На проверке</>}
                  {r.status === 'graded' && <>{r.earned} из {r.points} б.</>}
                </span>
                <div className="rescard__q">{idx + 1}. {r.text}{r.optional && <span className="test__opt-tag"> · необязательный</span>}</div>
              </div>

              <div className="rescard__answer"><span>Твой ответ:</span> {answerText(r)}</div>

              {r.type !== 'textarea' && (
                <>
                  <button className="rescard__toggle" onClick={() => toggle(r.id)}>
                    Показать решение <ChevronDown size={15} className={open.has(r.id) ? 'rot' : ''} />
                  </button>
                  {open.has(r.id) && (
                    <div className="rescard__solution">
                      {(r.status === 'wrong' || r.status === 'partial') && <div className="rescard__correct">Правильный ответ: <b>{correctText(r)}</b></div>}
                      <p>{r.solution}</p>
                    </div>
                  )}
                </>
              )}

              {r.type === 'textarea' && r.status === 'pending' && (
                <div className="rescard__pending"><Clock size={15} /> Развёрнутый ответ проверит эксперт — баллы появятся позже</div>
              )}
              {r.type === 'textarea' && r.status === 'graded' && (
                <div className="rescard__mentor">
                  <div className="rescard__mentor-head">Комментарий эксперта · {r.earned} из {r.points} б.</div>
                  <p>{r.mentor_comment || '—'}</p>
                </div>
              )}
            </div>
          ))}
        </div>

        <aside className="result__side">
          {pending ? (
            <div className="result__panel result__panel--pending">
              <Clock size={52} />
              <h3>Работа на проверке</h3>
              <p>Тесты проверены автоматически: <b>{earned} из {total} б.</b> Развёрнутые ответы проверит эксперт и выставит баллы с комментарием.</p>
              <button className="btn btn-light btn-block" onClick={onBack}>Вернуться к уроку</button>
            </div>
          ) : (
            <div className="result__panel">
              <div className="result__score"><b>{earned}</b><span>/{total}</span></div>
              <h3>{kind === 'mock' ? 'Пробник пройден' : 'Домашнее задание пройдено'}</h3>
              <p>Отличная работа! Можно двигаться дальше.</p>
              {allowRetry && <button className="btn btn-light btn-block" onClick={onRetry}><RefreshCw size={16} /> Пройти ещё раз</button>}
            </div>
          )}

          {expert && (
            <div className="result__expert">
              <div className="result__expert-head"><GraduationCap size={16} /> Проверил эксперт</div>
              <div className="result__expert-name">{expert.last_name} {expert.first_name}</div>
              <div className="result__expert-meta">{expert.university}</div>
              <div className="result__expert-score">ЕГЭ {expert.ege_score} баллов</div>
            </div>
          )}
        </aside>
      </div>
    </div>
  )
}
