import { useEffect, useState, useCallback } from 'react'
import { Dumbbell, Check, X, Play, Sparkles, ChevronRight, Target, MessageSquare, SkipForward } from 'lucide-react'
import { trainerMeta, trainerTaskNext, trainerTaskAnswer, trainerQaGenerate, trainerQaCheck, aiChatCreate } from '../api.js'
import AiChatBox from './AiChatBox.jsx'
import MarkdownContent from './MarkdownContent.jsx'
import { useLiveRefresh } from '../liveUpdates.js'

const LEVELS = [
  { n: 1, name: 'База', band: 'на «3» · 40–55 баллов', color: '#34c759' },
  { n: 2, name: 'Уверенный', band: 'на «4» · 56–70 баллов', color: '#3f9bff' },
  { n: 3, name: 'Продвинутый', band: 'на «4–5» · 71–80 баллов', color: '#5b4be0' },
  { n: 4, name: 'Высокий', band: 'на «5» · 81–90 баллов', color: '#ff7a00' },
  { n: 5, name: 'Эксперт', band: '90–100 баллов', color: '#e0518a' },
]
const MODES = [{ k: 'tasks', name: 'Решение задач' }, { k: 'qa', name: 'Вопрос-ответ' }]

// Старый дизайн тренажёра + живой бэкенд: реальные задачи/ИИ-генерация, ИИ-разбор, «разобрать с ИИ».
export default function Trainer() {
  const [subjects, setSubjects] = useState([])
  const [subjectId, setSubjectId] = useState(null)
  const [levelsInfo, setLevelsInfo] = useState([])
  const [qaTopics, setQaTopics] = useState(0)
  const [mode, setMode] = useState('tasks')
  const [level, setLevel] = useState(2)

  const [phase, setPhase] = useState('setup')   // setup | run
  const [task, setTask] = useState(null)
  const [qa, setQa] = useState(null)
  const [answer, setAnswer] = useState('')
  const [choice, setChoice] = useState(null)
  const [result, setResult] = useState(null)
  const [busy, setBusy] = useState(false)
  const [generating, setGenerating] = useState(false)
  const [generationSeconds, setGenerationSeconds] = useState(0)
  const [stats, setStats] = useState({ solved: 0, correct: 0 })
  const [chat, setChat] = useState(null)

  const refreshMeta = useCallback(() => {
    if (!subjectId) return
    trainerMeta(subjectId).then((d) => { setLevelsInfo(d.levels || []); setQaTopics(d.qa_topics || 0) })
  }, [subjectId])
  useEffect(() => {
    trainerMeta().then((d) => { setSubjects(d.subjects || []); if (d.subjects?.length) setSubjectId(d.subjects[0].id) })
  }, [])
  useEffect(() => { refreshMeta() }, [refreshMeta])
  useEffect(() => { if (phase === 'setup') refreshMeta() }, [phase, refreshMeta])
  useLiveRefresh(() => {
    if (phase !== 'setup') return
    trainerMeta().then((d) => {
      setSubjects(d.subjects || [])
      if (!subjectId && d.subjects?.length) setSubjectId(d.subjects[0].id)
    }).catch(() => {})
    refreshMeta()
  })
  useEffect(() => {
    if (!generating) { setGenerationSeconds(0); return undefined }
    const startedAt = Date.now()
    const timer = window.setInterval(() => setGenerationSeconds(Math.floor((Date.now() - startedAt) / 1000)), 1000)
    return () => window.clearInterval(timer)
  }, [generating])

  const subjectName = subjects.find((s) => s.id === subjectId)?.name || ''
  const lvl = LEVELS.find((l) => l.n === level)
  const accuracy = stats.solved ? Math.round((stats.correct / stats.solved) * 100) : 0
  const unsolved = (n) => levelsInfo.find((x) => x.level === n)?.unsolved ?? 0

  // ── загрузка следующего ──
  const next = async () => {
    setBusy(true); setResult(null); setAnswer(''); setChoice(null)
    try {
      if (mode === 'qa') {
        setGenerating(true)
        const d = await trainerQaGenerate(subjectId, level)
        setQa(d); setTask(null); setPhase('run')
      } else {
        const d = await trainerTaskNext(subjectId, level)
        if (!d.task) { setTask(null); setPhase('run') } else { setTask(d.task); setQa(null); setPhase('run') }
      }
    } catch (e) { alert(e.message) } finally { setBusy(false); setGenerating(false) }
  }
  const start = () => { setStats({ solved: 0, correct: 0 }); next() }

  const checkTask = async () => {
    setBusy(true)
    try {
      const r = await trainerTaskAnswer(task.id, answer)
      setResult(r); setStats((s) => ({ solved: s.solved + 1, correct: s.correct + (r.verdict === 'correct' ? 1 : 0) }))
    } catch (e) { alert(e.message) } finally { setBusy(false) }
  }
  const checkQa = async () => {
    setBusy(true)
    try {
      const r = await trainerQaCheck(qa.gen_id, choice)
      setResult(r); setStats((s) => ({ solved: s.solved + 1, correct: s.correct + (r.verdict === 'correct' ? 1 : 0) }))
    } catch (e) { alert(e.message) } finally { setBusy(false) }
  }
  const discuss = async () => {
    setBusy(true)
    try { const d = await aiChatCreate({ kind: 'task', task_id: task.id }); setChat({ chatId: d.chat.id, messages: d.messages }) }
    catch (e) { alert(e.message) } finally { setBusy(false) }
  }

  // ── Разбор с ИИ ──
  if (chat) return (
    <div className="trainer-run">
      <div className="trun__bar">
        <button className="trun__exit" onClick={() => setChat(null)}>← К задаче</button>
        <div className="trun__meta"><span className="trun__tag light"><Sparkles size={13} /> Разбор с ИИ</span></div>
      </div>
      <div className="trun__card" style={{ marginBottom: 12 }}><div className="test__question">{task.text}</div></div>
      <AiChatBox chatId={chat.chatId} initialMessages={chat.messages} />
    </div>
  )

  // ── НАСТРОЙКА (старый дизайн) ──
  if (phase === 'setup') {
    if (subjects.length === 0) return <div className="trainer-setup"><div className="hw__empty">ИИ-тренажёр появится, когда у тебя будет доступ к курсу.</div></div>
    const canStart = mode === 'qa' ? qaTopics > 0 : unsolved(level) > 0
    return (
      <div className="trainer-setup">
        <div className="tset__hero"><span className="tset__hero-ico"><Dumbbell size={26} /></span>
          <div><h2>ИИ-тренажёр</h2><p>Бесконечная практика с проверкой от настоящего ИИ — он объясняет ошибки и разбирает задачи в чате.</p></div>
        </div>

        <div className="tset__block">
          <h3>Режим</h3>
          <div className="tset__chips">
            {MODES.map((m) => <button key={m.k} className={m.k === mode ? 'active' : ''} onClick={() => setMode(m.k)}>{m.name}</button>)}
          </div>
          <p className="tset__hint">{mode === 'qa' ? 'ИИ сам генерирует новые вопросы по темам — бесконечно.' : 'Задачи от преподавателей. ИИ сверяет твой ответ с эталоном и объясняет, где ошибся.'}</p>
        </div>

        <div className="tset__block">
          <h3>Уровень сложности</h3>
          <div className="tset__levels">
            {LEVELS.map((l) => (
              <button key={l.n} className={`tset__level ${level === l.n ? 'active' : ''}`} onClick={() => setLevel(l.n)} style={level === l.n ? { '--accent': l.color } : undefined}>
                <span className="tset__level-n" style={{ background: l.color }}>{l.n}</span>
                <span className="tset__level-name">{l.name}</span>
                <span className="tset__level-band">{mode === 'tasks' ? `${unsolved(l.n)} непройденных` : l.band}</span>
              </button>
            ))}
          </div>
        </div>

        <div className="tset__block">
          <h3>Предмет</h3>
          <div className="tset__chips">
            {subjects.map((s) => <button key={s.id} className={s.id === subjectId ? 'active' : ''} onClick={() => setSubjectId(s.id)}>{s.name}</button>)}
          </div>
        </div>

        <button className="btn btn-orange btn-lg tset__start" onClick={start} disabled={busy || !canStart}>
          <Play size={18} fill="#fff" /> {generating ? `ИИ составляет и проверяет · ${generationSeconds} с` : busy ? 'Загружаем…' : mode === 'qa' ? 'Начать (ИИ сгенерирует вопрос)' : 'Начать тренировку'}
        </button>
        {generating && <p className="tset__hint" style={{ textAlign: 'center' }}>Запрос работает: сначала создаём вопрос, затем независимо проверяем ответ. Страницу можно не обновлять.</p>}
        {!canStart && <p className="tset__hint" style={{ textAlign: 'center' }}>{mode === 'qa' ? 'По этому предмету нет тем для генерации — админ добавит их.' : 'На этом уровне нет непройденных задач — выбери другой уровень.'}</p>}
      </div>
    )
  }

  // ── ТРЕНИРОВКА (старый дизайн) ──
  const noMore = mode === 'tasks' && !task
  return (
    <div className="trainer-run">
      <div className="trun__bar">
        <button className="trun__exit" onClick={() => setPhase('setup')}>← Настройки</button>
        <div className="trun__meta">
          <span className="trun__tag" style={{ background: lvl.color }}>Ур. {lvl.n} · {lvl.name}</span>
          <span className="trun__tag light">{subjectName}</span>
          <span className="trun__tag light">{mode === 'qa' ? 'Вопрос-ответ' : 'Решение задач'}</span>
        </div>
      </div>

      <div className="trun__grid">
        <div className="trun__card">
          {noMore ? (
            <div className="hw__empty">🎉 Все задачи этого уровня пройдены! Выбери другой уровень или режим.</div>
          ) : mode === 'qa' && qa ? (
            <>
              <div className="trun__num"><Sparkles size={13} /> ИИ · {qa.topic} · задание {stats.solved + 1}</div>
              <div className="test__question"><MarkdownContent source={qa.question.text} empty="" /></div>
              <div className="test__opts">
                {qa.question.options.map((o, oi) => {
                  const cls = result ? (oi === result.correct ? 'selected correct' : (oi === choice ? 'selected wrong' : '')) : (choice === oi ? 'selected' : '')
                  return <button key={oi} className={`test__opt ${cls}`} disabled={!!result} onClick={() => setChoice(oi)}>
                    <span className="test__check test__check--round">{choice === oi && <span className="test__dot" />}</span><span>{o}</span></button>
                })}
              </div>
              {result && (
                <div className={`trun__fb ${result.verdict === 'correct' ? 'ok' : 'no'}`}>
                  <div className="trun__fb-head">{result.verdict === 'correct' ? <><Check size={15} /> Верно!</> : <><X size={15} /> Неверно</>}</div>
                  <p><b>Правильный ответ:</b> {result.correct_text}<br />{result.explanation}</p>
                </div>
              )}
              {!result
                ? <button className="btn btn-blue test__next" disabled={choice === null || busy} onClick={checkQa}>{busy ? 'Проверяем…' : 'Проверить'}</button>
                : <button className="btn btn-orange test__next" onClick={next} disabled={busy}>{generating ? `Создаём и проверяем · ${generationSeconds} с` : <>Следующий вопрос <ChevronRight size={16} /></>}</button>}
            </>
          ) : task ? (
            <>
              <div className="trun__num">Задание {stats.solved + 1} · уровень {task.level}</div>
              <div className="test__question">{task.text}</div>
              {!result ? (
                <>
                  <textarea className="test__textarea" placeholder="Твой ответ…" rows={4} value={answer} onChange={(e) => setAnswer(e.target.value)} />
                  <div className="trun__actions">
                    <button className="btn btn-blue test__next" disabled={!answer.trim() || busy} onClick={checkTask}>{busy ? 'Проверяем…' : 'Проверить'}</button>
                    <button className="btn btn-light" onClick={next} disabled={busy}><SkipForward size={15} /> Пропустить</button>
                  </div>
                </>
              ) : (
                <>
                  <div className={`trun__fb ${result.verdict === 'correct' ? 'ok' : 'no'}`}>
                    <div className="trun__fb-head"><Sparkles size={15} /> {result.verdict === 'correct' ? 'Верно!' : result.verdict === 'partial' ? 'Почти — частично' : 'Неверно'}</div>
                    <p>{result.explanation}</p>
                    <p style={{ marginTop: 6 }}><b>Правильный ответ:</b> {result.correct_answer}</p>
                  </div>
                  <div className="trun__actions">
                    <button className="btn btn-orange test__next" onClick={next} disabled={busy}>Следующая задача <ChevronRight size={16} /></button>
                    <button className="btn btn-purple" onClick={discuss} disabled={busy}><MessageSquare size={15} /> Разобрать с ИИ</button>
                  </div>
                </>
              )}
            </>
          ) : <div className="hw__empty">Загрузка…</div>}
        </div>

        <aside className="trun__side">
          <div className="trun__statcard">
            <div className="trun__stat"><b>{stats.solved}</b><span>решено</span></div>
            <div className="trun__stat"><b>{stats.correct}</b><span>верно</span></div>
            <div className="trun__stat"><b>{accuracy}%</b><span>точность</span></div>
          </div>
          <div className="trun__tip"><Target size={16} /> {mode === 'qa' ? 'ИИ придумывает новый вопрос каждый раз — практика бесконечна.' : 'Пройденные задачи не повторяются. Решай серию — повышай готовность.'}</div>
        </aside>
      </div>
    </div>
  )
}
