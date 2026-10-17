import { useEffect, useState } from 'react'
import { ChevronLeft, Check, X, Clock, ChevronDown, RefreshCw, GraduationCap, FileText, Download } from 'lucide-react'
import { homeworkResult, mockResult } from '../api.js'
import MarkdownContent from './MarkdownContent.jsx'
import { pluralRu } from '../ruPlural.js'
import AudioMessage from './AudioMessage.jsx'
import KinescopeVideo from './KinescopeVideo.jsx'
import { canonicalTextAnswer } from '../answerDisplay.js'

function answerText(r) {
  const v = r.your_value
  if (r.type === 'single') return v != null ? r.options[v] : '— нет ответа'
  if (r.type === 'multi') return Array.isArray(v) && v.length ? v.map((i) => r.options[i]).join(', ') : '— нет ответа'
  if (r.type === 'file') return v?.note || '— нет подписи'
  if (r.type === 'audio') return v?.files?.length ? 'Аудиоответ записан' : '— нет аудиоответа'
  return v && String(v).trim() ? v : '— нет ответа'
}
function correctText(r) {
  if (r.type === 'single') return r.options[r.correct]
  if (r.type === 'multi') return (r.correct || []).map((i) => r.options[i]).join(', ')
  if (r.type === 'text') return canonicalTextAnswer(r.correct)
  return ''
}

function englishPlacement(score) {
  if (score <= 5) return { code: 'Starter', name: 'Разгон', text: 'Начни с крепкой базы A1: Present Simple, глагол be, местоимения, артикли и базовая лексика.' }
  if (score <= 10) return { code: 'A1', name: 'База', text: 'Базовые конструкции уже знакомы. Закрепи грамматику и расширяй словарный запас.' }
  if (score <= 15) return { code: 'A2', name: 'Нормис', text: 'Хороший фундамент для системной подготовки к ЕГЭ. Дальше нужны более сложная лексика, чтение и аудирование.' }
  if (score <= 19) return { code: 'B1', name: 'Имба', text: 'Сильная стартовая позиция. Развивай точность, тематическую лексику и стратегии экзаменационных заданий.' }
  if (score <= 22) return { code: 'B2', name: 'Комфортик', text: 'Уверенный уровень для подготовки к высоким результатам на ЕГЭ. Фокус — формат, письмо и устная речь.' }
  return { code: 'C1', name: 'Краш', text: 'Очень высокий уровень. Главный фокус — экзаменационные критерии, точность формулировок и тайм-менеджмент.' }
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
  const placement = kind === 'homework' && /уровень английского/i.test(data.homework?.title || '')
    ? englishPlacement(earned)
    : null

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
                <div className="rescard__q">
                  <span>{idx + 1}.</span>
                  <div><MarkdownContent source={r.text} empty="" />{r.optional && <span className="test__opt-tag"> · необязательный</span>}</div>
                </div>
              </div>

              {r.images?.length > 0 && (
                <div className="test__imgs">
                  {r.images.map((src, i) => (
                    <a key={i} href={src} target="_blank" rel="noreferrer" title="Открыть в полном размере">
                      <img src={src} alt={`Иллюстрация ${i + 1} к вопросу`} loading="lazy" />
                    </a>
                  ))}
                </div>
              )}
              {r.audio?.length > 0 && <div className="test__audio">{r.audio.map((item, i) => <AudioMessage key={`${item.url}-${i}`} src={item.url} name={item.name} />)}</div>}
              {r.videos?.length > 0 && <div className="test__videos">{r.videos.map((item, i) => <KinescopeVideo key={`${item.url}-${i}`} url={item.url} />)}</div>}
              {r.files?.length > 0 && (
                <div className="test__files">
                  {r.files.map((f, i) => (
                    <a className="lfile" key={i} href={f.url} target="_blank" rel="noreferrer">
                      <span className="lfile__ico"><FileText size={18} /></span>
                      <div className="lfile__info"><b>{f.name}</b><span>{f.size}</span></div>
                      <span className="lfile__dl"><Download size={14} /> Скачать</span>
                    </a>
                  ))}
                </div>
              )}

              <div className="rescard__answer"><span>{r.type === 'file' ? 'Твоя подпись:' : 'Твой ответ:'}</span> {answerText(r)}</div>
              {r.type === 'file' && <div className="rescard__files">{(r.your_value?.files || []).map((file) => <a href={file.url} target="_blank" rel="noreferrer" key={file.id}>{file.name}</a>)}</div>}
              {r.type === 'audio' && <div className="rescard__audio-answer">{(r.your_value?.files || []).map((file) => <AudioMessage src={file.url} name={file.name || 'Устный ответ'} key={file.id || file.url} />)}</div>}

              {!['textarea', 'file', 'audio'].includes(r.type) && (
                <>
                  <button className="rescard__toggle" onClick={() => toggle(r.id)}>
                    Показать решение <ChevronDown size={15} className={open.has(r.id) ? 'rot' : ''} />
                  </button>
                  {open.has(r.id) && (
                    <div className="rescard__solution">
                      {(r.status === 'wrong' || r.status === 'partial') && <div className="rescard__correct">Правильный ответ: <b>{correctText(r)}</b></div>}
                      <MarkdownContent source={r.solution} empty="Решение не добавлено" />
                    </div>
                  )}
                </>
              )}

              {['textarea', 'file', 'audio'].includes(r.type) && r.status === 'pending' && (
                <div className="rescard__pending"><Clock size={15} /> {r.type === 'audio' ? 'Устный ответ' : 'Развёрнутый ответ'} проверит эксперт — баллы появятся позже</div>
              )}
              {['textarea', 'file', 'audio'].includes(r.type) && r.status === 'graded' && (
                <>
                  <div className="rescard__mentor">
                    <div className="rescard__mentor-head">Комментарий эксперта · {r.earned} из {r.points} б.</div>
                    <MarkdownContent source={r.mentor_comment} empty="—" />
                  </div>
                  {r.solution && <>
                    <button className="rescard__toggle" onClick={() => toggle(r.id)}>
                      Показать пример ответа <ChevronDown size={15} className={open.has(r.id) ? 'rot' : ''} />
                    </button>
                    {open.has(r.id) && <div className="rescard__solution"><MarkdownContent source={r.solution} empty="" /></div>}
                  </>}
                </>
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
              <h3>{placement ? `${placement.code} — «${placement.name}»` : kind === 'mock' ? 'Пробник пройден' : 'Домашнее задание пройдено'}</h3>
              <p>{placement ? placement.text : 'Отличная работа! Можно двигаться дальше.'}</p>
              {allowRetry && <button className="btn btn-light btn-block" onClick={onRetry}><RefreshCw size={16} /> Пройти ещё раз</button>}
            </div>
          )}

          {expert && (
            <div className="result__expert">
              <div className="result__expert-head"><GraduationCap size={16} /> Проверил эксперт</div>
              <div className="result__expert-name">{expert.last_name} {expert.first_name}</div>
              <div className="result__expert-meta">{expert.university}</div>
              <div className="result__expert-score">ЕГЭ {expert.ege_score} {pluralRu(expert.ege_score, 'балл', 'балла', 'баллов')}</div>
            </div>
          )}
        </aside>
      </div>
    </div>
  )
}
