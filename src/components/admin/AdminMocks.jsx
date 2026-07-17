import { useEffect, useState } from 'react'
import { Plus, Trash2, Save, ChevronRight, UserRound } from 'lucide-react'
import { catList, catCreate, catUpdate, catDelete } from '../../api.js'
import AdminQuestionsModal from './AdminQuestionsModal.jsx'
import AdminMockAssignments from './AdminMockAssignments.jsx'

export default function AdminMocks() {
  const [courses, setCourses] = useState([])
  const [mocks, setMocks] = useState([])
  const [form, setForm] = useState({ course_id: '', month: '', title: '', format: 'normal', access_mode: 'general' })
  const [msg, setMsg] = useState(null)
  const [busy, setBusy] = useState(false)
  const [qModal, setQModal] = useState(null)
  const [assignModal, setAssignModal] = useState(null)

  const load = async () => {
    const [c, m] = await Promise.all([catList('courses'), catList('mock_exams')])
    setCourses(c.items || [])
    setMocks(m.items || [])
    setForm((f) => f.course_id ? f : { ...f, course_id: c.items?.[0]?.id || '' })
  }
  useEffect(() => { load() }, [])

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }))

  const create = async (e) => {
    e.preventDefault()
    if (!form.course_id) return setMsg({ type: 'err', text: 'Сначала создайте курс' })
    if (!form.month.trim() || !form.title.trim()) return setMsg({ type: 'err', text: 'Заполните месяц и название' })
    setBusy(true); setMsg(null)
    try {
      const payload = { ...form, access_mode: form.format === 'proctored' ? 'individual' : form.access_mode, position: mocks.length }
      await catCreate('mock_exams', payload)
      setForm((f) => ({ ...f, title: '' })); await load()
      setMsg({ type: 'ok', text: 'Пробник создан' })
    } catch (err) { setMsg({ type: 'err', text: err.message }) }
    finally { setBusy(false) }
  }
  const saveRow = (m) => catUpdate('mock_exams', { id: m.id, course_id: m.course_id, month: m.month, title: m.title, format: m.format || 'normal', access_mode: (m.format || 'normal') === 'proctored' ? 'individual' : (m.access_mode || 'general') }).then(load).catch((e) => alert(e.message))
  const remove = (m) => confirm(`Удалить пробник «${m.title}» со всеми вопросами?`) && catDelete('mock_exams', m.id).then(load)
  const patch = (id, k, v) => setMocks((list) => list.map((x) => x.id === id ? { ...x, [k]: v } : x))

  return (
    <div className="admin__cols">
      <section className="admin__panel">
        <h2><Plus size={18} /> Новый пробник</h2>
        <p className="admin__hint">Пробник привязан к курсу — если курс закрыт, пробник тоже закрыт. В один месяц можно сделать несколько пробников (разные названия: «… 2», «… ПРО»). Месяц можно потом переименовать — например, прошлогодним поставить «2026».</p>
        <form className="admin__form" onSubmit={create}>
          <label>Курс
            <select value={form.course_id} onChange={set('course_id')}>
              {courses.length === 0 && <option value="">— нет курсов —</option>}
              {courses.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}
            </select>
          </label>
          <label>Месяц <input value={form.month} onChange={set('month')} placeholder="Март (или 2026 для архива)" /></label>
          <label>Название <input value={form.title} onChange={set('title')} placeholder="Пробный ЕГЭ по математике 2 / ПРО" /></label>
          <div className="admin__row2">
            <label>Формат
              <select value={form.format} onChange={(e) => setForm((f) => ({ ...f, format: e.target.value, access_mode: e.target.value === 'proctored' ? 'individual' : f.access_mode }))}>
                <option value="normal">Обычный</option>
                <option value="proctored">С наставником и камерой</option>
              </select>
            </label>
            <label>Кому доступен
              <select value={form.format === 'proctored' ? 'individual' : form.access_mode} disabled={form.format === 'proctored'} onChange={set('access_mode')}>
                <option value="general">Всем на курсе</option>
                <option value="individual">Только назначенным</option>
              </select>
            </label>
          </div>
          {msg && <div className={`admin__msg admin__msg--${msg.type}`}>{msg.text}</div>}
          <button className="btn btn-orange btn-lg" disabled={busy}>{busy ? 'Создаём…' : 'Создать пробник'}</button>
        </form>
      </section>

      <section className="admin__panel">
        <h2>Пробники <span className="admin__count">{mocks.length}</span></h2>
        <div className="admin__list">
          {mocks.length === 0 && <div className="admin__empty">Пока нет пробников</div>}
          {mocks.map((m) => (
            <div className="admin__mockrow" key={m.id}>
              <div className="admin__row3">
                <label>Курс
                  <select className="admin__inp" value={m.course_id} onChange={(e) => patch(m.id, 'course_id', Number(e.target.value))}>
                    {courses.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}
                  </select>
                </label>
                <label>Месяц <input className="admin__inp" value={m.month} onChange={(e) => patch(m.id, 'month', e.target.value)} /></label>
                <label>Название <input className="admin__inp" value={m.title} onChange={(e) => patch(m.id, 'title', e.target.value)} /></label>
              </div>
              <div className="admin__row2">
                <label>Формат
                  <select className="admin__inp" value={m.format || 'normal'} onChange={(e) => patch(m.id, 'format', e.target.value)}>
                    <option value="normal">Обычный</option><option value="proctored">С наставником и камерой</option>
                  </select>
                </label>
                <label>Доступ
                  <select className="admin__inp" value={(m.format || 'normal') === 'proctored' ? 'individual' : (m.access_mode || 'general')} disabled={(m.format || 'normal') === 'proctored'} onChange={(e) => patch(m.id, 'access_mode', e.target.value)}>
                    <option value="general">Всем на курсе</option><option value="individual">Только назначенным</option>
                  </select>
                </label>
              </div>
              <div className="admin__edit-btns">
                <button className="btn btn-light btn-sm" onClick={() => saveRow(m)}><Save size={14} /> Сохранить</button>
                <button className="btn btn-light btn-sm" onClick={() => setQModal(m)}>Вопросы <ChevronRight size={14} /></button>
                {((m.access_mode || 'general') === 'individual' || (m.format || 'normal') === 'proctored') && <button className="btn btn-light btn-sm" onClick={() => setAssignModal(m)}><UserRound size={14} /> Назначения</button>}
                <button className="btn admin__delbtn btn-sm" onClick={() => remove(m)}><Trash2 size={14} /> Удалить</button>
              </div>
            </div>
          ))}
        </div>
      </section>

      {qModal && <AdminQuestionsModal parent={{ key: 'mock_id', id: qModal.id, title: qModal.title }} onClose={() => setQModal(null)} />}
      {assignModal && <AdminMockAssignments mock={assignModal} onClose={() => setAssignModal(null)} />}
    </div>
  )
}
