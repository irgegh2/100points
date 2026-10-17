import { useEffect, useState } from 'react'
import { ChevronDown, Check, Lock, Crown, Search, X } from 'lucide-react'
import HomeworkTest from './HomeworkTest.jsx'
import { homeworksList } from '../api.js'
import { useLiveRefresh } from '../liveUpdates.js'
import { periodOptionLabel } from '../coursePeriod.js'

const STATUS = {
  todo:   { label: 'К выполнению', cls: 'todo' },
  review: { label: 'На проверке', cls: 'review' },
  done:   { label: 'Выполнено', cls: 'done' },
}

// «2026-11-03 23:59» → «3 ноября, 23:59». Нераспознанное (старые текстовые
// дедлайны вида «до 3 ноября») показываем как есть.
const RU_MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря']
const fmtDeadline = (v) => {
  const m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/)
  if (!m) return v
  const s = `${Number(m[3])} ${RU_MONTHS[Number(m[2]) - 1]}`
  return m[4] ? `${s}, ${m[4]}:${m[5]}` : s
}

export default function Homework() {
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [opened, setOpened] = useState(null)   // id открытого ДЗ
  const [open, setOpen] = useState(null)        // открытый фильтр
  const [query, setQuery] = useState('')
  const [sel, setSel] = useState({ subject: 'Все предметы', month: 'Все периоды', status: 'Все', level: 'Все уровни' })

  const load = () => homeworksList()
    .then((d) => { setItems(d.homeworks || []); setLoading(false) })
    .catch((e) => { setError(e.message); setLoading(false) })
  useEffect(() => { load() }, [])
  useLiveRefresh(() => { if (!opened) load() })

  if (opened) return <HomeworkTest homeworkId={opened} onBack={() => { setOpened(null); load() }} />

  const subjects = ['Все предметы', ...Array.from(new Set(items.map((h) => h.subject)))]
  const periodLabel = (h) => periodOptionLabel({ kind: h.period_kind, name: h.month })
  const months = ['Все периоды', ...Array.from(new Set(items.filter((h) => h.month).map(periodLabel)))]
  const FILTERS = [
    { key: 'subject', label: 'Предмет', options: subjects },
    { key: 'month', label: 'Период', options: months },
    { key: 'status', label: 'Статус', options: ['Все', 'К выполнению', 'На проверке', 'Выполнено'] },
    { key: 'level', label: 'Уровень', options: ['Все уровни', 'Базовый', 'Средний', 'Продвинутый'] },
  ]
  const pick = (key, val) => { setSel((s) => ({ ...s, [key]: val })); setOpen(null) }
  const btnLabel = (f) => { const v = sel[f.key]; return (v === 'Все предметы' || v === 'Все' || v === 'Все периоды' || v === 'Все уровни') ? f.label : v }
  const needle = query.trim().toLocaleLowerCase('ru-RU')
  const levelLabel = (level) => level === 'hard' ? 'Продвинутый' : level === 'medium' ? 'Средний' : 'Базовый'

  const list = items.filter((h) =>
    (sel.subject === 'Все предметы' || h.subject === sel.subject) &&
    (sel.month === 'Все периоды' || periodLabel(h) === sel.month) &&
    (sel.status === 'Все' || STATUS[h.status].label === sel.status) &&
    (sel.level === 'Все уровни' || levelLabel(h.level) === sel.level) &&
    (!needle || [h.title, h.subject, h.month, h.course].some((v) => String(v || '').toLocaleLowerCase('ru-RU').includes(needle)))
  )

  return (
    <div className="hw">
      <div className="hw__filters">
        {open !== null && <div className="hw__backdrop" onClick={() => setOpen(null)} />}
        <label className="hw__search">
          <Search size={17} />
          <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Найти домашнее задание" aria-label="Поиск домашних заданий" />
          {query && <button type="button" onClick={() => setQuery('')} aria-label="Очистить поиск"><X size={15} /></button>}
        </label>
        {FILTERS.map((f, i) => {
          const chosen = sel[f.key] !== 'Все предметы' && sel[f.key] !== 'Все' && sel[f.key] !== 'Все периоды' && sel[f.key] !== 'Все уровни'
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
                  {h.month && <span className="hwcard__month">{periodLabel(h)}</span>}
                  {h.level === 'hard' && <span className="hwcard__level">{h.locked ? <Lock size={11} /> : <Crown size={11} />} Продвинутый</span>}
                  {h.level === 'medium' && <span className="hwcard__level">Средний</span>}
                </span>
                <span className="hwcard__name">{h.title}</span>
                <span className="hwcard__deadline">
                  {h.subject}{h.deadline ? <> · Дедлайн {fmtDeadline(h.deadline)}</> : null}
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
