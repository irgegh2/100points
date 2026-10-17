import { useEffect, useState } from 'react'
import { Search, FileText, Link2, Image, Archive, Download, BookOpen } from 'lucide-react'
import { libraryGet } from '../api.js'
import { useLiveRefresh } from '../liveUpdates.js'
import { periodOptionLabel } from '../coursePeriod.js'

const ICON = { doc: FileText, link: Link2, img: Image, zip: Archive }

export default function Library() {
  const [subjects, setSubjects] = useState([])
  const [subjectId, setSubjectId] = useState(0)   // 0 = все
  const [q, setQ] = useState('')
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)

  const reload = () => {
    let alive = true
    setLoading(true)
    libraryGet({ subject_id: subjectId || undefined })
      .then((d) => { if (alive) { setSubjects(d.subjects || []); setItems(d.items || []) } })
      .catch(() => {}).finally(() => { if (alive) setLoading(false) })
    return () => { alive = false }
  }

  // Грузим материалы только при смене предмета (не на каждую букву) — поиск фильтрует на клиенте.
  useEffect(() => reload(), [subjectId])
  useLiveRefresh(reload)

  // Мгновенная фильтрация по уроку, курсу и файлу — без запросов и мигания.
  const needle = q.trim().toLowerCase()
  const shown = needle
    ? items.filter((it) => [it.title, it.lesson_title, it.course_title, it.period_name, it.section_title, it.subject_name]
      .some((value) => String(value || '').toLowerCase().includes(needle)))
    : items

  // Один урок — одна полка. Порядок уже задан программой курса на сервере.
  const groups = []
  const groupByLesson = new Map()
  shown.forEach((item) => {
    let group = groupByLesson.get(item.lesson_id)
    if (!group) {
      group = { ...item, items: [] }
      groupByLesson.set(item.lesson_id, group)
      groups.push(group)
    }
    group.items.push(item)
  })

  return (
    <div className="bank">
      <p className="bank__sub">Материалы из доступных уроков основных курсов — без повторной загрузки и лишних категорий.</p>
      <div className="bank__chips">
        <button className={subjectId === 0 ? 'active' : ''} onClick={() => setSubjectId(0)}>Все</button>
        {subjects.map((s) => (
          <button key={s.id} className={s.id === subjectId ? 'active' : ''} onClick={() => setSubjectId(s.id)}>{s.name}</button>
        ))}
      </div>
      <div className="bank__search">
        <Search size={18} />
        <input type="text" placeholder="Найти урок или файл" value={q} onChange={(e) => setQ(e.target.value)} />
      </div>

      {loading ? <div className="hw__empty">Загрузка…</div> : shown.length === 0 ? (
        <div className="hw__empty">{q ? 'Ничего не найдено' : 'В доступных уроках пока нет материалов.'}</div>
      ) : (
        groups.map((group) => (
          <section className="lib__group lib__lesson" key={group.lesson_id}>
            <div className="lib__lesson-head">
              <span className="lib__lesson-ico" style={{ '--lesson-color': group.subject_color }}><BookOpen size={19} /></span>
              <div className="lib__lesson-copy">
                <div className="lib__group-head">{group.lesson_title} <span>{group.items.length}</span></div>
                <div className="lib__lesson-meta">
                  <span>{group.subject_name}</span>
                  <span>{group.course_title}</span>
                  <span>{periodOptionLabel({ kind: group.period_kind, name: group.period_name })}</span>
                  <span>Раздел {group.section_n}</span>
                </div>
              </div>
            </div>
            <div className="lib__list">
              {group.items.map((it) => {
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
