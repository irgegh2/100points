import { useEffect, useRef, useState } from 'react'
import { ArrowLeft, Camera, Check, Clock3, Eye, MessageSquare, Send, ShieldCheck, VideoOff } from 'lucide-react'
import TestResult from './TestResult.jsx'
import FileAnswer from './FileAnswer.jsx'
import { isAnswered } from './HomeworkTest.jsx'
import { mockSubmit, proctoredChatSend, proctoredSessionGet, proctoredSignalList, proctoredSignalSend, proctoredStudentStart, proctoredStudentSync } from '../api.js'

const rtcConfig = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] }
const ts = (value) => new Date(String(value || '').replace(' ', 'T')).getTime()
const formatDate = (value) => value ? new Date(String(value).replace(' ', 'T')).toLocaleString('ru-RU', { dateStyle: 'long', timeStyle: 'short' }) : 'время не назначено'
const formatLeft = (ms) => {
  const total = Math.max(0, Math.ceil(ms / 1000)); const h = Math.floor(total / 3600); const m = Math.floor((total % 3600) / 60); const s = total % 60
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
}

export default function ProctoredMock({ assignmentId, onBack }) {
  const [data, setData] = useState(null)
  const [answers, setAnswers] = useState({})
  const [cur, setCur] = useState(0)
  const [active, setActive] = useState(false)
  const [permissionError, setPermissionError] = useState('')
  const [cameraState, setCameraState] = useState('off')
  const [peerState, setPeerState] = useState('new')
  const [visibility, setVisibility] = useState(document.visibilityState === 'hidden' ? 'hidden' : 'visible')
  const [focusState, setFocusState] = useState(document.hasFocus() ? 'focused' : 'blurred')
  const [messages, setMessages] = useState([])
  const [chat, setChat] = useState('')
  const [now, setNow] = useState(Date.now())
  const [submitted, setSubmitted] = useState(false)
  const [busy, setBusy] = useState(false)
  const videoRef = useRef(null); const streamRef = useRef(null); const pcRef = useRef(null)
  const signalAfter = useRef(0); const epochRef = useRef(''); const iceQueue = useRef([])
  const pointerRef = useRef({ x: 0, y: 0, w: 0, h: 0 }); const finishRef = useRef(false)
  const questionsLoadRef = useRef(false)

  useEffect(() => {
    proctoredSessionGet(assignmentId).then((d) => { setData(d); setAnswers(d.answers || {}); setMessages(d.messages || []); if (d.submission) setSubmitted(true) }).catch((e) => setPermissionError(e.message))
    return () => { streamRef.current?.getTracks().forEach((t) => t.stop()); pcRef.current?.close() }
  }, [assignmentId])
  useEffect(() => { const t = setInterval(() => setNow(Date.now()), 1000); return () => clearInterval(t) }, [])
  useEffect(() => { if (active && videoRef.current && streamRef.current) videoRef.current.srcObject = streamRef.current }, [active, now < ts(data?.assignment?.starts_at)])
  useEffect(() => {
    if (!active || !data || now < ts(data.assignment.starts_at) || data.questions?.length || questionsLoadRef.current) return
    questionsLoadRef.current = true
    proctoredSessionGet(assignmentId).then((d) => { setData(d); setAnswers(d.answers || {}); setMessages(d.messages || []) }).catch(() => { questionsLoadRef.current = false })
  }, [active, data, now, assignmentId])
  useEffect(() => {
    const vis = () => setVisibility(document.visibilityState === 'hidden' ? 'hidden' : 'visible')
    const focus = () => setFocusState('focused'); const blur = () => setFocusState('blurred')
    document.addEventListener('visibilitychange', vis); window.addEventListener('focus', focus); window.addEventListener('blur', blur)
    return () => { document.removeEventListener('visibilitychange', vis); window.removeEventListener('focus', focus); window.removeEventListener('blur', blur) }
  }, [])

  const startPeer = async (stream) => {
    pcRef.current?.close()
    const epoch = `${Date.now()}-${Math.random().toString(36).slice(2)}`; epochRef.current = epoch; iceQueue.current = []
    const pc = new RTCPeerConnection(rtcConfig); pcRef.current = pc
    const pendingLocalIce = []; let offerSent = false
    stream.getTracks().forEach((track) => pc.addTrack(track, stream))
    pc.onicecandidate = (event) => { if (event.candidate) { const candidate = event.candidate.toJSON(); if (offerSent) proctoredSignalSend(assignmentId, 'ice', { epoch, candidate }).catch(() => {}); else pendingLocalIce.push(candidate) } }
    pc.onconnectionstatechange = () => { setPeerState(pc.connectionState); if (pc.connectionState === 'failed') setPermissionError('Камера включена, но прямое соединение с наставником не установилось. Попробуй другую сеть.') }
    const offer = await pc.createOffer(); await pc.setLocalDescription(offer)
    await proctoredSignalSend(assignmentId, 'offer', { epoch, sdp: pc.localDescription })
    offerSent = true
    pendingLocalIce.forEach((candidate) => proctoredSignalSend(assignmentId, 'ice', { epoch, candidate }).catch(() => {}))
  }
  const enableCamera = async () => {
    setPermissionError('')
    if (!navigator.mediaDevices?.getUserMedia) return setPermissionError('Этот браузер не поддерживает доступ к камере. Открой страницу в актуальном Chrome, Safari или Firefox.')
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 960 }, height: { ideal: 540 }, facingMode: 'user' }, audio: false })
      streamRef.current = stream; if (videoRef.current) videoRef.current.srcObject = stream
      stream.getVideoTracks()[0].onended = () => setCameraState('ended')
      setCameraState('on'); await proctoredStudentStart(assignmentId); await startPeer(stream); setActive(true)
    } catch (e) {
      streamRef.current?.getTracks().forEach((track) => track.stop()); setActive(false)
      setCameraState(e?.name === 'NotAllowedError' ? 'blocked' : 'off')
      setPermissionError(e?.name === 'NotAllowedError' ? 'Камера запрещена. Разреши доступ в адресной строке браузера и попробуй снова.' : 'Не удалось включить камеру. Проверь, что она подключена и не занята другим приложением.')
    }
  }

  useEffect(() => {
    if (!active) return undefined
    let alive = true
    const poll = async () => {
      try {
        const d = await proctoredSignalList(assignmentId, signalAfter.current)
        for (const sig of d.signals || []) {
          signalAfter.current = Math.max(signalAfter.current, sig.id); const p = sig.payload || {}
          if (sig.type === 'answer' && p.epoch === epochRef.current && p.sdp && pcRef.current) {
            await pcRef.current.setRemoteDescription(p.sdp)
            for (const candidate of iceQueue.current) await pcRef.current.addIceCandidate(candidate).catch(() => {})
            iceQueue.current = []
          } else if (sig.type === 'ice' && p.epoch === epochRef.current && p.candidate && pcRef.current) {
            if (pcRef.current.remoteDescription) await pcRef.current.addIceCandidate(p.candidate).catch(() => {})
            else iceQueue.current.push(p.candidate)
          }
        }
      } catch { /* следующий опрос повторит */ }
    }
    poll(); const timer = setInterval(poll, 900)
    return () => { alive = false; clearInterval(timer) }
  }, [active, assignmentId])

  useEffect(() => {
    if (!active || submitted) return undefined
    let running = false
    const sync = async () => {
      if (running) return; running = true
      try {
        const p = pointerRef.current
        const d = await proctoredStudentSync({ assignment_id: assignmentId, answers, current_question: cur, cursor_x: p.x, cursor_y: p.y, viewport_w: p.w, viewport_h: p.h, visibility, focus_state: focusState, camera_state: cameraState, peer_state: peerState, after_message_id: messages.at(-1)?.id || 0 })
        if (d.messages?.length) setMessages((old) => [...old, ...d.messages.filter((m) => !old.some((x) => x.id === m.id))])
      } catch { /* не прерываем работу при единичном обрыве */ } finally { running = false }
    }
    sync(); const timer = setInterval(sync, 1000)
    return () => clearInterval(timer)
  }, [active, answers, cur, visibility, focusState, cameraState, peerState, submitted, assignmentId, messages])

  if (!data) return <div className="proctor-gate"><button className="lesson__back" onClick={onBack}><ArrowLeft size={16} /> К пробникам</button><div className="hw__empty">{permissionError || 'Загружаем назначение…'}</div></div>
  if (submitted) return <TestResult homeworkId={data.assignment.mock_id} kind="mock" onBack={onBack} allowRetry={false} />
  const a = data.assignment; const questions = data.questions || []
  const startAt = ts(a.starts_at); const endAt = startAt + a.duration * 60000
  const startedByClock = now >= startAt; const timeLeft = endAt - now
  const q = questions[cur]; const value = q ? answers[q.id] : null
  const requiredDone = questions.filter((x) => !x.optional).every((x) => isAnswered(x.type, answers[x.id]))
  const answeredCount = questions.filter((x) => isAnswered(x.type, answers[x.id])).length
  const setChoice = (v) => setAnswers((old) => ({ ...old, [q.id]: v }))
  const toggleMulti = (i) => setAnswers((old) => { const arr = Array.isArray(old[q.id]) ? old[q.id] : []; return { ...old, [q.id]: arr.includes(i) ? arr.filter((x) => x !== i) : [...arr, i] } })
  const finish = async () => {
    if (busy || finishRef.current) return; finishRef.current = true; setBusy(true)
    try {
      const payload = questions.filter((x) => isAnswered(x.type, answers[x.id])).map((x) => ({ question_id: x.id, value: answers[x.id] }))
      await mockSubmit(a.mock_id, payload, assignmentId); streamRef.current?.getTracks().forEach((t) => t.stop()); pcRef.current?.close(); setSubmitted(true)
    } catch (e) { finishRef.current = false; alert(e.message) } finally { setBusy(false) }
  }
  const send = async (e) => { e.preventDefault(); if (!chat.trim()) return; const d = await proctoredChatSend(assignmentId, chat.trim()); setMessages((old) => [...old, d.message]); setChat('') }
  const trackPointer = (e) => { const r = e.currentTarget.getBoundingClientRect(); pointerRef.current = { x: Math.round(((e.clientX - r.left) / r.width) * 10000), y: Math.round(((e.clientY - r.top) / r.height) * 10000), w: Math.round(r.width), h: Math.round(r.height) } }
  const chatPanel = <div className="proctor-session__chat"><h3><MessageSquare size={15} /> Чат с наставником</h3><div>{messages.length === 0 && <span className="proctor-session__chat-empty">Сообщений пока нет</span>}{messages.map((m) => <p className={`is-${m.sender}`} key={m.id}><b>{m.sender === 'student' ? 'Ты' : 'Наставник'}</b>{m.content}</p>)}</div><form onSubmit={send}><input value={chat} onChange={(e) => setChat(e.target.value)} placeholder="Сообщение наставнику" aria-label="Сообщение наставнику" /><button aria-label="Отправить сообщение"><Send size={15} /></button></form></div>

  if (!active) return <div className="proctor-gate">
    <button className="lesson__back" onClick={onBack}><ArrowLeft size={16} /> К пробникам</button>
    <div className="proctor-gate__card"><div className="proctor-gate__mark"><ShieldCheck size={30} /></div><span className="proctor-gate__eyebrow">Пробник с наставником</span><h2>{a.title}</h2><p className="proctor-gate__date"><Clock3 size={17} /> {formatDate(a.starts_at)} · {a.duration} минут</p>
      <div className="proctor-gate__rules"><h3>Перед подключением</h3><p><Camera size={18} /> Камера обязательна и будет видна только администратору-наблюдателю.</p><p><VideoOff size={18} /> Микрофон не запрашивается. Видео не записывается и не сохраняется на сервере.</p><p><Eye size={18} /> Во время работы фиксируются ответы, курсор внутри страницы и уход со вкладки или из окна.</p></div>
      {a.time_state === 'scheduled' && <div className="proctor-gate__wait">Подключение откроется за 15 минут до начала.</div>}
      {permissionError && <div className="proctor-gate__error">{permissionError}</div>}
      <video className="proctor-gate__preview" ref={videoRef} autoPlay playsInline muted />
      <button className="btn btn-orange btn-lg" onClick={enableCamera} disabled={!['ready','live'].includes(a.time_state)}><Camera size={18} /> Разрешить камеру и подключиться</button>
    </div>
  </div>

  if (!startedByClock) return <div className="proctor-lobby"><video ref={videoRef} autoPlay playsInline muted /><ShieldCheck size={34} /><h2>Камера подключена</h2><p>Наставник уже может подключиться. Задания откроются точно в назначенное время.</p><b>{formatLeft(startAt - now)}</b><button className="lesson__back" onClick={onBack}><ArrowLeft size={16} /> Выйти из ожидания</button></div>
  if (!q) return <div className="proctor-session">
    <header className="proctor-session__head"><div><span>Пробник под наблюдением</span><h2>{a.title}</h2></div><div className="proctor-session__timer"><Clock3 size={17} /> {formatLeft(timeLeft)}</div><div className={`proctor-session__camera is-${cameraState}`}><CircleDot /> Камера {cameraState === 'on' ? 'включена' : 'отключена'}</div></header>
    <div className="proctor-session__layout"><main className="proctor-session__work proctor-session__work--empty"><ShieldCheck size={34} /><h3>Задания пока не добавлены</h3><p>Чат с наставником уже работает — можешь написать ему справа.</p></main><aside className="proctor-session__rail"><div className="proctor-session__self"><video ref={videoRef} autoPlay playsInline muted /><span><Camera size={14} /> Ты в кадре</span></div>{chatPanel}</aside></div>
  </div>
  return <div className="proctor-session">
    <header className="proctor-session__head"><div><span>Пробник под наблюдением</span><h2>{a.title}</h2></div><div className="proctor-session__timer"><Clock3 size={17} /> {formatLeft(timeLeft)}</div><div className={`proctor-session__camera is-${cameraState}`}><CircleDot /> Камера {cameraState === 'on' ? 'включена' : 'отключена'}</div></header>
    <div className="proctor-session__layout"><main className="proctor-session__work" onPointerMove={trackPointer}>
      <div className="test__num">№ {cur + 1}{q.optional && <span className="test__opt-tag"> · необязательный</span>}</div><div className="test__question">{q.text}</div>
      {q.type === 'single' && <div className="test__opts">{q.options.map((o, i) => <button key={i} className={`test__opt ${value === i ? 'selected' : ''}`} onClick={() => setChoice(i)}><span className="test__check test__check--round">{value === i && <span className="test__dot" />}</span><span>{o}</span></button>)}</div>}
      {q.type === 'multi' && <div className="test__opts">{q.options.map((o, i) => { const on = Array.isArray(value) && value.includes(i); return <button key={i} className={`test__opt ${on ? 'selected' : ''}`} onClick={() => toggleMulti(i)}><span className="test__check">{on && <Check size={14} />}</span><span>{o}</span></button> })}</div>}
      {q.type === 'text' && <input className="test__input" value={value || ''} onChange={(e) => setChoice(e.target.value)} placeholder="Введите ответ" />}
      {q.type === 'textarea' && <textarea className="test__textarea" rows={7} value={value || ''} onChange={(e) => setChoice(e.target.value)} placeholder="Напиши развёрнутый ответ…" />}
      {q.type === 'file' && <FileAnswer questionId={q.id} limit={q.file_limit} value={value} onChange={(v) => setChoice({ ...v, limit: q.file_limit })} assignmentId={assignmentId} />}
      <div className="proctor-session__nav"><button className="btn btn-light" disabled={cur === 0} onClick={() => setCur((i) => i - 1)}>Назад</button>{cur < questions.length - 1 ? <button className="btn btn-blue" onClick={() => setCur((i) => i + 1)}>Следующее</button> : <button className="btn btn-orange" disabled={!requiredDone || busy} onClick={finish}>{busy ? 'Завершаем…' : 'Завершить пробник'}</button>}</div>
    </main><aside className="proctor-session__rail"><div className="proctor-session__self"><video ref={videoRef} autoPlay playsInline muted /><span><Camera size={14} /> Ты в кадре</span></div><div className="test__progress"><div className="test__progress-top"><b>Прогресс</b><span>{answeredCount}/{questions.length}</span></div><div className="test__progress-bar"><span style={{ width: `${questions.length ? Math.round(answeredCount / questions.length * 100) : 0}%` }} /></div></div><div className="test__sidesteps">{questions.map((item, i) => <button key={item.id} className={`test__step ${i === cur ? 'active' : ''} ${isAnswered(item.type, answers[item.id]) ? 'done' : ''}`} onClick={() => setCur(i)}>{i + 1}</button>)}</div>
      {chatPanel}
    </aside></div>
  </div>
}

function CircleDot() { return <span className="proctor-session__dot" /> }
