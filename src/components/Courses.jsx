import { useState, useEffect } from 'react'
import { Landmark, Feather, Leaf, GraduationCap, Users, Languages, MoreHorizontal } from 'lucide-react'
import CourseInside from './CourseInside.jsx'
import { coursesList } from '../api.js'

const TABS = ['Все', 'Летняя школа', 'Вводные', 'Основные', 'Профориентация']

// какие типы показывают плашку на обложке и с каким текстом
const PLATE = { 'Вводные': 'Вводный курс', 'Летняя школа': 'Летняя школа' }

const ICONS = { landmark: Landmark, feather: Feather, leaf: Leaf, cap: GraduationCap, social: Users, english: Languages }

// уникальный дудл-фон под каждый предмет (?v — чтобы браузер не кэшировал старые версии)
const V = '9'
const PATTERNS = {
  'Профильная математика': `/img/pattern-math.svg?v=${V}`,
  'Русский язык': `/img/pattern-rus.svg?v=${V}`,
  'История': `/img/pattern-history.svg?v=${V}`,
  'Обществознание': `/img/pattern-social.svg?v=${V}`,
  'Английский язык': `/img/pattern-english.svg?v=${V}`,
  'Биология': `/img/pattern-bio.svg?v=${V}`,
  'Поступление': `/img/pattern-admission.svg?v=${V}`,
}

function CourseIcon({ name }) {
  if (name === 'pi') return <span className="ccourse__pi">π</span>
  const Ico = ICONS[name]
  return Ico ? <Ico size={26} /> : null
}

export default function Courses() {
  const [tab, setTab] = useState('Все')
  const [opened, setOpened] = useState(null)
  const [courses, setCourses] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let alive = true
    coursesList()
      .then((d) => { if (alive) { setCourses(d.courses || []); setLoading(false) } })
      .catch((e) => { if (alive) { setError(e.message); setLoading(false) } })
    return () => { alive = false }
  }, [])

  if (opened) return <CourseInside courseId={opened} onBack={() => setOpened(null)} />

  const list = tab === 'Все' ? courses : courses.filter((c) => c.type === tab)

  return (
    <div className="courses-lk">
      <div className="courses-lk__tabs">
        {TABS.map((t) => (
          <button key={t} className={t === tab ? 'active' : ''} onClick={() => setTab(t)}>{t}</button>
        ))}
      </div>

      {loading && <div className="courses-lk__empty">Загружаем курсы…</div>}
      {error && <div className="courses-lk__empty">Не удалось загрузить курсы: {error}</div>}
      {!loading && !error && list.length === 0 && <div className="courses-lk__empty">В этой категории пока нет курсов.</div>}

      <div className="courses-lk__grid" key={tab}>
        {list.map((c, i) => (
          <article className="ccourse" key={c.id} style={{ animationDelay: `${i * 45}ms` }} onClick={() => setOpened(c.id)}>
            <div className="ccourse__cover" style={{ backgroundColor: c.color, '--pat': `url('${PATTERNS[c.subject] || `/img/course-pattern.svg?v=${V}`}')` }}>
              <span className="ccourse__badge"><CourseIcon name={c.icon} /></span>
              {PLATE[c.type] && <span className="ccourse__plate">{PLATE[c.type]}</span>}
            </div>
            <div className="ccourse__body">
              <span className="ccourse__tag">{c.subject}</span>
              <div className="ccourse__row">
                <div>
                  <h4>{c.title}</h4>
                  <div className="ccourse__meta">{c.blocks} блоков</div>
                </div>
                <button className="ccourse__more" aria-label="Меню" onClick={(e) => e.stopPropagation()}><MoreHorizontal size={18} /></button>
              </div>
            </div>
          </article>
        ))}
      </div>
    </div>
  )
}
