import { useEffect, useRef, useState } from 'react'
import { Pause, Play } from 'lucide-react'

function formatTime(value) {
  if (!Number.isFinite(value) || value < 0) return '0:00'
  const minutes = Math.floor(value / 60)
  const seconds = Math.floor(value % 60)
  return `${minutes}:${String(seconds).padStart(2, '0')}`
}

export default function AudioMessage({ src, name = 'Аудиозапись' }) {
  const audioRef = useRef(null)
  const [playing, setPlaying] = useState(false)
  const [current, setCurrent] = useState(0)
  const [duration, setDuration] = useState(0)

  useEffect(() => {
    const audio = audioRef.current
    if (!audio) return undefined
    const sync = () => setCurrent(audio.currentTime || 0)
    const meta = () => setDuration(Number.isFinite(audio.duration) ? audio.duration : 0)
    const stop = () => setPlaying(false)
    const start = () => setPlaying(true)
    audio.addEventListener('timeupdate', sync)
    audio.addEventListener('loadedmetadata', meta)
    audio.addEventListener('durationchange', meta)
    audio.addEventListener('ended', stop)
    audio.addEventListener('pause', stop)
    audio.addEventListener('play', start)
    return () => {
      audio.removeEventListener('timeupdate', sync)
      audio.removeEventListener('loadedmetadata', meta)
      audio.removeEventListener('durationchange', meta)
      audio.removeEventListener('ended', stop)
      audio.removeEventListener('pause', stop)
      audio.removeEventListener('play', start)
    }
  }, [src])

  const toggle = () => {
    const audio = audioRef.current
    if (!audio) return
    if (audio.paused) audio.play().catch(() => setPlaying(false))
    else audio.pause()
  }
  const seek = (event) => {
    const audio = audioRef.current
    if (!audio) return
    audio.currentTime = Number(event.target.value)
    setCurrent(audio.currentTime)
  }

  return (
    <div className={`audio-message ${playing ? 'is-playing' : ''}`}>
      <audio ref={audioRef} src={src} preload="metadata" />
      <button className="audio-message__play" type="button" onClick={toggle} aria-label={playing ? `Поставить «${name}» на паузу` : `Воспроизвести «${name}»`}>
        {playing ? <Pause size={18} fill="currentColor" /> : <Play size={18} fill="currentColor" />}
      </button>
      <div className="audio-message__body">
        <div className="audio-message__top"><time>{formatTime(current)} / {formatTime(duration)}</time></div>
        <input className="audio-message__seek" type="range" min="0" max={duration || 0} step="0.1" value={Math.min(current, duration || 0)} onChange={seek} aria-label={`Позиция воспроизведения «${name}»`} />
      </div>
    </div>
  )
}
