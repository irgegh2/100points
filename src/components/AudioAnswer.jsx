import { useEffect, useRef, useState } from 'react'
import { Mic, Square, Trash2, UploadCloud } from 'lucide-react'
import { answerFileDelete, answerFilePending, answerFileUpload } from '../api.js'
import AudioMessage from './AudioMessage.jsx'

const ACCEPT = 'audio/mpeg,audio/mp4,audio/x-m4a,audio/ogg,audio/wav,audio/x-wav,audio/webm,audio/aac,.mp3,.m4a,.ogg,.wav,.webm,.aac'

function recordingType() {
  if (typeof MediaRecorder === 'undefined') return ''
  return ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'].find((type) => MediaRecorder.isTypeSupported?.(type)) || ''
}

function extensionFor(type) {
  if (type.includes('mp4')) return 'm4a'
  if (type.includes('ogg')) return 'ogg'
  if (type.includes('wav')) return 'wav'
  return 'webm'
}

function formatTime(seconds) {
  const value = Math.max(0, Number(seconds) || 0)
  return `${Math.floor(value / 60)}:${String(value % 60).padStart(2, '0')}`
}

export default function AudioAnswer({ questionId, value, onChange, assignmentId = null }) {
  const inputRef = useRef(null)
  const recorderRef = useRef(null)
  const streamRef = useRef(null)
  const chunksRef = useRef([])
  const recoveredRef = useRef(false)
  const aliveRef = useRef(true)
  const [busy, setBusy] = useState(false)
  const [recording, setRecording] = useState(false)
  const [seconds, setSeconds] = useState(0)
  const [error, setError] = useState('')
  const answer = value && typeof value === 'object' ? value : { files: [] }
  const file = Array.isArray(answer.files) ? answer.files[0] : null

  useEffect(() => {
    aliveRef.current = true
    return () => {
      aliveRef.current = false
      const recorder = recorderRef.current
      if (recorder?.state === 'recording') recorder.stop()
      streamRef.current?.getTracks().forEach((track) => track.stop())
    }
  }, [])

  useEffect(() => {
    if (!recording) return undefined
    const timer = setInterval(() => setSeconds((value) => value + 1), 1000)
    return () => clearInterval(timer)
  }, [recording])

  useEffect(() => {
    if (recoveredRef.current || file) return
    recoveredRef.current = true
    answerFilePending(questionId).then((data) => {
      const recovered = data.files?.[0]
      if (recovered) onChange({ files: [recovered] })
    }).catch(() => {})
  }, [questionId])

  const uploadFile = async (selected) => {
    if (!selected) return
    setBusy(true); setError('')
    try {
      const data = await answerFileUpload(questionId, selected, assignmentId)
      if (aliveRef.current) onChange({ files: [data.file] })
    } catch (e) {
      if (aliveRef.current) setError(e.message)
    } finally {
      if (aliveRef.current) setBusy(false)
    }
  }

  const start = async () => {
    setError('')
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
      inputRef.current?.click()
      return
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
      const mimeType = recordingType()
      const recorder = mimeType ? new MediaRecorder(stream, { mimeType }) : new MediaRecorder(stream)
      streamRef.current = stream
      recorderRef.current = recorder
      chunksRef.current = []
      setSeconds(0)
      recorder.ondataavailable = (event) => { if (event.data?.size) chunksRef.current.push(event.data) }
      recorder.onstop = async () => {
        stream.getTracks().forEach((track) => track.stop())
        streamRef.current = null
        if (aliveRef.current) setRecording(false)
        if (!aliveRef.current || !chunksRef.current.length) return
        const type = recorder.mimeType || mimeType || 'audio/webm'
        const blob = new Blob(chunksRef.current, { type })
        chunksRef.current = []
        const recorded = new File([blob], `Устный ответ.${extensionFor(type)}`, { type, lastModified: Date.now() })
        await uploadFile(recorded)
      }
      recorder.start(250)
      setRecording(true)
    } catch (e) {
      streamRef.current?.getTracks().forEach((track) => track.stop())
      const message = e?.name === 'NotAllowedError'
        ? 'Доступ к микрофону запрещён. Разреши его в адресной строке браузера или загрузи готовую запись.'
        : 'Не удалось включить микрофон. Проверь устройство или загрузи готовую запись.'
      setError(message)
    }
  }

  const stop = () => {
    const recorder = recorderRef.current
    if (recorder?.state === 'recording') recorder.stop()
    setRecording(false)
  }

  const remove = async () => {
    if (!file) return
    setError('')
    try {
      await answerFileDelete(file.id)
      onChange({ files: [] })
    } catch (e) { setError(e.message) }
  }

  return (
    <div className="audio-answer">
      <div className="audio-answer__intro">
        <div><Mic size={18} /><b>Запиши устный ответ</b></div>
        <span>проверит эксперт вручную</span>
      </div>

      {file ? (
        <div className="audio-answer__ready">
          <AudioMessage src={file.url} name={file.name || 'Устный ответ'} />
          <button type="button" className="audio-answer__delete" onClick={remove} aria-label="Удалить аудиоответ"><Trash2 size={17} /> Записать заново</button>
        </div>
      ) : recording ? (
        <button type="button" className="audio-answer__record is-recording" onClick={stop}>
          <span className="audio-answer__pulse" /><Square size={19} fill="currentColor" />
          <span><b>Остановить запись</b><small>{formatTime(seconds)} · идёт запись с микрофона</small></span>
        </button>
      ) : (
        <div className="audio-answer__actions">
          <button type="button" className="audio-answer__record" disabled={busy} onClick={start}>
            <Mic size={22} /><span><b>{busy ? 'Загружаем запись…' : 'Начать запись'}</b><small>Браузер попросит доступ к микрофону</small></span>
          </button>
          <button type="button" className="audio-answer__upload" disabled={busy} onClick={() => inputRef.current?.click()}>
            <UploadCloud size={19} /> Загрузить готовое аудио
          </button>
        </div>
      )}

      <input ref={inputRef} type="file" hidden accept={ACCEPT} onChange={(event) => { const selected = event.target.files?.[0]; event.target.value = ''; uploadFile(selected) }} />
      {error && <div className="file-answer__error">{error}</div>}
      <p className="test__hint">После записи обязательно прослушай ответ. Отправить пробник можно, когда у задания сохранена одна аудиозапись.</p>
    </div>
  )
}
