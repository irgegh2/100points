import { useEffect, useState } from 'react'
import { ChevronDown, Check, Camera, Clock3, UserRound } from 'lucide-react'
import HomeworkTest from './HomeworkTest.jsx'
import { mockList } from '../api.js'
import ProctoredMock from './ProctoredMock.jsx'

const STATUS = {
  todo:   { label: 'К выполнению', cls: 'todo' },
  review: { label: 'На проверке', cls: 'review' },
  done:   { label: 'Выполнено', cls: 'done' },
}

const formatCountdown = (ms) => {
  const total = Math.max(0, Math.ceil(ms / 1000))
  const days = Math.floor(total / 86400)
  const hours = Math.floor((total % 86400) / 3600)
  const minutes = Math.floor((total % 3600) / 60)
  const seconds = total % 60
  const time = [hours, minutes, seconds].map((n) => String(n).padStart(2, '0')).join(':')
  return days > 0 ? `${days} дн. ${time}` : time
}

const proctoredTimer = (mock, now) => {
  if (!mock.starts_at) return null
  if (mock.status === 'done' || mock.session_state === 'finished') return { state: 'done', label: 'Завершён', value: '' }
  const start = Number(mock.starts_at_ts) || new Date(String(mock.starts_at).replace(' ', 'T')).getTime()
  const end = Number(mock.ends_at_ts) || start + Number(mock.duration || 0) * 60000
  if (!Number.isFinite(start) || !Number.isFinite(end)) return null
  if (now < start) return { state: 'waiting', label: 'До начала', value: formatCountdown(start - now) }
  if (now <= end) return { state: 'live', label: 'Осталось', value: formatCountdown(end - now) }
  return { state: 'ended', label: 'Время вышло', value: '' }
}

export default function Mock() {
  const [items, setItems] = useState([])
  const [months, setMonths] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [opened, setOpened] = useState(null)
  const [open, setOpen] = useState(null)
  const [clockOffset, setClockOffset] = useState(0)
  const [now, setNow] = useState(Date.now())
  const [sel, setSel] = useState({ subject: 'Все предметы', month: 'Все месяцы', status: 'Все' })

  const load = () => mockList()
    .then((d) => {
      const offset = Number.isFinite(Number(d.server_now_ms)) ? Number(d.server_now_ms) - Date.now() : 0
      setClockOffset(offset); setNow(Date.now() + offset)
      setItems(d.mocks || []); setMonths(d.months || []); setLoading(false)
    })
    .catch((e) => { setError(e.message); setLoading(false) })
  useEffect(() => { load() }, [])
  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now() + clockOffset), 1000)
    return () => clearInterval(timer)
  }, [clockOffset])

  if (opened?.format === 'proctored') return <ProctoredMock assignmentId={opened.assignmentId} onBack={() => { setOpened(null); load() }} />
  if (opened) return <HomeworkTest homeworkId={opened.mockId} kind="mock" onBack={() => { setOpened(null); load() }} />

  const subjects = ['Все предметы', ...Array.from(new Set(items.map((m) => m.subject)))]
  const FILTERS = [
    { key: 'subject', label: 'Предмет', options: subjects },
    { key: 'month', label: 'Месяц', options: ['Все месяцы', ...months] },
    { key: 'status', label: 'Статус', options: ['Все', 'К выполнению', 'На проверке', 'Выполнено'] },
  ]
  const pick = (key, val) => { setSel((s) => ({ ...s, [key]: val })); setOpen(null) }
  const btnLabel = (f) => { const v = sel[f.key]; return (v === 'Все предметы' || v === 'Все' || v === 'Все месяцы') ? f.label : v }

  const list = items.filter((m) =>
    (sel.subject === 'Все предметы' || m.subject === sel.subject) &&
    (sel.month === 'Все месяцы' || m.month === sel.month) &&
    (sel.status === 'Все' || STATUS[m.status].label === sel.status)
  )

  return (
    <div className="hw">
      <div className="hw__filters">
        {open !== null && <div className="hw__backdrop" onClick={() => setOpen(null)} />}
        {FILTERS.map((f, i) => {
          const chosen = sel[f.key] !== 'Все предметы' && sel[f.key] !== 'Все' && sel[f.key] !== 'Все месяцы'
          return (
            <div className="hw__fwrap" key={f.key}>
              <button className={`hw__filter ${open === i ? 'open' : ''} ${chosen ? 'has' : ''}`} onClick={() => setOpen(open === i ? null : i)}>
                {btnLabel(f)} <ChevronDown size={15} />
              </button>
              {open === i && (
                <div className="hw__menu">
                  {f.options.map((o) => (
                    <button key={o} className={sel[f.key] === o ? 'active' : ''} onClick={() => pick(f.key, o)}>
                      {o}{sel[f.key] === o && <Check size={15} />}
                    </button>
                  ))}
                </div>
              )}
            </div>
          )
        })}
      </div>

      {loading && <div className="hw__empty">Загружаем пробники…</div>}
      {error && <div className="hw__empty">Ошибка: {error}</div>}

      <div className="hw__list">
        {!loading && list.length === 0 && <div className="hw__empty">Нет пробников по выбранным фильтрам</div>}
        {list.map((m) => {
          const st = STATUS[m.status]
          const timer = m.format === 'proctored' ? proctoredTimer(m, now) : null
          return (
            <button className={`hwcard ${m.format === 'proctored' ? 'hwcard--proctored' : ''}`} key={m.id} onClick={() => setOpened({ format: m.format || 'normal', assignmentId: m.assignment_id, mockId: m.id })}>
              <span className="hwcard__ico" style={{ background: m.color }}>{(m.subject || '?')[0]}</span>
              <span className="hwcard__main">
                <span className="hwcard__statusrow">
                  <span className={`hwcard__status ${st.cls}`}>{st.label}</span>
                  <span className="hwcard__level">{m.month}</span>
                  {m.format === 'proctored' && <span className="hwcard__proctor"><Camera size={12} /> С наставником</span>}
                  {m.format !== 'proctored' && m.access_mode === 'individual' && <span className="hwcard__personal"><UserRound size={12} /> Личный</span>}
                </span>
                <span className="hwcard__name">{m.title}</span>
                <span className="hwcard__deadline">
                  {m.subject}
                  {m.format === 'proctored' && m.starts_at && <> · {new Date(String(m.starts_at).replace(' ', 'T')).toLocaleString('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</>}
                  {m.status === 'done' && m.score != null && <> · {m.score}/{m.max_score} б.</>}
                </span>
                {timer && (
                  <span className={`hwcard__timer is-${timer.state}`} aria-label={`${timer.label}${timer.value ? ` ${timer.value}` : ''}`}>
                    <Clock3 size={14} />
                    <span>{timer.label}</span>
                    {timer.value && <b>{timer.value}</b>}
                  </span>
                )}
              </span>
            </button>
          )
        })}
      </div>
    </div>
  )
}
