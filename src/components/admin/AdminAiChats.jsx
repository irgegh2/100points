import { useEffect, useState } from 'react'
import { Sparkles, Home, BookOpen, MessageSquare, Search } from 'lucide-react'
import { adminAiChats, adminChatMessages } from '../../api.js'

const KIND = { platform: ['Помощь по платформе', Home], task: ['Разбор задачи', BookOpen], subject: ['По предмету', BookOpen], free: ['Свободный', Sparkles] }

export default function AdminAiChats() {
  const [chats, setChats] = useState([])
  const [q, setQ] = useState('')
  const [open, setOpen] = useState(null)   // { title, user_name, messages }

  const load = () => adminAiChats(q).then((d) => setChats(d.chats || [])).catch(() => {})
  useEffect(() => { const t = setTimeout(load, 250); return () => clearTimeout(t) }, [q])

  const openChat = (c) => adminChatMessages(c.id).then((d) => setOpen({ ...d.chat, kind: c.kind, messages: d.messages })).catch((e) => alert(e.message))

  return (
    <div className="mentors">
      <div className="chat">
        {!open ? (
          <div className="chat__empty"><Sparkles size={36} /><p>Выбери ИИ-чат справа, чтобы прочитать переписку.<br />Это режим просмотра — отвечает ИИ, вручную писать нельзя.</p></div>
        ) : (<>
          <div className="chat__head">
            <span className="chat__ava chat__ava--ai"><Sparkles size={20} /></span>
            <div className="chat__head-info"><b>{open.user_name} · {open.title}</b><span>{(KIND[open.kind] || ['ИИ-чат'])[0]} · только чтение</span></div>
          </div>
          <div className="chat__body">
            {open.messages.map((m, i) => (
              m.role === 'user'
                ? <div className="bubble me" key={i}><div className="bubble__text">{m.content}</div></div>
                : <div className="airow" key={i}><span className="airow__ava"><Sparkles size={15} /></span><div className="bubble them"><div className="bubble__text">{m.content}</div></div></div>
            ))}
          </div>
          <div className="admin__readonly-note">Только просмотр — в ИИ-чатах отвечает искусственный интеллект.</div>
        </>)}
      </div>

      <aside className="mentors__list">
        <div className="aichats__head"><h3>ИИ-чаты учеников</h3></div>
        <div className="admin__search"><Search size={15} /><input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Поиск по ученику / чату" /></div>
        {chats.length === 0 && <div className="admin__empty">Чатов нет</div>}
        {chats.map((c) => {
          const [label, Ico] = KIND[c.kind] || ['ИИ-чат', Sparkles]
          return (
            <button key={c.id} className={`mrow ${open && open.user_name === c.user_name && open.title === c.title ? 'active' : ''}`} onClick={() => openChat(c)}>
              <span className="mrow__ava mrow__ava--ai"><Ico size={18} /></span>
              <span className="mrow__main">
                <span className="mrow__top"><b>{c.user_name || c.user_email}</b><span className="mrow__time">{(c.created_at || '').slice(0, 10)}</span></span>
                <span className="mrow__sub">{label}</span>
                <span className="mrow__last">{c.title}</span>
              </span>
            </button>
          )
        })}
      </aside>
    </div>
  )
}
