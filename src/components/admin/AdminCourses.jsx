import { useEffect, useState } from 'react'
import { Plus, Trash2, ChevronRight } from 'lucide-react'
import { catList, catCreate, catDelete } from '../../api.js'
import AdminCourseEditor from './AdminCourseEditor.jsx'

export const COURSE_TYPES = ['Вводные', 'Основные', 'Летняя школа', 'Профориентация']

export default function AdminCourses() {
  const [subjects, setSubjects] = useState([])
  const [courses, setCourses] = useState([])
  const [form, setForm] = useState({ subject_id: '', title: '', type: 'Вводные', color: '#3f9bff' })
  const [msg, setMsg] = useState(null)
  const [busy, setBusy] = useState(false)
  const [openId, setOpenId] = useState(null)

  const load = async () => {
    const [s, c] = await Promise.all([catList('subjects'), catList('courses')])
    setSubjects(s.items || [])
    setCourses(c.items || [])
    // дефолтный предмет в форме
    setForm((f) => f.subject_id ? f : { ...f, subject_id: s.items?.[0]?.id || '' })
  }
  useEffect(() => { load() }, [])

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }))
  const subjName = (id) => subjects.find((s) => s.id === id)?.name || '—'

  const create = async (e) => {
    e.preventDefault()
    if (!form.subject_id) return setMsg({ type: 'err', text: 'Сначала создайте предмет' })
    if (!form.title.trim()) return setMsg({ type: 'err', text: 'Введите название курса' })
    setBusy(true); setMsg(null)
    try {
      await catCreate('courses', { ...form, position: courses.length })
      setForm((f) => ({ ...f, title: '' })); await load()
      setMsg({ type: 'ok', text: 'Курс создан' })
    } catch (err) { setMsg({ type: 'err', text: err.message }) }
    finally { setBusy(false) }
  }

  const remove = async (c) => {
    if (!confirm(`Удалить курс «${c.title}»? Все месяцы, блоки и уроки внутри тоже удалятся.`)) return
    try { await catDelete('courses', c.id); await load() }
    catch (err) { alert(err.message) }
  }

  if (openId) return <AdminCourseEditor courseId={openId} subjects={subjects} onBack={() => { setOpenId(null); load() }} />

  return (
    <div className="admin__cols">
      <section className="admin__panel">
        <h2><Plus size={18} /> Новый курс</h2>
        <form className="admin__form" onSubmit={create}>
          <label>Предмет
            <select value={form.subject_id} onChange={set('subject_id')}>
              {subjects.length === 0 && <option value="">— нет предметов —</option>}
              {subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
          </label>
          <label>Название <input value={form.title} onChange={set('title')} placeholder="Годовой курс ЕГЭ по…" /></label>
          <div className="admin__row2">
            <label>Тип <select value={form.type} onChange={set('type')}>{COURSE_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}</select></label>
            <label>Цвет <input type="color" className="admin__color" value={form.color} onChange={set('color')} /></label>
          </div>
          {msg && <div className={`admin__msg admin__msg--${msg.type}`}>{msg.text}</div>}
          <button className="btn btn-orange btn-lg" disabled={busy}>{busy ? 'Создаём…' : 'Создать курс'}</button>
        </form>
      </section>

      <section className="admin__panel">
        <h2>Курсы <span className="admin__count">{courses.length}</span></h2>
        <div className="admin__list">
          {courses.length === 0 && <div className="admin__empty">Пока нет курсов</div>}
          {courses.map((c) => (
            <div className="admin__crow" key={c.id}>
              <span className="admin__swatch" style={{ background: c.color }} />
              <button className="admin__crow-main" onClick={() => setOpenId(c.id)}>
                <div className="admin__crow-title">{c.title}</div>
                <div className="admin__crow-sub">{subjName(c.subject_id)} · {c.type}</div>
              </button>
              <button className="admin__iconbtn admin__iconbtn--danger" title="Удалить" onClick={() => remove(c)}><Trash2 size={16} /></button>
              <button className="admin__open" onClick={() => setOpenId(c.id)}>Открыть <ChevronRight size={15} /></button>
            </div>
          ))}
        </div>
      </section>
    </div>
  )
}
