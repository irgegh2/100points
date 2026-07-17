import { useEffect, useState } from 'react'
import { Send, Bell } from 'lucide-react'
import { usersList, adminNotify } from '../../api.js'

// Куда вести по клику на уведомление (ключи разделов ЛК).
const LINKS = [
  ['', '— без перехода —'], ['homework', 'Домашние задания'], ['mock', 'Пробники'],
  ['schedule', 'Расписание'], ['achievements', 'Достижения'], ['shop', 'Магазин'],
  ['mentor', 'Онлайн-наставник'], ['courses', 'Курсы'], ['sub', 'Подписка'],
]

export default function AdminNotify() {
  const [users, setUsers] = useState([])
  const [target, setTarget] = useState('all')
  const [text, setText] = useState('')
  const [link, setLink] = useState('')
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState(null)

  useEffect(() => { usersList().then((d) => setUsers((d.users || []).filter((u) => u.role === 'student' || !u.role))).catch(() => {}) }, [])

  const send = async (e) => {
    e.preventDefault()
    if (!text.trim()) return setMsg({ type: 'err', text: 'Введи текст уведомления' })
    setBusy(true); setMsg(null)
    try {
      const r = await adminNotify(target, text.trim(), link || null)
      setText(''); setMsg({ type: 'ok', text: `Отправлено получателям: ${r.sent}` })
    } catch (err) { setMsg({ type: 'err', text: err.message }) }
    finally { setBusy(false) }
  }

  return (
    <div className="admin__cols">
      <section className="admin__panel">
        <h2><Bell size={18} /> Рассылка уведомлений</h2>
        <p className="admin__hint">Уведомление прилетит в колокольчик ученику. Можно всем сразу или одному.</p>
        <form className="admin__form" onSubmit={send}>
          <label>Кому
            <select value={target} onChange={(e) => setTarget(e.target.value)}>
              <option value="all">Всем ученикам ({users.length})</option>
              {users.map((u) => <option key={u.id} value={u.id}>{u.name || u.email || u.phone || ('id ' + u.id)}</option>)}
            </select>
          </label>
          <label>Текст <textarea className="admin__textarea" rows={3} value={text} onChange={(e) => setText(e.target.value)} placeholder="Например: Завтра вебинар по математике в 18:00" maxLength={400} /></label>
          <label>Перейти в раздел <span>(по клику)</span>
            <select value={link} onChange={(e) => setLink(e.target.value)}>{LINKS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
          </label>
          {msg && <div className={`admin__msg admin__msg--${msg.type}`}>{msg.text}</div>}
          <button className="btn btn-orange btn-lg" disabled={busy}><Send size={16} /> {busy ? 'Отправляем…' : 'Отправить'}</button>
        </form>
      </section>

      <section className="admin__panel">
        <h2>Как это выглядит у ученика</h2>
        <div className="admin__notif-preview">
          <div className="lk__notif-row" style={{ cursor: 'default' }}>
            <span className="lk__notif-ico" style={{ background: '#8a93a31a', color: '#8a93a3' }}><Bell size={16} /></span>
            <div><div className="lk__notif-text">{text || 'Текст уведомления появится тут'}</div><div className="lk__notif-time">только что</div></div>
          </div>
        </div>
        <p className="admin__hint">Иконка-колокольчик, красная точка непрочитанного, по клику — переход в выбранный раздел. Непрочитанные хранятся вечно, прочитанные — 7 дней.</p>
      </section>
    </div>
  )
}
