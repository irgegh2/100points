import { useEffect, useState } from 'react'
import { ChevronDown, ChevronRight, Minus, Plus, Printer, Check, X, ListChecks } from 'lucide-react'
import { bankMeta, bankSession, bankSolved } from '../api.js'
import { printQuestions } from '../printQuestions.js'
import BankTrainer from './BankTrainer.jsx'

const COUNTS = [5, 10, 20, 30, 40, 50]

export default function Bank() {
  const [subjects, setSubjects] = useState([])
  const [subjectId, setSubjectId] = useState(null)
  const [data, setData] = useState(null)        // { topics, sources }
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [mode, setMode] = useState('topic')
  const [topicId, setTopicId] = useState('')
  const [count, setCount] = useState(10)
  const [custom, setCustom] = useState({})       // { subtopic_id: n }
  const [expanded, setExpanded] = useState(() => new Set())
  const [sourceId, setSourceId] = useState(null)
  const [variantId, setVariantId] = useState('')
  const [sort, setSort] = useState({ key: 'number', dir: 1 })
  const [onlyUnsolved, setOnlyUnsolved] = useState(false)
  const [solved, setSolved] = useState(null)       // список решённых для модалки

  const [session, setSession] = useState(null)   // { questions, title }
  const [starting, setStarting] = useState(false)

  useEffect(() => {
    bankMeta().then((d) => {
      setSubjects(d.subjects || [])
      if (d.subjects?.length) setSubjectId(d.subjects[0].id)
      else setLoading(false)
    }).catch((e) => { setError(e.message); setLoading(false) })
  }, [])

  useEffect(() => {
    if (!subjectId) return
    setLoading(true)
    bankMeta(subjectId).then((d) => {
      setData({ topics: d.topics || [], sources: d.sources || [] })
      setTopicId(d.topics?.[0]?.id || '')
      setSourceId(d.sources?.[0]?.id || null)
      setVariantId(d.sources?.[0]?.variants?.[0]?.id || '')
      setCustom({}); setLoading(false)
    }).catch((e) => { setError(e.message); setLoading(false) })
  }, [subjectId])

  if (session) return <BankTrainer questions={session.questions} title={session.title}
    onExit={() => { setSession(null); bankMeta(subjectId).then((d) => setData({ topics: d.topics || [], sources: d.sources || [] })) }}
    onPrint={() => printQuestions(session.title, session.questions)} />

  if (loading && !data) return <div className="hw"><div className="hw__empty">Загружаем банк…</div></div>
  if (error) return <div className="hw"><div className="hw__empty">Ошибка: {error}</div></div>
  if (subjects.length === 0) return <div className="hw"><div className="hw__empty">Банк появится, когда у тебя будет доступ хотя бы к одному курсу.</div></div>

  const topics = data?.topics || []
  const sources = data?.sources || []
  const variants = sources.find((s) => s.id === sourceId)?.variants || []

  const customTotal = Object.values(custom).reduce((s, n) => s + (n || 0), 0)
  const setCnt = (stId, n, max) => setCustom((c) => ({ ...c, [stId]: Math.max(0, Math.min(max, n)) }))
  const toggleTopic = (id) => setExpanded((s) => { const x = new Set(s); x.has(id) ? x.delete(id) : x.add(id); return x })

  const buildPayload = () => {
    if (mode === 'topic') return { mode: 'topic', subject_id: subjectId, topic_id: Number(topicId), count: Number(count), only_unsolved: onlyUnsolved }
    if (mode === 'custom') return { mode: 'custom', subject_id: subjectId, counts: custom, only_unsolved: onlyUnsolved }
    return { mode: 'variant', subject_id: subjectId, variant_id: Number(variantId) }
  }
  const sessionTitle = () => {
    if (mode === 'topic') { const t = topics.find((x) => x.id === Number(topicId)); return t ? `№${t.number} ${t.name}` : 'Тренировка' }
    if (mode === 'variant') { const v = variants.find((x) => x.id === Number(variantId)); return v ? v.name : 'Пробник' }
    return 'Своя тренировка'
  }
  const start = async (payloadOverride) => {
    setStarting(true)
    try {
      const d = await bankSession(payloadOverride || buildPayload())
      if (!d.questions.length) { alert('По выбранным параметрам нет вопросов'); return }
      setSession({ questions: d.questions, title: payloadOverride ? null : sessionTitle() })
    } catch (e) { alert(e.message) } finally { setStarting(false) }
  }
  const printOnly = async () => {
    try { const d = await bankSession(buildPayload()); if (d.questions.length) printQuestions(sessionTitle(), d.questions); else alert('Нет вопросов') }
    catch (e) { alert(e.message) }
  }

  const sorted = [...topics].sort((a, b) => {
    const va = sort.key === 'number' ? (a.number ?? 0) : (a.error_rate ?? -1)
    const vb = sort.key === 'number' ? (b.number ?? 0) : (b.error_rate ?? -1)
    return (va - vb) * sort.dir
  })
  const setSortKey = (k) => setSort((s) => s.key === k ? { key: k, dir: -s.dir } : { key: k, dir: 1 })
  const errColor = (e) => e == null ? '#9aa1ab' : e >= 25 ? '#e0584f' : e >= 15 ? '#e08a1f' : '#41c45e'

  return (
    <div className="bank">
      <div className="bank__subject">
        <label>Предмет
          <select value={subjectId || ''} onChange={(e) => setSubjectId(Number(e.target.value))}>
            {subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
        </label>
      </div>

      <section className="bank__errors">
        <div className="admin__panel-head">
          <h2>Частые ошибки выпускников ЕГЭ</h2>
          <button className="btn btn-light btn-sm" onClick={() => bankSolved(subjectId).then((d) => setSolved(d.items || []))}><ListChecks size={15} /> Решённые задачи</button>
        </div>
        <p className="bank__sub">Нажми «Тренировать», чтобы прокачать слабое место. Зелёным — сколько уже решено.</p>
        <div className="bank__table">
          <div className="bank__thead">
            <button className="bank__th" onClick={() => setSortKey('number')}>Задание {sort.key === 'number' && (sort.dir > 0 ? '↑' : '↓')}</button>
            <div className="bank__th bank__th--name">Тема / тип</div>
            <button className="bank__th bank__th--num" onClick={() => setSortKey('error_rate')}>Ошибки % {sort.key === 'error_rate' && (sort.dir > 0 ? '↑' : '↓')}</button>
            <div className="bank__th bank__th--act">Что делать?</div>
          </div>
          {sorted.length === 0 && <div className="hw__empty">По этому предмету банк пока пуст.</div>}
          {sorted.map((t) => (
            <div className="bank__row" key={t.id}>
              <div className="bank__td bank__num">№{t.number ?? '—'}</div>
              <div className="bank__td bank__td--name">
                {t.name}
                {t.count > 0 && <span className={`bank__solved ${t.solved >= t.count ? 'done' : ''}`}>решено {t.solved}/{t.count}</span>}
              </div>
              <div className="bank__td bank__td--num" style={{ color: errColor(t.error_rate), fontWeight: 700 }}>{t.error_rate != null ? `${t.error_rate}.0%` : '—'}</div>
              <div className="bank__td bank__td--act">
                <button className="bank__train" disabled={t.count === 0} onClick={() => start({ mode: 'topic', subject_id: subjectId, topic_id: t.id, count: 10, only_unsolved: onlyUnsolved })}>Тренировать</button>
              </div>
            </div>
          ))}
        </div>
      </section>

      <section className="bank__builder">
        <h2>Конструктор тренировки</h2>
        <div className="bank__modes">
          {[['topic', 'По заданиям'], ['custom', 'Своя тренировка'], ['variant', 'Пробник']].map(([m, l]) => (
            <label key={m} className={`bank__mode ${mode === m ? 'active' : ''}`}>
              <input type="radio" name="bankmode" checked={mode === m} onChange={() => setMode(m)} /> {l}
            </label>
          ))}
        </div>

        {mode === 'topic' && (
          <div className="bank__form">
            <label>Номер задания
              <select value={topicId} onChange={(e) => setTopicId(e.target.value)}>
                {topics.map((t) => <option key={t.id} value={t.id}>№{t.number} {t.name} ({t.count})</option>)}
              </select>
            </label>
            <label>Количество задач
              <input type="number" min={1} max={100} value={count} onChange={(e) => setCount(+e.target.value)} />
            </label>
            <div className="bank__counts">
              {COUNTS.map((c) => <button key={c} className={count === c ? 'active' : ''} onClick={() => setCount(c)}>{c}</button>)}
            </div>
          </div>
        )}

        {mode === 'custom' && (
          <div className="bank__custom">
            {topics.map((t) => {
              const tSum = (t.subtopics || []).reduce((s, st) => s + (custom[st.id] || 0), 0)
              return (
                <div className="bank__ctopic" key={t.id}>
                  <button className="bank__ctopic-head" onClick={() => toggleTopic(t.id)}>
                    {expanded.has(t.id) ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
                    <b>№ {t.number} {t.name}</b> <span className="bank__ctopic-cnt">{t.count}шт</span>
                    <span className="bank__ctopic-sum">{tSum}</span>
                  </button>
                  {expanded.has(t.id) && (
                    <div className="bank__csubs">
                      <div className="bank__csub-actions">
                        <button onClick={() => (t.subtopics || []).forEach((st) => setCnt(st.id, st.count, st.count))}>Добавить все</button>
                        <button onClick={() => (t.subtopics || []).forEach((st) => setCnt(st.id, 0, st.count))}>Сбросить все</button>
                      </div>
                      {(t.subtopics || []).map((st) => (
                        <div className="bank__csub" key={st.id}>
                          <span>{st.name} <small>{st.count} шт{st.solved > 0 ? ` · решено ${st.solved}` : ''}</small></span>
                          <div className="bank__stepper">
                            <button onClick={() => setCnt(st.id, (custom[st.id] || 0) - 1, st.count)} disabled={st.count === 0}><Minus size={14} /></button>
                            <b>{custom[st.id] || 0}</b>
                            <button onClick={() => setCnt(st.id, (custom[st.id] || 0) + 1, st.count)} disabled={st.count === 0}><Plus size={14} /></button>
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              )
            })}
            <p className="bank__sub">Выбрано задач: <b>{customTotal}</b></p>
          </div>
        )}

        {mode === 'variant' && (
          <div className="bank__form">
            {sources.length === 0 && <p className="hw__empty">Источников пока нет.</p>}
            {sources.length > 0 && <>
              <div className="bank__sources">
                {sources.map((s) => (
                  <button key={s.id} className={`bank__srctag ${sourceId === s.id ? 'active' : ''}`} onClick={() => { setSourceId(s.id); setVariantId(s.variants?.[0]?.id || '') }}>{s.name}</button>
                ))}
              </div>
              <label>Вариант
                <select value={variantId} onChange={(e) => setVariantId(e.target.value)}>
                  {variants.length === 0 && <option value="">— нет вариантов —</option>}
                  {variants.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
                </select>
              </label>
            </>}
          </div>
        )}

        {mode !== 'variant' && (
          <label className="bank__unsolved">
            <input type="checkbox" checked={onlyUnsolved} onChange={(e) => setOnlyUnsolved(e.target.checked)} />
            Решать только ещё не решённые задачи
          </label>
        )}

        <div className="bank__actions">
          <button className="btn btn-light" onClick={printOnly}><Printer size={15} /> Версия для печати</button>
          <button className="btn btn-blue btn-lg" disabled={starting || (mode === 'custom' && customTotal === 0)} onClick={() => start()}>
            {starting ? 'Собираем…' : 'Начать тренировку'}
          </button>
        </div>
      </section>

      {/* Решённые задачи */}
      {solved && (
        <div className="admin__modal" onClick={() => setSolved(null)}>
          <div className="admin__modal-box admin__modal-box--wide" onClick={(e) => e.stopPropagation()}>
            <div className="admin__modal-head">
              <h3>Решённые задачи <span className="admin__count">{solved.length}</span></h3>
              <button className="admin__iconbtn" onClick={() => setSolved(null)}><X size={18} /></button>
            </div>
            {solved.length === 0 && <div className="hw__empty">Ты ещё не решал задачи по этому предмету.</div>}
            {solved.map((q, i) => (
              <div className="rescard" key={q.id} style={{ marginBottom: 10 }}>
                <div className="rescard__top">
                  <span className={`rescard__badge ${q.is_correct ? 'correct' : 'wrong'}`}>
                    {q.is_correct ? <><Check size={13} /> Верно</> : <><X size={13} /> Неверно</>}
                  </span>
                  <div className="rescard__q">№{q.topic.number} {q.topic.name} · {q.subtopic}</div>
                </div>
                <div className="rescard__answer" style={{ background: 'transparent', padding: 0 }}>{q.text}</div>
                <div className="rescard__solution">
                  <div className="rescard__correct">Ответ: <b>{q.type === 'single' ? q.options[q.correct] : q.type === 'multi' ? (q.correct || []).map((j) => q.options[j]).join(', ') : (q.correct || []).join(' / ')}</b></div>
                  {q.solution && <p>{q.solution}</p>}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
