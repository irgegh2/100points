import { useEffect, useState } from 'react'
import { Plus, Trash2, Save } from 'lucide-react'
import { catList, catCreate, catUpdate, catDelete } from '../../api.js'
import AdminFold from './AdminFold.jsx'

const TYPES = [['webinar', 'Вебинар'], ['practice', 'Практика']]
// 'YYYY-MM-DD HH:MM' ↔ input datetime-local 'YYYY-MM-DDTHH:MM'
const toInput = (s) => (s || '').replace(' ', 'T').slice(0, 16)
const fromInput = (s) => (s || '').replace('T', ' ').slice(0, 16)

export default function AdminSchedule() {
  const [courses, setCourses] = useState([])
  const [courseId, setCourseId] = useState('')
  const [events, setEvents] = useState([])
  const [form, setForm] = useState({ type: 'webinar', title: '', starts_at: '', duration: 90, link: '' })
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState(null)

  useEffect(() => { catList('courses').then((d) => { setCourses(d.items || []); if (!courseId && d.items?.length) setCourseId(d.items[0].id) }) }, [])
  const load = (cid) => cid && catList('events', { course_id: cid }).then((d) => setEvents(d.items || []))
  useEffect(() => { load(courseId) }, [courseId])

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }))
  const create = async (e) => {
    e.preventDefault()
    if (!form.title.trim() || !form.starts_at) return setMsg({ type: 'err', text: 'Заполни название и дату/время' })
    setBusy(true); setMsg(null)
    try {
      const d = await catCreate('events', { ...form, starts_at: fromInput(form.starts_at), course_id: courseId, position: events.length })
      setEvents((list) => [...list, d.item]); setForm((f) => ({ ...f, title: '', starts_at: '', link: '' }))
      setMsg({ type: 'ok', text: 'Событие добавлено' })
    } catch (err) { setMsg({ type: 'err', text: err.message }) } finally { setBusy(false) }
  }
  const patch = (id, k, v) => setEvents((a) => a.map((x) => x.id === id ? { ...x, [k]: v } : x))
  const save = (ev) => catUpdate('events', { id: ev.id, type: ev.type, title: ev.title, starts_at: fromInput(toInput(ev.starts_at)), duration: ev.duration, link: ev.link }).catch((e) => alert(e.message))
  const del = (ev) => confirm('Удалить событие?') && catDelete('events', ev.id).then(() => setEvents((list) => list.filter((x) => x.id !== ev.id)))

  return (
    <div className="admin__cols">
      <section className="admin__panel">
        <div className="admin__panel-head">
          <h2><Plus size={18} /> Новое событие</h2>
          <label className="admin__field admin__course-filter">Курс
            <select className="admin__inp" value={courseId} onChange={(e) => setCourseId(Number(e.target.value))}>{courses.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}</select>
          </label>
        </div>
        <p className="admin__hint">События доступны ученикам, зачисленным на этот курс. На Главной «Обучение сегодня» показывает сегодняшние эфиры.</p>
        <form className="admin__form" onSubmit={create}>
          <div className="admin__row2">
            <label>Тип <select value={form.type} onChange={set('type')}>{TYPES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></label>
            <label>Дата и время <input type="datetime-local" value={form.starts_at} onChange={set('starts_at')} /></label>
          </div>
          <label>Название <input value={form.title} onChange={set('title')} placeholder="Вебинар: параметры" /></label>
          <div className="admin__row2">
            <label>Длительность (мин) <input type="number" value={form.duration} onChange={set('duration')} /></label>
            <label>Ссылка на эфир <input value={form.link} onChange={set('link')} placeholder="https://kinescope.io/live или внешняя" /></label>
          </div>
          {msg && <div className={`admin__msg admin__msg--${msg.type}`}>{msg.text}</div>}
          <button className="btn btn-orange btn-lg" disabled={busy}>{busy ? 'Добавляем…' : 'Добавить событие'}</button>
        </form>
      </section>

      <section className="admin__panel">
        <h2>События курса <span className="admin__count">{events.length}</span></h2>
        <div className="admin__list">
          {events.length === 0 && <div className="admin__empty">Событий нет</div>}
          {events.map((ev) => (
            <AdminFold key={ev.id} title={ev.title} meta={`${TYPES.find(([v]) => v === ev.type)?.[1] || ev.type} · ${toInput(ev.starts_at).replace('T', ' ')}`}>
              <div className="admin__qcard-head">
                <select className="admin__inp admin__inp--sm" value={ev.type} onChange={(e) => patch(ev.id, 'type', e.target.value)}>{TYPES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
                <input className="admin__inp" type="datetime-local" value={toInput(ev.starts_at)} onChange={(e) => patch(ev.id, 'starts_at', e.target.value)} />
                <button className="admin__iconbtn" onClick={() => save(ev)}><Save size={15} /></button>
                <button className="admin__iconbtn admin__iconbtn--danger" onClick={() => del(ev)}><Trash2 size={15} /></button>
              </div>
              <input className="admin__inp" value={ev.title} onChange={(e) => patch(ev.id, 'title', e.target.value)} />
              <input className="admin__inp" value={ev.link || ''} onChange={(e) => patch(ev.id, 'link', e.target.value)} placeholder="ссылка на эфир" />
            </AdminFold>
          ))}
        </div>
      </section>
    </div>
  )
}
