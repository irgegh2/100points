import { useEffect, useState } from 'react'
import { Eye, Plus, Trash2, X, Video } from 'lucide-react'
import { adminMockAssignments, adminMockAssign, adminMockUnassign } from '../../api.js'
import AdminProctoredMonitor from './AdminProctoredMonitor.jsx'
import { useDangerConfirm } from './DangerConfirm.jsx'

const localValue = (value) => value ? String(value).replace(' ', 'T').slice(0, 16) : ''
const formatDate = (value) => value ? new Date(String(value).replace(' ', 'T')).toLocaleString('ru-RU', { dateStyle: 'medium', timeStyle: 'short' }) : 'Без даты'

export default function AdminMockAssignments({ mock, onClose }) {
  const confirmDelete = useDangerConfirm()
  const proctored = (mock.format || 'normal') === 'proctored'
  const [data, setData] = useState({ users: [], assignments: [] })
  const [form, setForm] = useState({ user_id: '', starts_at: '', duration: 180 })
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState('')
  const [monitor, setMonitor] = useState(null)
  const load = () => adminMockAssignments(mock.id).then((d) => {
    setData(d)
    setForm((f) => f.user_id ? f : { ...f, user_id: d.users?.[0]?.id || '' })
  }).catch((e) => setMsg(e.message))
  useEffect(() => { load() }, [mock.id])

  const assign = async (e) => {
    e.preventDefault(); setBusy(true); setMsg('')
    try {
      await adminMockAssign({ mock_id: mock.id, user_id: form.user_id, starts_at: proctored ? form.starts_at : null, duration: form.duration })
      await load(); setMsg('Назначение сохранено')
    } catch (err) { setMsg(err.message) } finally { setBusy(false) }
  }
  const remove = async (a) => {
    if (!await confirmDelete({ title: 'Снять назначение пробника?', target: `Ученик «${a.user_name || a.user_email}» · ${mock.title}`, consequence: 'Личный доступ ученика, чат и данные наблюдения этой сессии будут удалены.' })) return
    try { await adminMockUnassign(a.id); await load() } catch (e) { setMsg(e.message) }
  }

  if (monitor) return <div className="admin__modal"><div className="admin__modal-box admin__modal-box--wide admin-live__modal"><AdminProctoredMonitor assignment={monitor} onClose={() => { setMonitor(null); load() }} /></div></div>
  return (
    <div className="admin__modal" onClick={onClose}>
      <div className="admin__modal-box admin__modal-box--wide" onClick={(e) => e.stopPropagation()}>
        <div className="admin__modal-head"><div><h3>Назначения · {mock.title}</h3><p className="admin__hint">{proctored ? 'Укажи ученика и время подключения. Доступ получит только он.' : 'Этот пробник увидят только назначенные ученики.'}</p></div><button className="admin__iconbtn" onClick={onClose}><X size={18} /></button></div>
        <form className="admin-assign__form" onSubmit={assign}>
          <label>Ученик<select className="admin__inp" value={form.user_id} onChange={(e) => setForm((f) => ({ ...f, user_id: e.target.value }))}>{data.users.map((u) => <option value={u.id} key={u.id}>{u.name || u.email || u.phone}</option>)}</select></label>
          {proctored && <label>Дата и время<input className="admin__inp" type="datetime-local" value={localValue(form.starts_at)} onChange={(e) => setForm((f) => ({ ...f, starts_at: e.target.value }))} required /></label>}
          {proctored && <label>Минут<input className="admin__inp" type="number" min="15" max="480" value={form.duration} onChange={(e) => setForm((f) => ({ ...f, duration: e.target.value }))} /></label>}
          <button className="btn btn-orange" disabled={busy || !form.user_id}><Plus size={15} /> {busy ? 'Сохраняем…' : 'Назначить'}</button>
        </form>
        {msg && <div className="admin__msg admin__msg--ok">{msg}</div>}
        <div className="admin-assign__list">
          {data.assignments.length === 0 && <div className="admin__empty">Пока никому не назначен</div>}
          {data.assignments.map((a) => <div className="admin-assign__row" key={a.id}>
            <div className="admin-assign__avatar">{(a.user_name || a.user_email || '?')[0]}</div>
            <div><b>{a.user_name || 'Без имени'}</b><span>{a.user_email || '—'}{proctored ? ` · ${formatDate(a.starts_at)} · ${a.duration} мин` : ''}</span></div>
            <span className={`admin-assign__status is-${a.time_state}`}>{a.time_state === 'live' ? 'В эфире' : a.time_state === 'finished' ? 'Завершён' : proctored ? 'Назначен' : 'Доступ открыт'}</span>
            {proctored && <button className="btn btn-light btn-sm" onClick={() => setMonitor(a)}>{a.time_state === 'live' ? <Video size={14} /> : <Eye size={14} />} Наблюдать</button>}
            <button className="admin__iconbtn admin__iconbtn--danger" onClick={() => remove(a)}><Trash2 size={15} /></button>
          </div>)}
        </div>
      </div>
    </div>
  )
}
