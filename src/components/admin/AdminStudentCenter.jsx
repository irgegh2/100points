import { useEffect, useMemo, useState } from 'react'
import {
  Activity, AlertTriangle, ArrowLeft, BarChart3, BookOpen, CalendarDays, Check,
  ChevronRight, Clock, Eye, FileText, GraduationCap, Mail, MessageSquare, PencilLine,
  RefreshCw, Save, Search, Sparkles, Target, Trophy, X,
} from 'lucide-react'
import { adminImpersonateStart, adminStudentAnalytics, adminStudentReportSave, reviewGet } from '../../api.js'
import MarkdownContent from '../MarkdownContent.jsx'
import AudioMessage from '../AudioMessage.jsx'
import { pluralRu } from '../../ruPlural.js'
import { canonicalTextAnswer } from '../../answerDisplay.js'

const TABS = [
  ['analytics', 'Аналитик', BarChart3],
  ['works', 'Работы и ответы', FileText],
  ['courses', 'Курсы', BookOpen],
  ['reports', 'Еженедельные отчёты', Mail],
]
const WEEKDAYS = ['', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье']
const LEVEL = { normal: 'База', medium: 'Средний', hard: 'Продвинутый' }

const fmtDate = (value) => {
  if (!value) return '—'
  const raw = String(value).slice(0, 10)
  const [y, m, d] = raw.split('-')
  return y && m && d ? `${d}.${m}.${y}` : value
}
const pct = (value) => value == null ? '—' : `${value}%`
const workKind = (kind) => kind === 'mock' ? 'Пробник' : 'Домашняя работа'

function answerValue(answer) {
  const value = answer.your_value
  if (answer.type === 'single') return value != null ? answer.options?.[value] ?? String(value) : 'Нет ответа'
  if (answer.type === 'multi') return Array.isArray(value) ? value.map((i) => answer.options?.[i] ?? i).join(', ') : 'Нет ответа'
  if (answer.type === 'file') return value?.note || 'Нет подписи'
  if (answer.type === 'audio') return value?.files?.length ? 'Аудиоответ ученика' : 'Нет аудиоответа'
  if (typeof value === 'string') return value || 'Нет ответа'
  return value == null ? 'Нет ответа' : JSON.stringify(value)
}
function correctValue(answer) {
  if (answer.type === 'single') {
    const correct = Array.isArray(answer.correct) ? answer.correct : [answer.correct]
    return correct.map((i) => answer.options?.[i] ?? i).join(' / ')
  }
  if (answer.type === 'multi') return (answer.correct || []).map((i) => answer.options?.[i] ?? i).join(', ')
  if (answer.type === 'text') return canonicalTextAnswer(answer.correct)
  return ''
}

function ActivityChart({ data }) {
  const points = useMemo(() => {
    if (!data?.length) return []
    const target = 90
    const stride = Math.max(1, Math.ceil(data.length / target))
    const result = []
    for (let i = 0; i < data.length; i += stride) {
      const chunk = data.slice(i, i + stride)
      result.push({ date: chunk[chunk.length - 1].date, total: chunk.reduce((sum, item) => sum + item.total, 0) })
    }
    return result
  }, [data])
  if (!points.length || !points.some((point) => point.total > 0)) return <div className="scenter__chart-empty">В выбранном периоде активности пока нет</div>
  const width = 760, height = 180, pad = 20
  const max = Math.max(1, ...points.map((point) => point.total))
  const xy = points.map((point, index) => ({
    ...point,
    x: pad + index * ((width - pad * 2) / Math.max(1, points.length - 1)),
    y: height - pad - point.total / max * (height - pad * 2),
  }))
  const path = xy.map((point, index) => `${index ? 'L' : 'M'}${point.x.toFixed(1)},${point.y.toFixed(1)}`).join(' ')
  const area = `${path} L${xy.at(-1).x},${height - pad} L${xy[0].x},${height - pad} Z`
  return (
    <div className="scenter__chart-scroll">
      <svg className="scenter__linechart" viewBox={`0 0 ${width} ${height}`} role="img" aria-label="График учебной активности">
        {[0, .25, .5, .75, 1].map((part) => <line key={part} x1={pad} x2={width - pad} y1={pad + part * (height - pad * 2)} y2={pad + part * (height - pad * 2)} />)}
        <path className="scenter__area" d={area} /><path className="scenter__line" d={path} />
        {xy.map((point, index) => point.total > 0 && (points.length < 60 || index % 3 === 0) ? (
          <circle key={`${point.date}-${index}`} cx={point.x} cy={point.y} r="3.5"><title>{fmtDate(point.date)} · {point.total} действий</title></circle>
        ) : null)}
      </svg>
    </div>
  )
}

function ScoreChart({ data, onOpen }) {
  if (!data?.length) return <div className="scenter__chart-empty">Нет оценённых работ за выбранный период</div>
  const width = 760, height = 210, left = 36, right = 18, top = 16, bottom = 28
  const points = data.map((item, index) => ({
    ...item,
    x: left + index * ((width - left - right) / Math.max(1, data.length - 1)),
    y: top + (100 - item.percent) / 100 * (height - top - bottom),
  }))
  const path = points.map((point, index) => `${index ? 'L' : 'M'}${point.x.toFixed(1)},${point.y.toFixed(1)}`).join(' ')
  return (
    <div className="scenter__chart-scroll">
      <svg className="scenter__scorechart" viewBox={`0 0 ${width} ${height}`} role="img" aria-label="Динамика результатов">
        {[0, 25, 50, 75, 100].map((value) => {
          const y = top + (100 - value) / 100 * (height - top - bottom)
          return <g key={value}><line x1={left} x2={width - right} y1={y} y2={y} /><text x="2" y={y + 4}>{value}%</text></g>
        })}
        {points.length > 1 && <path d={path} />}
        {points.map((point) => (
          <circle className="is-clickable" key={point.submission_id} cx={point.x} cy={point.y} r="5" onClick={() => onOpen(point.submission_id)}>
            <title>{point.title} · {point.percent}% · {fmtDate(point.date)} — открыть ответы</title>
          </circle>
        ))}
      </svg>
    </div>
  )
}

function ProgressRing({ value, label }) {
  const safe = Math.max(0, Math.min(100, Number(value) || 0))
  return <div className="scenter__ring" style={{ '--progress': `${safe * 3.6}deg` }}><div><b>{safe}%</b><span>{label}</span></div></div>
}

export default function AdminStudentCenter({ userId, onBack, onEdit }) {
  const [tab, setTab] = useState('analytics')
  const [days, setDays] = useState('90')
  const [courseId, setCourseId] = useState(0)
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [workSearch, setWorkSearch] = useState('')
  const [workKindFilter, setWorkKindFilter] = useState('all')
  const [submission, setSubmission] = useState(null)
  const [submissionBusy, setSubmissionBusy] = useState(false)
  const [report, setReport] = useState(null)
  const [reportBusy, setReportBusy] = useState(false)
  const [reportMsg, setReportMsg] = useState(null)

  const load = () => {
    setLoading(true); setError('')
    adminStudentAnalytics(userId, { days, course_id: courseId })
      .then((result) => { setData(result); setReport(result.report_setting); setLoading(false) })
      .catch((err) => { setError(err.message); setLoading(false) })
  }
  useEffect(load, [userId, days, courseId])

  const openSubmission = async (id) => {
    setSubmissionBusy(true)
    try { setSubmission(await reviewGet(id)) } catch (err) { alert(err.message) }
    finally { setSubmissionBusy(false) }
  }
  const previewAsStudent = async () => {
    try {
      const result = await adminImpersonateStart(userId)
      window.open(result.url, '_blank', 'noopener,noreferrer')
    } catch (err) { alert(err.message) }
  }
  const saveReport = async () => {
    setReportBusy(true); setReportMsg(null)
    try {
      const recipients = String(report.recipientsText ?? report.recipients?.join('\n') ?? '').split(/[\n,;]+/).map((value) => value.trim()).filter(Boolean)
      const result = await adminStudentReportSave({ user_id: userId, ...report, recipients })
      setReport({ ...result.setting, recipientsText: result.setting.recipients.join('\n') })
      setReportMsg({ type: 'ok', text: result.setting.enabled ? 'Еженедельный отчёт включён' : 'Настройка сохранена, отправка выключена' })
      load()
    } catch (err) { setReportMsg({ type: 'err', text: err.message }) }
    finally { setReportBusy(false) }
  }

  const filteredWorks = useMemo(() => {
    const query = workSearch.trim().toLowerCase()
    return (data?.submissions || []).filter((work) => {
      if (workKindFilter !== 'all' && work.kind !== workKindFilter) return false
      return !query || `${work.title} ${work.course} ${work.subject} ${work.lesson}`.toLowerCase().includes(query)
    })
  }, [data, workSearch, workKindFilter])

  if (loading && !data) return <div className="scenter__loading"><RefreshCw className="spin" size={24} /> Собираю аналитику ученика…</div>
  if (error && !data) return <div className="admin__msg admin__msg--err">{error}</div>
  if (!data) return null
  const student = data.student, summary = data.summary
  const lessonProgress = summary.lessons_total ? Math.round(summary.lessons_done * 100 / summary.lessons_total) : 0
  const homeworkProgress = summary.homeworks_total ? Math.round(summary.homeworks_done * 100 / summary.homeworks_total) : 0
  const bestSubject = [...data.subjects].sort((a, b) => b.average - a.average)[0]
  const weakSubject = [...data.subjects].sort((a, b) => a.average - b.average)[0]

  return (
    <div className="scenter">
      <div className="scenter__hero">
        <button className="scenter__back" onClick={onBack}><ArrowLeft size={17} /> Все ученики</button>
        <div className="scenter__identity">
          <span className="scenter__avatar" style={student.avatar ? { backgroundImage: `url(${student.avatar})` } : undefined}>{!student.avatar && (student.name || '?').slice(0, 1)}</span>
          <div><span className="scenter__eyebrow">Личная траектория · ученик #{student.id}</span><h2>{student.name || 'Без имени'}</h2><p>{student.email || 'Почта не указана'} · {student.phone || 'Телефон не указан'}</p></div>
        </div>
        <div className="scenter__hero-actions">
          <button className="btn btn-light" onClick={onEdit}><PencilLine size={15} /> Аккаунт и доступы</button>
          <button className="btn btn-orange" onClick={previewAsStudent}><Eye size={16} /> Смотреть как ученик</button>
        </div>
      </div>

      <div className="scenter__controls">
        <div className="scenter__tabs" role="tablist">
          {TABS.map(([key, label, Icon]) => <button key={key} className={tab === key ? 'active' : ''} onClick={() => setTab(key)}><Icon size={16} /> {label}</button>)}
        </div>
        <div className="scenter__filters">
          <select value={courseId} onChange={(event) => setCourseId(Number(event.target.value))}><option value="0">Все курсы</option>{data.courses.map((course) => <option value={course.id} key={course.id}>{course.title}</option>)}</select>
          <select value={days} onChange={(event) => setDays(event.target.value)}><option value="30">30 дней</option><option value="90">90 дней</option><option value="365">Год</option><option value="all">Всё время</option></select>
        </div>
      </div>

      {tab === 'analytics' && (
        <div className="scenter__content">
          <section className="scenter__pulse">
            <div className="scenter__pulse-main"><span>Учебный пульс</span><b>{summary.active_days}</b><small>{pluralRu(summary.active_days, 'активный день', 'активных дня', 'активных дней')} за период</small></div>
            <div className="scenter__pulse-stat"><span>Средний результат</span><b>{pct(summary.average_score)}</b><small>лучший — {pct(summary.best_score)}</small></div>
            <div className="scenter__pulse-stat"><span>Работы</span><b>{summary.submissions}</b><small>{summary.pending_review ? `${summary.pending_review} на проверке` : 'все проверены'}</small></div>
            <div className={`scenter__pulse-stat ${summary.overdue ? 'is-alert' : ''}`}><span>Просрочено</span><b>{summary.overdue}</b><small>{summary.overdue ? 'нужно внимание' : 'долгов нет'}</small></div>
            <div className="scenter__pulse-stat"><span>Серия</span><b>{summary.streak}</b><small>рекорд — {summary.streak_best} дн.</small></div>
          </section>

          <div className="scenter__grid scenter__grid--wide">
            <section className="scenter__panel scenter__panel--span2"><div className="scenter__panel-head"><div><span className="scenter__kicker">Ритм</span><h3>Активность по дням</h3></div><Activity size={20} /></div><ActivityChart data={data.activity} /><div className="scenter__legend"><span className="is-orange" /> входы, уроки, работы и тренажёр</div></section>
            <section className="scenter__panel scenter__progress-panel"><div className="scenter__panel-head"><div><span className="scenter__kicker">Программа</span><h3>Общий прогресс</h3></div></div><div className="scenter__rings"><ProgressRing value={lessonProgress} label="уроки" /><ProgressRing value={homeworkProgress} label="домашки" /></div><p>{summary.lessons_done} из {summary.lessons_total} уроков · {summary.homeworks_done} из {summary.homeworks_total} домашних работ</p></section>
          </div>

          <div className="scenter__grid scenter__grid--wide">
            <section className="scenter__panel scenter__panel--span2"><div className="scenter__panel-head"><div><span className="scenter__kicker">Результат</span><h3>Динамика баллов</h3></div><span className="scenter__hint">Нажми на точку, чтобы открыть ответы</span></div><ScoreChart data={data.scores} onOpen={openSubmission} /></section>
            <section className="scenter__panel"><div className="scenter__panel-head"><div><span className="scenter__kicker">Диагностика</span><h3>По предметам</h3></div></div><div className="scenter__subject-bars">{data.subjects.length ? [...data.subjects].sort((a, b) => b.average - a.average).map((subject) => <div key={subject.subject}><div><b>{subject.subject}</b><span>{subject.average}% · {subject.attempts} раб.</span></div><i><span style={{ width: `${subject.average}%`, background: subject.color }} /></i></div>) : <div className="admin__empty">Пока недостаточно оценённых работ</div>}</div>{bestSubject && <div className="scenter__insight good"><Trophy size={16} /><span><b>Сильная сторона:</b> {bestSubject.subject}, средний результат {bestSubject.average}%.</span></div>}{weakSubject && weakSubject.subject !== bestSubject?.subject && <div className="scenter__insight warn"><Target size={16} /><span><b>Зона роста:</b> {weakSubject.subject}, средний результат {weakSubject.average}%.</span></div>}</section>
          </div>

          <div className="scenter__grid">
            <section className="scenter__panel"><div className="scenter__panel-head"><div><span className="scenter__kicker">Вовлечённость</span><h3>Как пользуется платформой</h3></div></div><dl className="scenter__facts"><div><dt>ИИ-чаты</dt><dd>{data.engagement.ai_chats}</dd></div><div><dt>Вопросы ИИ</dt><dd>{data.engagement.ai_messages}</dd></div><div><dt>Тренажёр</dt><dd>{data.engagement.trainer_correct}/{data.engagement.trainer_attempted}</dd></div><div><dt>Банк заданий</dt><dd>{data.engagement.bank_correct}/{data.engagement.bank_attempted}</dd></div><div><dt>Достижения</dt><dd>{data.engagement.achievements}</dd></div><div><dt>Покупки</dt><dd>{data.engagement.purchases}</dd></div></dl></section>
            <section className="scenter__panel"><div className="scenter__panel-head"><div><span className="scenter__kicker">По месяцам</span><h3>Срез результатов</h3></div></div><div className="scenter__months">{data.months.length ? [...data.months].reverse().slice(0, 12).map((month) => <div key={month.month}><span>{month.month}</span><b>{month.average}%</b><small>{month.attempts} раб.</small></div>) : <div className="admin__empty">Нет данных</div>}</div></section>
            <section className="scenter__panel"><div className="scenter__panel-head"><div><span className="scenter__kicker">Внимание</span><h3>Просроченные ДЗ</h3></div><AlertTriangle size={19} /></div><div className="scenter__overdue">{data.overdue.length ? data.overdue.slice(0, 8).map((work) => <div key={work.id}><span><b>{work.title}</b><small>{work.course}</small></span><time>{fmtDate(work.deadline)}</time></div>) : <div className="scenter__good-empty"><Check size={18} /> Просроченных домашних работ нет</div>}</div></section>
          </div>
        </div>
      )}

      {tab === 'works' && (
        <div className="scenter__content">
          <section className="scenter__panel">
            <div className="scenter__worktools"><label><Search size={16} /><input value={workSearch} onChange={(event) => setWorkSearch(event.target.value)} placeholder="Название, курс или предмет" /></label><select value={workKindFilter} onChange={(event) => setWorkKindFilter(event.target.value)}><option value="all">Все работы</option><option value="homework">Домашние задания</option><option value="mock">Пробники</option></select><span>{filteredWorks.length} результатов</span></div>
            <div className="scenter__worklist">
              {filteredWorks.map((work) => <button key={work.id} onClick={() => openSubmission(work.id)}><span className={`scenter__workicon is-${work.kind}`}>{work.kind === 'mock' ? <GraduationCap size={18} /> : <PencilLine size={18} />}</span><span className="scenter__workmain"><span><b>{work.title}</b><em>{workKind(work.kind)} · {LEVEL[work.level] || work.level}</em></span><small>{work.subject} · {work.course}{work.lesson ? ` · ${work.lesson}` : ''}</small></span><span className="scenter__workdate">{fmtDate(work.date)}</span><span className={`scenter__workscore ${work.percent != null && work.percent < 50 ? 'low' : ''}`}>{work.status === 'review' ? <><Clock size={14} /> Проверка</> : pct(work.percent)}</span><ChevronRight size={17} /></button>)}
              {!filteredWorks.length && <div className="admin__empty">По выбранным фильтрам работ нет</div>}
            </div>
          </section>
        </div>
      )}

      {tab === 'courses' && (
        <div className="scenter__content"><div className="scenter__coursegrid">{(courseId ? data.courses.filter((course) => course.id === courseId) : data.courses).map((course) => {
          const lp = course.lessons_total ? Math.round(course.lessons_done * 100 / course.lessons_total) : 0
          return <section className="scenter__course" key={course.id} style={{ '--course': course.color }}><div className="scenter__course-head"><span>{course.subject}</span><i className={course.active ? 'active' : ''}>{course.active ? 'доступ открыт' : 'доступ истёк'}</i></div><h3>{course.title}</h3><div className="scenter__course-progress"><span style={{ width: `${lp}%` }} /></div><div className="scenter__course-metrics"><div><b>{course.lessons_done}/{course.lessons_total}</b><span>уроков</span></div><div><b>{course.homeworks_done}/{course.homeworks_total}</b><span>домашек</span></div><div><b>{course.mocks_done}/{course.mocks_total}</b><span>пробников</span></div><div><b>{pct(course.average_score)}</b><span>средний</span></div></div><footer><span>Последняя активность: {fmtDate(course.last_activity)}</span><span>{course.source === 'manual' ? 'выдан вручную' : course.source}</span></footer></section>
        })}{!data.courses.length && <div className="admin__empty">Ученику пока не открыт ни один курс</div>}</div></div>
      )}

      {tab === 'reports' && report && (
        <div className="scenter__content"><div className="scenter__reportgrid">
          <section className="scenter__panel"><div className="scenter__panel-head"><div><span className="scenter__kicker">Автоматизация</span><h3>Еженедельный отчёт</h3></div><Mail size={20} /></div><p className="scenter__lead">Раз в неделю платформа отправит самое важное: активные дни, средний результат, сданные и просроченные работы, сильные стороны и зоны роста.</p><label className="scenter__switch"><input type="checkbox" checked={report.enabled} onChange={(event) => setReport({ ...report, enabled: event.target.checked })} /><span /> <b>{report.enabled ? 'Отправка включена' : 'Отправка выключена'}</b></label><label className="scenter__field">Получатели <span>по одному адресу в строке, максимум 5</span><textarea rows="4" value={report.recipientsText ?? report.recipients.join('\n')} onChange={(event) => setReport({ ...report, recipientsText: event.target.value })} placeholder="parent@example.ru" /></label><div className="scenter__row2"><label className="scenter__field">День отправки<select value={report.weekday} onChange={(event) => setReport({ ...report, weekday: Number(event.target.value) })}>{WEEKDAYS.slice(1).map((day, index) => <option value={index + 1} key={day}>{day}</option>)}</select></label><label className="scenter__field">Почта отправителя<input type="email" value={report.sender_email} onChange={(event) => setReport({ ...report, sender_email: event.target.value })} /></label></div><label className="scenter__field">Имя отправителя<input value={report.sender_name} onChange={(event) => setReport({ ...report, sender_name: event.target.value })} /></label><div className="scenter__mailnote"><Clock size={15} /> Отправка выполняется ночью после ежедневного резервного копирования. Отдельный cron не нужен.</div>{reportMsg && <div className={`admin__msg admin__msg--${reportMsg.type}`}>{reportMsg.text}</div>}<button className="btn btn-orange" disabled={reportBusy} onClick={saveReport}><Save size={15} /> {reportBusy ? 'Сохраняю…' : 'Сохранить настройку'}</button></section>
          <section className="scenter__panel"><div className="scenter__panel-head"><div><span className="scenter__kicker">История</span><h3>Последние отправки</h3></div></div><div className="scenter__deliveries">{data.report_deliveries.length ? data.report_deliveries.map((delivery, index) => <div key={index} className={delivery.status}><span>{delivery.status === 'sent' ? <Check size={15} /> : <X size={15} />}</span><div><b>{delivery.status === 'sent' ? 'Отправлено' : 'Ошибка отправки'}</b><small>{delivery.recipients.join(', ') || 'Получатели не указаны'}</small>{delivery.error && <em>{delivery.error}</em>}</div><time>{fmtDate(delivery.created_at)}</time></div>) : <div className="admin__empty">Отчёты ещё не отправлялись</div>}</div></section>
        </div></div>
      )}

      {(submission || submissionBusy) && (
        <div className="admin__modal" onClick={() => !submissionBusy && setSubmission(null)}>
          <div className="admin__modal-box scenter__submission" onClick={(event) => event.stopPropagation()}>
            {submissionBusy ? <div className="scenter__loading"><RefreshCw className="spin" size={22} /> Загружаю ответы…</div> : <>
              <div className="admin__modal-head"><div><span className="scenter__kicker">Попытка #{submission.submission.id} · {fmtDate(submission.submission.created_at)}</span><h3>{submission.submission.homework_title}</h3></div><button className="admin__iconbtn" onClick={() => setSubmission(null)}><X size={18} /></button></div>
              <div className="scenter__submission-summary"><span>{workKind(submission.submission.kind)}</span><b>{submission.submission.score} из {submission.submission.max_score} баллов</b><em>{submission.submission.status === 'review' ? 'На проверке' : 'Проверено'}</em></div>
              <div className="scenter__answers">{submission.answers.map((answer, index) => <article key={answer.id}>
                <header><span>№ {index + 1}{answer.optional ? ' · необязательное' : ''}{answer.hard ? ' · повышенная сложность' : ''}</span><b>{answer.earned} / {answer.points} б.</b></header>
                {answer.images?.length > 0 && <div className="scenter__question-images">{answer.images.map((url) => <a href={url} target="_blank" rel="noreferrer" key={url}><img src={url} alt="Иллюстрация к вопросу" /></a>)}</div>}
                <MarkdownContent source={answer.text} empty="" />
                {answer.audio?.length > 0 && <div className="test__audio">{answer.audio.map((item, i) => <AudioMessage key={`${item.url}-${i}`} src={item.url} name={item.name} />)}</div>}
                {answer.files?.length > 0 && <div className="scenter__question-files">{answer.files.map((file) => <a href={file.url} target="_blank" rel="noreferrer" key={file.url}><FileText size={15} /> {file.name}</a>)}</div>}
                <div className="scenter__answer"><span>Ответ ученика</span><p>{answerValue(answer)}</p>{answer.type === 'file' && answer.your_value?.files?.length > 0 && <div className="scenter__student-files">{answer.your_value.files.map((file) => <a href={file.url} target="_blank" rel="noreferrer" key={file.id || file.url}><FileText size={15} /> {file.name}</a>)}</div>}{answer.type === 'audio' && answer.your_value?.files?.length > 0 && <div className="scenter__student-audio">{answer.your_value.files.map((file) => <AudioMessage key={file.id || file.url} src={file.url} name={file.name || 'Устный ответ'} />)}</div>}</div>
                {correctValue(answer) && <div className="scenter__answer is-correct"><span>Правильный ответ</span><p>{correctValue(answer)}</p></div>}
                {answer.solution && <div className="scenter__answer is-solution"><span>Решение</span><MarkdownContent source={answer.solution} empty="" /></div>}
                {answer.mentor_comment && <div className="scenter__mentor"><MessageSquare size={15} /><MarkdownContent source={answer.mentor_comment} empty="" /></div>}
              </article>)}</div>
            </>}
          </div>
        </div>
      )}
    </div>
  )
}
