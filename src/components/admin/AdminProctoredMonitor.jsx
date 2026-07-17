import { useEffect, useRef, useState } from 'react'
import { ArrowLeft, Camera, Circle, Eye, MessageSquare, MousePointer2, Send, TriangleAlert } from 'lucide-react'
import { adminProctoredMonitor, proctoredChatSend, proctoredSignalList, proctoredSignalSend } from '../../api.js'

const rtcConfig = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] }
const answerText = (q, value) => {
  if (value === undefined || value === null || value === '') return 'Нет ответа'
  if (q.type === 'single') return q.options?.[Number(value)] ?? String(value)
  if (q.type === 'multi') return (Array.isArray(value) ? value : []).map((i) => q.options?.[Number(i)] ?? i).join(', ') || 'Нет ответа'
  return String(value)
}

export default function AdminProctoredMonitor({ assignment, onClose }) {
  const [data, setData] = useState(null)
  const [chat, setChat] = useState('')
  const [videoState, setVideoState] = useState('Ждём камеру ученика')
  const videoRef = useRef(null)
  const pcRef = useRef(null)
  const epochRef = useRef('')
  const signalAfter = useRef(0)
  const iceQueue = useRef([])

  useEffect(() => {
    let alive = true
    const poll = async () => {
      try { const d = await adminProctoredMonitor(assignment.id); if (alive) setData(d) } catch { /* следующий опрос повторит */ }
    }
    poll(); const timer = setInterval(poll, 1000)
    return () => { alive = false; clearInterval(timer) }
  }, [assignment.id])

  useEffect(() => {
    let alive = true
    const makePeer = (epoch) => {
      pcRef.current?.close()
      const pc = new RTCPeerConnection(rtcConfig)
      pcRef.current = pc; epochRef.current = epoch; iceQueue.current = []
      pc.ontrack = (event) => { if (videoRef.current) videoRef.current.srcObject = event.streams[0]; setVideoState('Камера подключена') }
      pc.onconnectionstatechange = () => setVideoState(pc.connectionState === 'connected' ? 'Камера подключена' : pc.connectionState === 'failed' ? 'Не удалось соединить камеру' : 'Подключаем камеру…')
      pc.onicecandidate = (event) => { if (event.candidate) proctoredSignalSend(assignment.id, 'ice', { epoch, candidate: event.candidate.toJSON() }).catch(() => {}) }
      return pc
    }
    const pollSignals = async () => {
      try {
        const d = await proctoredSignalList(assignment.id, signalAfter.current)
        for (const sig of d.signals || []) {
          signalAfter.current = Math.max(signalAfter.current, sig.id)
          const p = sig.payload || {}
          if (sig.type === 'offer' && p.epoch && p.sdp) {
            const pc = makePeer(p.epoch)
            await pc.setRemoteDescription(p.sdp)
            const answer = await pc.createAnswer(); await pc.setLocalDescription(answer)
            await proctoredSignalSend(assignment.id, 'answer', { epoch: p.epoch, sdp: pc.localDescription })
            for (const candidate of iceQueue.current) await pc.addIceCandidate(candidate).catch(() => {})
            iceQueue.current = []
          } else if (sig.type === 'ice' && p.epoch === epochRef.current && p.candidate) {
            if (pcRef.current?.remoteDescription) await pcRef.current.addIceCandidate(p.candidate).catch(() => {})
            else iceQueue.current.push(p.candidate)
          }
        }
      } catch { /* следующий опрос повторит */ }
    }
    pollSignals(); const timer = setInterval(pollSignals, 900)
    return () => { alive = false; clearInterval(timer); pcRef.current?.close() }
  }, [assignment.id])

  const send = async (e) => {
    e.preventDefault(); if (!chat.trim()) return
    await proctoredChatSend(assignment.id, chat.trim()); setChat('')
    const d = await adminProctoredMonitor(assignment.id); setData(d)
  }
  if (!data) return <div className="admin-live"><button className="lesson__back" onClick={onClose}><ArrowLeft size={16} /> К назначениям</button><div className="admin__empty">Подключаемся к сессии…</div></div>
  const p = data.presence
  const q = data.questions[p?.current_question || 0]
  const answers = p?.answers && typeof p.answers === 'object' ? p.answers : {}
  const active = p?.online === true
  return <div className="admin-live">
    <div className="admin-live__top"><button className="lesson__back" onClick={onClose}><ArrowLeft size={16} /> К назначениям</button><div><h2>{data.assignment.title}</h2><p>{data.assignment.user_name || data.assignment.user_email} · {data.assignment.starts_at?.replace('T', ' ')}</p></div><span className={`admin-live__air ${active ? 'is-live' : ''}`}><Circle size={9} fill="currentColor" /> {active ? 'Ученик онлайн' : 'Нет сигнала'}</span></div>
    <div className="admin-live__grid">
      <section className="admin-live__observe">
        <div className="admin-live__video"><video ref={videoRef} autoPlay playsInline muted /><span><Camera size={16} /> {videoState}</span></div>
        <div className="admin-live__signals">
          <span className={p?.visibility === 'hidden' ? 'is-warn' : ''}><Eye size={15} /> {p?.visibility === 'hidden' ? 'Вкладка/окно скрыты' : 'Страница видима'}</span>
          <span className={p?.focus_state === 'blurred' ? 'is-warn' : ''}><MousePointer2 size={15} /> {p?.focus_state === 'blurred' ? 'Окно не активно' : 'Окно активно'}</span>
          <span className={p?.camera_state !== 'on' ? 'is-warn' : ''}><Camera size={15} /> Камера: {p?.camera_state || 'не включена'}</span>
        </div>
        <div className="admin-live__paper">
          {q ? <><span className="admin-live__qnum">Задание {Number(p?.current_question || 0) + 1} из {data.questions.length}</span><h3>{q.text}</h3><div className={`admin-live__answer ${answers[q.id] === undefined ? 'is-empty' : ''}`}>{answerText(q, answers[q.id])}</div></> : <div className="admin__empty">Ученик ещё не открыл задание</div>}
          {p && <MousePointer2 className="admin-live__cursor" size={22} fill="currentColor" style={{ left: `${p.cursor_x / 100}%`, top: `${p.cursor_y / 100}%` }} />}
        </div>
        <div className="admin-live__answers">{data.questions.map((item, i) => <button className={i === Number(p?.current_question || 0) ? 'active' : ''} key={item.id}><b>{i + 1}</b><span>{answerText(item, answers[item.id])}</span></button>)}</div>
      </section>
      <aside className="admin-live__rail">
        <div className="admin-live__chat"><h3><MessageSquare size={16} /> Чат</h3><div className="admin-live__messages">{data.messages.map((m) => <div className={`admin-live__msg is-${m.sender}`} key={m.id}><b>{m.sender === 'admin' ? 'Вы' : 'Ученик'}</b>{m.content}</div>)}</div><form onSubmit={send}><input value={chat} onChange={(e) => setChat(e.target.value)} placeholder="Написать ученику" /><button><Send size={16} /></button></form></div>
        <div className="admin-live__events"><h3><TriangleAlert size={16} /> События</h3>{data.events.length === 0 && <p>Нарушений и отключений нет</p>}{data.events.map((e) => <div key={e.id}><b>{e.detail || e.event_type}</b><span>{String(e.created_at).slice(11, 16)}</span></div>)}</div>
      </aside>
    </div>
  </div>
}
