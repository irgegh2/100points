import { useEffect, useState } from 'react'
import { Search, FileText, Link2, Image, Archive, Download } from 'lucide-react'
import { libraryGet } from '../api.js'

const ICON = { doc: FileText, link: Link2, img: Image, zip: Archive }

export default function Library() {
  const [subjects, setSubjects] = useState([])
  const [subjectId, setSubjectId] = useState(0)   // 0 = все
  const [q, setQ] = useState('')
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)

  // Грузим материалы только при смене предмета (не на каждую букву) — поиск фильтрует на клиенте.
  useEffect(() => {
    let alive = true
    setLoading(true)
    libraryGet({ subject_id: subjectId || undefined })
      .then((d) => { if (alive) { setSubjects(d.subjects || []); setItems(d.items || []) } })
      .catch(() => {}).finally(() => { if (alive) setLoading(false) })
    return () => { alive = false }
  }, [subjectId])

  // Мгновенная фильтрация по названию/категории на клиенте — без запросов и мигания.
  const needle = q.trim().toLowerCase()
  const shown = needle
    ? items.filter((it) => (it.title || '').toLowerCase().includes(needle) || (it.category || '').toLowerCase().includes(needle))
    : items

  // группируем по категории
  const groups = {}
  shown.forEach((it) => { (groups[it.category] = groups[it.category] || []).push(it) })

  return (
    <div className="bank">
      <p className="bank__sub">Конспекты, шпаргалки, кодификаторы, демоверсии и другие материалы.</p>
      <div className="bank__chips">
        <button className={subjectId === 0 ? 'active' : ''} onClick={() => setSubjectId(0)}>Все</button>
        {subjects.map((s) => (
          <button key={s.id} className={s.id === subjectId ? 'active' : ''} onClick={() => setSubjectId(s.id)}>{s.name}</button>
        ))}
      </div>
      <div className="bank__search">
        <Search size={18} />
        <input type="text" placeholder="Поиск по материалам" value={q} onChange={(e) => setQ(e.target.value)} />
      </div>

      {loading ? <div className="hw__empty">Загрузка…</div> : shown.length === 0 ? (
        <div className="hw__empty">{q ? 'Ничего не найдено' : 'Материалов пока нет.'}</div>
      ) : (
        Object.entries(groups).map(([cat, list]) => (
          <section className="lib__group" key={cat}>
            <div className="lib__group-head">{cat} <span>{list.length}</span></div>
            <div className="lib__list">
              {list.map((it) => {
                const Ico = ICON[it.kind] || FileText
                return (
                  <a className="lib__item" key={it.id} href={it.url || '#'} target="_blank" rel="noreferrer">
                    <span className="lib__item-ico"><Ico size={20} /></span>
                    <span className="lib__item-main">
                      <span className="lib__item-title">{it.title}</span>
                      {it.size && <span className="lib__item-size">{it.size}</span>}
                    </span>
                    <span className="lib__item-dl"><Download size={17} /></span>
                  </a>
                )
              })}
            </div>
          </section>
        ))
      )}
    </div>
  )
}
