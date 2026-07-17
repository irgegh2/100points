import { useEffect, useRef, useState } from 'react'
import { Bot, Plus, Mic, ArrowUp, Sparkles, Home, BookOpen, X, MoreHorizontal, Pencil, Trash2, Check } from 'lucide-react'
import { aiChatList, aiChatCreate, aiChatGet, aiChatStream, aiChatRename, aiChatDelete, aiPlatformAsk } from '../api.js'
import { useDictation } from '../dictation.js'

const SUBJECTS = ['Математика', 'Русский язык', 'Биология', 'История', 'Химия', 'Обществознание', 'Английский язык']

// Старый дизайн ИИ-чата + живой бэкенд (реальный ИИ, стриминг, сохранение чатов).
export default function AiChat() {
  const [chats, setChats] = useState([])           // { id, title, kind }
  const [activeId, setActiveId] = useState(null)
  const [messages, setMessages] = useState([])     // { from:'ai'|'me', text } активного чата
  const [draft, setDraft] = useState('')
  const [typing, setTyping] = useState(false)      // ждём первый кусок ответа
  const [thinking, setThinking] = useState('')     // статус для platform-чата («Изучаю платформу…»)
  const [creating, setCreating] = useState(false)
  const [menuId, setMenuId] = useState(null)
  const [editId, setEditId] = useState(null)
  const [editDraft, setEditDraft] = useState('')
  const bodyRef = useRef(null)
  const dict = useDictation((t) => setDraft((d) => (d ? d + ' ' : '') + t))

  const chat = chats.find((c) => c.id === activeId)

  const loadList = () => aiChatList().then((d) => setChats(d.chats || []))
  useEffect(() => { loadList() }, [])
  // открыть чат — подгрузить сообщения
  useEffect(() => {
    if (!activeId) { setMessages([]); return }
    let alive = true
    aiChatGet(activeId).then((d) => { if (alive) setMessages((d.messages || []).map((m) => ({ from: m.role === 'user' ? 'me' : 'ai', text: m.content }))) }).catch(() => {})
    return () => { alive = false }
  }, [activeId])
  useEffect(() => { bodyRef.current?.scrollTo({ top: 1e9, behavior: 'smooth' }) }, [messages, typing])

  const createChat = async (kind, subject) => {
    setCreating(false)
    try {
      const d = await aiChatCreate({ kind, subject })
      await loadList()
      setActiveId(d.chat.id)
      setMessages((d.messages || []).map((m) => ({ from: m.role === 'user' ? 'me' : 'ai', text: m.content })))
    } catch (e) { alert(e.message) }
  }

  const removeChat = async (id) => {
    setMenuId(null)
    try { await aiChatDelete(id); if (id === activeId) setActiveId(null); loadList() } catch (e) { alert(e.message) }
  }
  const startRename = (c) => { setMenuId(null); setEditId(c.id); setEditDraft(c.title) }
  const saveRename = async () => {
    const t = editDraft.trim(); const id = editId; setEditId(null)
    if (!t) return
    setChats((cs) => cs.map((c) => c.id === id ? { ...c, title: t } : c))
    try { await aiChatRename(id, t) } catch { loadList() }
  }

  const ask = async (text) => {
    const t = text.trim()
    if (!t || typing || !activeId) return
    setMessages((m) => [...m, { from: 'me', text: t }, { from: 'ai', text: '' }])
    setDraft(''); setTyping(true)
    const setLast = (txt) => setMessages((m) => { const a = [...m]; a[a.length - 1] = { from: 'ai', text: txt }; return a })

    if (chat?.kind === 'platform') {
      // Помощь по платформе — RAG (без стрима), показываем стадии.
      setThinking('Обрабатываю вопрос…')
      const sw = setTimeout(() => setThinking('Изучаю платформу…'), 1400)
      try {
        const d = await aiPlatformAsk(activeId, t)
        setLast(d.message.content)
      } catch (e) { setLast('Ошибка: ' + e.message) }
      finally { clearTimeout(sw); setThinking(''); setTyping(false) }
      return
    }
    try {
      await aiChatStream(activeId, t, (full) => { setTyping(false); setLast(full) })
    } catch (e) { setLast('Ошибка: ' + e.message) }
    finally { setTyping(false) }
  }

  return (
    <div className="mentors">
      <div className="chat">
        {!chat ? (
          <div className="chat__empty"><Bot size={40} /><p>Нет открытого чата.<br />Создай новый справа →</p></div>
        ) : (<>
        <div className="chat__head">
          <span className="chat__ava chat__ava--ai"><Bot size={22} /></span>
          <div className="chat__head-info">
            <b>{chat.title} <span className="ai-badge">ИИ</span></b>
            <span className="chat__ai-status"><Sparkles size={12} /> Искусственный интеллект · отвечает за секунды</span>
          </div>
        </div>

        <div className="chat__pinned chat__pinned--ai">
          <span className="chat__pinned-label">Это ИИ-ассистент</span>
          <p>Я отвечаю автоматически и могу ошибаться — важные вещи проверяй у наставника или в учебнике.</p>
        </div>

        <div className="chat__body" ref={bodyRef}>
          {messages.map((m, i) => (
            m.from === 'ai' ? (
              <div className="airow" key={i}>
                <span className="airow__ava"><Bot size={15} /></span>
                {m.text === '' && (typing || thinking) && i === messages.length - 1
                  ? (thinking
                      ? <div className="bubble them"><div className="bubble__text bubble__thinking"><Sparkles size={13} /> {thinking}</div></div>
                      : <div className="bubble them typing"><span /><span /><span /></div>)
                  : <div className="bubble them"><div className="bubble__text">{m.text}</div></div>}
              </div>
            ) : (
              <div className="bubble me" key={i}><div className="bubble__text">{m.text}</div></div>
            )
          ))}
        </div>

        <form className="chat__input" onSubmit={(e) => { e.preventDefault(); ask(draft) }}>
          <input type="text" placeholder="Спроси ИИ-наставника…" value={draft} onChange={(e) => setDraft(e.target.value)} disabled={typing} />
          {draft.trim()
            ? <button type="submit" className="chat__send"><ArrowUp size={18} /></button>
            : <button type="button" className={`chat__mic ${dict.listening ? 'listening' : ''}`} onClick={dict.toggle} disabled={!dict.supported || typing} title={dict.supported ? 'Голосовой ввод' : 'Голосовой ввод не поддерживается браузером'}><Mic size={18} /></button>}
        </form>
        </>)}
      </div>

      <aside className="mentors__list">
        <div className="aichats__head">
          <h3>ИИ-чаты</h3>
          <button className="aichats__new" onClick={() => setCreating(true)}><Plus size={16} /> Новый</button>
        </div>

        <div className="aichats__list">
          {chats.length === 0 && <div className="chat__empty" style={{ padding: 20 }}><p>Чатов пока нет.<br />Создай первый →</p></div>}
          {chats.map((c) => (
            <div key={c.id} className={`mrow ${c.id === activeId ? 'active' : ''}`} onClick={() => setActiveId(c.id)}>
              <span className="mrow__ava mrow__ava--ai">{c.kind === 'platform' ? <Home size={18} /> : (c.kind === 'task' || c.kind === 'subject') ? <BookOpen size={18} /> : <Sparkles size={18} />}</span>
              <span className="mrow__main">
                {editId === c.id ? (
                  <form className="mrow__edit" onClick={(e) => e.stopPropagation()} onSubmit={(e) => { e.preventDefault(); saveRename() }}>
                    <input autoFocus value={editDraft} onChange={(e) => setEditDraft(e.target.value)} onBlur={saveRename} />
                    <button type="submit"><Check size={15} /></button>
                  </form>
                ) : (
                  <>
                    <span className="mrow__top"><b>{c.title}</b></span>
                    <span className="mrow__last">{c.kind === 'platform' ? 'Помощь по платформе' : c.kind === 'task' ? 'Разбор задачи' : c.kind === 'subject' ? 'По предмету' : 'ИИ-помощник'}{c.created_at ? ' · ' + c.created_at.slice(0, 10) : ''}</span>
                  </>
                )}
              </span>
              <button className="mrow__more" onClick={(e) => { e.stopPropagation(); setMenuId(menuId === c.id ? null : c.id) }}><MoreHorizontal size={16} /></button>
              {menuId === c.id && (
                <>
                  <div className="mrow__backdrop" onClick={(e) => { e.stopPropagation(); setMenuId(null) }} />
                  <div className="mrow__menu" onClick={(e) => e.stopPropagation()}>
                    <button onClick={() => startRename(c)}><Pencil size={14} /> Переименовать</button>
                    <button className="danger" onClick={() => removeChat(c.id)}><Trash2 size={14} /> Удалить</button>
                  </div>
                </>
              )}
            </div>
          ))}
        </div>

        {creating && (
          <div className="aicreate" onClick={() => setCreating(false)}>
            <div className="aicreate__card" onClick={(e) => e.stopPropagation()}>
              <div className="aicreate__head"><b>Новый чат</b><button onClick={() => setCreating(false)}><X size={16} /></button></div>
              <div className="aicreate__opts">
                <button onClick={() => createChat('platform')}><Home size={18} /> Помощь по платформе</button>
                <button onClick={() => createChat('free')}><Sparkles size={18} /> Свободный чат</button>
              </div>
              <div className="aicreate__subj-title">По предмету</div>
              <div className="aicreate__chips">
                {SUBJECTS.map((s) => <button key={s} onClick={() => createChat('subject', s)}>{s}</button>)}
              </div>
            </div>
          </div>
        )}
      </aside>
    </div>
  )
}
