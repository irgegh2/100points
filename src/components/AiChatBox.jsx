import { useState, useRef, useEffect } from 'react'
import { Send, Sparkles } from 'lucide-react'
import { aiChatStream } from '../api.js'

// Окно ИИ-чата со стримингом (живой эфир). props: chatId, initialMessages, seed (авто-вопрос).
export default function AiChatBox({ chatId, initialMessages = [], seed = null }) {
  const [messages, setMessages] = useState(initialMessages)
  const [input, setInput] = useState('')
  const [streaming, setStreaming] = useState(false)
  const endRef = useRef(null)
  const seededRef = useRef(false)

  useEffect(() => { setMessages(initialMessages); seededRef.current = false }, [chatId])
  useEffect(() => { endRef.current?.scrollIntoView({ behavior: 'smooth' }) }, [messages, streaming])

  const send = async (text) => {
    text = (text ?? input).trim()
    if (!text || streaming) return
    setInput(''); setStreaming(true)
    setMessages((m) => [...m, { role: 'user', content: text }, { role: 'assistant', content: '' }])
    try {
      await aiChatStream(chatId, text, (full) => {
        setMessages((m) => { const a = [...m]; a[a.length - 1] = { role: 'assistant', content: full }; return a })
      })
    } catch (e) {
      setMessages((m) => { const a = [...m]; a[a.length - 1] = { role: 'assistant', content: 'Ошибка: ' + e.message }; return a })
    } finally { setStreaming(false) }
  }

  // Авто-отправка стартового вопроса (из подсказки).
  useEffect(() => { if (seed && !seededRef.current) { seededRef.current = true; send(seed) } }, [seed, chatId])

  const onKey = (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send() } }
  const lastIdx = messages.length - 1

  return (
    <div className="aichat">
      <div className="aichat__msgs">
        {messages.map((m, i) => (
          <div key={i} className={`aimsg aimsg--${m.role}`}>
            {m.role === 'assistant' && <span className="aimsg__ico"><Sparkles size={15} /></span>}
            <div className={`aimsg__bubble ${streaming && i === lastIdx && m.role === 'assistant' ? 'aimsg__bubble--live' : ''}`}>
              {m.content || (streaming && i === lastIdx ? '' : m.content)}
            </div>
          </div>
        ))}
        <div ref={endRef} />
      </div>
      <div className="aichat__input">
        <textarea rows={1} value={input} onChange={(e) => setInput(e.target.value)} onKeyDown={onKey} placeholder="Спроси что-нибудь…" disabled={streaming} />
        <button className="btn btn-blue" onClick={() => send()} disabled={streaming || !input.trim()}><Send size={16} /></button>
      </div>
    </div>
  )
}
