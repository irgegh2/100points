import { useEffect, useRef, useState } from 'react'
import { Clock, Paperclip, Mic, ArrowUp, X } from 'lucide-react'
import { chatList, chatThread, messageSend, chatUpload } from '../api.js'
import { useDictation } from '../dictation.js'
import ChatAttachment from './ChatAttachment.jsx'

export default function Mentors() {
  const [chats, setChats] = useState([])
  const [active, setActive] = useState(null)   // subject_id
  const [messages, setMessages] = useState([])
  const [draft, setDraft] = useState('')
  const [loading, setLoading] = useState(true)
  const [attachment, setAttachment] = useState(null) // url прикреплённого файла
  const [attaching, setAttaching] = useState(false)
  const fileRef = useRef(null)
  const bodyRef = useRef(null)
  const activeRef = useRef(null)
  activeRef.current = active
  const dict = useDictation((t) => setDraft((d) => (d ? d + ' ' : '') + t))

  const loadList = () => chatList().then((d) => {
    setChats(d.chats || [])
    // activeRef, а не active — иначе в опросе (раз в 7с) старое замыкание видит active=null
    // и каждый раз сбрасывает выбор на первый чат.
    if (activeRef.current == null && d.chats?.length) setActive(d.chats[0].subject_id)
  }).catch(() => {}).finally(() => setLoading(false))
  const loadThread = (sid) => sid && chatThread(sid).then((d) => setMessages(d.messages || [])).catch(() => {})

  useEffect(() => { loadList() }, [])
  // опрос списка (непрочитанные) и открытого треда
  useEffect(() => {
    const t = setInterval(() => { loadList(); if (activeRef.current) loadThread(activeRef.current) }, 7000)
    return () => clearInterval(t)
  }, [])
  useEffect(() => { loadThread(active) }, [active])
  useEffect(() => { if (bodyRef.current) bodyRef.current.scrollTop = bodyRef.current.scrollHeight }, [messages, active])

  const chat = chats.find((c) => c.subject_id === active)
  const letter = (s) => (s || '?').trim().charAt(0).toUpperCase()

  const onPick = async (e) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    setAttaching(true)
    try { const d = await chatUpload(file); setAttachment(d.url) }
    catch (err) { alert(err.message) }
    finally { setAttaching(false) }
  }

  const send = async (e) => {
    e.preventDefault()
    const text = draft.trim()
    const att = attachment
    if ((!text && !att) || !active) return
    setMessages((m) => [...m, { from: 'me', text, attachment: att, time: 'сейчас' }])
    setDraft(''); setAttachment(null)
    try { await messageSend(active, text, att); loadThread(active); loadList() } catch (err) { alert(err.message) }
  }

  if (loading) return <div className="mentors"><div className="chat"><div className="chat__empty"><p>Загрузка…</p></div></div></div>
  if (chats.length === 0) return <div className="mentors"><div className="chat"><div className="chat__empty"><p>Наставник появится, когда у тебя будет доступ к курсу.</p></div></div></div>

  return (
    <div className="mentors">
      <div className="chat">
        {chat && <>
          <div className="chat__head">
            <span className="chat__ava" style={{ background: chat.color }}>{letter(chat.subject)}</span>
            <div className="chat__head-info"><b>{chat.subject}</b><span>Куратор · онлайн</span></div>
            <div className="chat__resp"><Clock size={14} /> Ответ обычно в течение дня</div>
          </div>

          <div className="chat__body" ref={bodyRef}>
            {messages.map((m, i) => (
              <div className={`bubble ${m.from === 'me' ? 'me' : 'them'}`} key={i}>
                {m.attachment && <ChatAttachment url={m.attachment} />}
                {m.text && <div className="bubble__text">{m.text}</div>}
                <div className="bubble__time">{m.time}</div>
              </div>
            ))}
          </div>

          {attachment && (
            <div className="chat__attbar">
              <ChatAttachment url={attachment} />
              <button type="button" className="chat__attremove" onClick={() => setAttachment(null)} title="Убрать"><X size={15} /></button>
            </div>
          )}
          <form className="chat__input" onSubmit={send}>
            <input ref={fileRef} type="file" accept="image/*,application/pdf" hidden onChange={onPick} />
            <button type="button" className="chat__attach" title="Прикрепить файл" onClick={() => fileRef.current?.click()} disabled={attaching}><Paperclip size={20} /></button>
            <input type="text" placeholder={attaching ? 'Загружаем файл…' : 'Введите сообщение'} value={draft} onChange={(e) => setDraft(e.target.value)} />
            {draft.trim() || attachment
              ? <button type="submit" className="chat__send"><ArrowUp size={18} /></button>
              : <button type="button" className={`chat__mic ${dict.listening ? 'listening' : ''}`} onClick={dict.toggle} disabled={!dict.supported} title={dict.supported ? 'Голосовой ввод' : 'Голосовой ввод не поддерживается браузером'}><Mic size={18} /></button>}
          </form>
        </>}
      </div>

      <aside className="mentors__list">
        <h3>Наставники</h3>
        <p className="mentors__hint">Есть вопрос — задай его куратору по предмету. Ответим в течение дня.</p>
        {chats.map((m) => (
          <button key={m.subject_id} className={`mrow ${m.subject_id === active ? 'active' : ''}`} onClick={() => setActive(m.subject_id)}>
            <span className="mrow__ava" style={{ background: m.color }}>{letter(m.subject)}</span>
            <span className="mrow__main">
              <span className="mrow__top"><b>{m.subject}</b>{m.time && <span className="mrow__time">{m.time}</span>}</span>
              <span className="mrow__sub">Куратор</span>
              <span className="mrow__last">{m.last || 'Напиши первым — куратор ответит'}</span>
            </span>
            {m.unread > 0 && <span className="mrow__badge">{m.unread}</span>}
          </button>
        ))}
      </aside>
    </div>
  )
}
