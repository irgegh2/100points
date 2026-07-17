import { useEffect, useState } from 'react'
import { ChevronDown, Check } from 'lucide-react'
import HomeworkTest from './HomeworkTest.jsx'
import { mockList } from '../api.js'

const STATUS = {
  todo:   { label: 'К выполнению', cls: 'todo' },
  review: { label: 'На проверке', cls: 'review' },
  done:   { label: 'Выполнено', cls: 'done' },
}

export default function Mock() {
  const [items, setItems] = useState([])
  const [months, setMonths] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [opened, setOpened] = useState(null)
  const [open, setOpen] = useState(null)
  const [sel, setSel] = useState({ subject: 'Все предметы', month: 'Все месяцы', status: 'Все' })

  const load = () => mockList()
    .then((d) => { setItems(d.mocks || []); setMonths(d.months || []); setLoading(false) })
    .catch((e) => { setError(e.message); setLoading(false) })
  useEffect(() => { load() }, [])

  if (opened) return <HomeworkTest homeworkId={opened} kind="mock" onBack={() => { setOpened(null); load() }} />

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
          return (
            <button className="hwcard" key={m.id} onClick={() => setOpened(m.id)}>
              <span className="hwcard__ico" style={{ background: m.color }}>{(m.subject || '?')[0]}</span>
              <span className="hwcard__main">
                <span className="hwcard__statusrow">
                  <span className={`hwcard__status ${st.cls}`}>{st.label}</span>
                  <span className="hwcard__level">{m.month}</span>
                </span>
                <span className="hwcard__name">{m.title}</span>
                <span className="hwcard__deadline">
                  {m.subject}
                  {m.status === 'done' && m.score != null && <> · {m.score}/{m.max_score} б.</>}
                </span>
              </span>
            </button>
          )
        })}
      </div>
    </div>
  )
}
