import { useEffect, useState } from 'react'
import { ChevronDown, Check, Lock, Crown } from 'lucide-react'
import HomeworkTest from './HomeworkTest.jsx'
import { homeworksList } from '../api.js'

const STATUS = {
  todo:   { label: 'К выполнению', cls: 'todo' },
  review: { label: 'На проверке', cls: 'review' },
  done:   { label: 'Выполнено', cls: 'done' },
}

export default function Homework() {
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [opened, setOpened] = useState(null)   // id открытого ДЗ
  const [open, setOpen] = useState(null)        // открытый фильтр
  const [sel, setSel] = useState({ subject: 'Все предметы', status: 'Все' })

  const load = () => homeworksList()
    .then((d) => { setItems(d.homeworks || []); setLoading(false) })
    .catch((e) => { setError(e.message); setLoading(false) })
  useEffect(() => { load() }, [])

  if (opened) return <HomeworkTest homeworkId={opened} onBack={() => { setOpened(null); load() }} />

  const subjects = ['Все предметы', ...Array.from(new Set(items.map((h) => h.subject)))]
  const FILTERS = [
    { key: 'subject', label: 'Предмет', options: subjects },
    { key: 'status', label: 'Статус', options: ['Все', 'К выполнению', 'На проверке', 'Выполнено'] },
  ]
  const pick = (key, val) => { setSel((s) => ({ ...s, [key]: val })); setOpen(null) }
  const btnLabel = (f) => { const v = sel[f.key]; return (v === 'Все предметы' || v === 'Все') ? f.label : v }

  const list = items.filter((h) =>
    (sel.subject === 'Все предметы' || h.subject === sel.subject) &&
    (sel.status === 'Все' || STATUS[h.status].label === sel.status)
  )

  return (
    <div className="hw">
      <div className="hw__filters">
        {open !== null && <div className="hw__backdrop" onClick={() => setOpen(null)} />}
        {FILTERS.map((f, i) => {
          const chosen = sel[f.key] !== 'Все предметы' && sel[f.key] !== 'Все'
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

      {loading && <div className="hw__empty">Загружаем домашки…</div>}
      {error && <div className="hw__empty">Ошибка: {error}</div>}

      <div className="hw__list">
        {!loading && list.length === 0 && <div className="hw__empty">Нет домашек по выбранным фильтрам</div>}
        {list.map((h) => {
          const st = STATUS[h.status]
          return (
            <button className="hwcard" key={h.id} onClick={() => setOpened(h.id)}>
              <span className="hwcard__ico" style={{ background: h.color }}>{(h.subject || '?')[0]}</span>
              <span className="hwcard__main">
                <span className="hwcard__statusrow">
                  <span className={`hwcard__status ${st.cls}`}>{st.label}</span>
                  {h.level === 'hard' && <span className="hwcard__level">{h.locked ? <Lock size={11} /> : <Crown size={11} />} Повышенный</span>}
                </span>
                <span className="hwcard__name">{h.title}</span>
                <span className="hwcard__deadline">
                  {h.subject} · Дедлайн {h.deadline}
                  {h.status === 'done' && h.score != null && <> · {h.score}/{h.max_score} б.</>}
                </span>
              </span>
            </button>
          )
        })}
      </div>
    </div>
  )
}
