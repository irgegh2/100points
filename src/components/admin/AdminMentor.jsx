import { useEffect, useRef, useState } from 'react'
import { ArrowUp, Phone, Mail, ChevronLeft } from 'lucide-react'
import { adminChats, adminChatThread, adminChatSend } from '../../api.js'
import ChatAttachment from '../ChatAttachment.jsx'

export default function AdminMentor() {
  const [chats, setChats] = useState([])
  const [onlyUnread, setOnlyUnread] = useState(false)
  const [open, setOpen] = useState(null)        // { id, user_name, subject }
  const [messages, setMessages] = useState([])
  const [draft, setDraft] = useState('')
  const bodyRef = useRef(null)
  const openRef = useRef(null)
  openRef.current = open

  const loadList = () => adminChats(onlyUnread).then((d) => setChats(d.chats || [])).catch(() => {})
  const loadThread = (id) => id && adminChatThread(id).then((d) => { setOpen(d.chat); setMessages(d.messages || []) }).catch(() => {})

  useEffect(() => { loadList() }, [onlyUnread])
  useEffect(() => {
    const t = setInterval(() => { loadList(); if (openRef.current) adminChatThread(openRef.current.id).then((d) => setMessages(d.messages || [])).catch(() => {}) }, 7000)
    return () => clearInterval(t)
  }, [onlyUnread])
  useEffect(() => { if (bodyRef.current) bodyRef.current.scrollTop = bodyRef.current.scrollHeight }, [messages, open])

  const send = async (e) => {
    e.preventDefault()
    const text = draft.trim()
    if (!text || !open) return
    setMessages((m) => [...m, { from: 'mentor', text, time: 'сейчас' }])
    setDraft('')
    try { await adminChatSend(open.id, text); loadThread(open.id); loadList() } catch (err) { alert(err.message) }
  }

  return (
    <div className="mentors">
      <div className="chat">
        {!open ? (
          <div className="chat__empty"><p>Выбери чат справа, чтобы ответить ученику.</p></div>
        ) : (<>
          <div className="chat__head">
            <button className="aix__back" onClick={() => setOpen(null)}><ChevronLeft size={18} /></button>
            <div className="chat__head-info"><b>{open.user_name} · {open.subject}</b><span>Чат с куратором</span></div>
          </div>
          <div className="chat__body" ref={bodyRef}>
            {messages.map((m, i) => (
              <div className={`bubble ${m.from === 'mentor' ? 'me' : 'them'}`} key={i}>
                {m.attachment && <ChatAttachment url={m.attachment} />}
                {m.text && <div className="bubble__text">{m.text}</div>}
                <div className="bubble__time">{m.time}</div>
              </div>
            ))}
          </div>
          <form className="chat__input" onSubmit={send}>
            <input type="text" placeholder="Ответ куратора…" value={draft} onChange={(e) => setDraft(e.target.value)} />
            <button type="submit" className="chat__send"><ArrowUp size={18} /></button>
          </form>
        </>)}
      </div>

      <aside className="mentors__list">
        <div className="aichats__head"><h3>Чаты учеников</h3>
          <label className="admin__chk"><input type="checkbox" checked={onlyUnread} onChange={(e) => setOnlyUnread(e.target.checked)} /> непрочитанные</label>
        </div>
        {chats.length === 0 && <div className="admin__empty">Чатов нет</div>}
        {chats.map((c) => (
          <button key={c.id} className={`mrow ${open?.id === c.id ? 'active' : ''}`} onClick={() => loadThread(c.id)}>
            <span className="mrow__ava" style={{ background: c.color }}>{(c.subject || '?').charAt(0)}</span>
            <span className="mrow__main">
              <span className="mrow__top"><b>{c.user_name} · {c.subject}</b><span className="mrow__time">{(c.time || '').slice(11)}</span></span>
              <span className="mrow__sub">{c.user_email || ''}{c.user_phone ? ' · ' + c.user_phone : ''}</span>
              <span className="mrow__last">{c.last_from === 'student' ? '' : 'Вы: '}{c.last}</span>
            </span>
            {c.unread > 0 && <span className="mrow__badge">{c.unread}</span>}
          </button>
        ))}
      </aside>
    </div>
  )
}
