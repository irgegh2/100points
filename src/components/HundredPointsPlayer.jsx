import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react'
import Hls from 'hls.js'
import {
  Play, Pause, Volume2, VolumeX, Settings, PictureInPicture, Maximize,
  Check, ChevronRight, ChevronLeft, Gauge, SlidersHorizontal, LoaderCircle,
} from 'lucide-react'
import { adminVideoPlay, videoPlay, videoProgress } from '../api.js'

const SPEEDS = [.25, .5, .75, 1, 1.25, 1.5, 1.75, 2]
const time = (value) => {
  if (!Number.isFinite(value)) return '0:00'
  const n = Math.max(0, Math.floor(value)); const h = Math.floor(n / 3600); const m = Math.floor(n % 3600 / 60); const s = n % 60
  return h ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`
}

const HundredPointsPlayer = forwardRef(function HundredPointsPlayer({ video, onComplete, admin = false, sourceOverride = null }, ref) {
  const rootRef = useRef(null)
  const videoRef = useRef(null)
  const hlsRef = useRef(null)
  const hideRef = useRef(null)
  const savedRef = useRef(0)
  const [source, setSource] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [playing, setPlaying] = useState(false)
  const [current, setCurrent] = useState(0)
  const [duration, setDuration] = useState(0)
  const [buffered, setBuffered] = useState(0)
  const [volume, setVolume] = useState(1)
  const [muted, setMuted] = useState(false)
  const [speed, setSpeed] = useState(1)
  const [controls, setControls] = useState(true)
  const [menu, setMenu] = useState(null) // null | main | speed | quality
  const [levels, setLevels] = useState([])
  const [level, setLevel] = useState(-1)

  const showControls = () => {
    setControls(true); clearTimeout(hideRef.current)
    if (playing && !menu) hideRef.current = setTimeout(() => setControls(false), 2600)
  }
  const persist = (event = 'progress') => {
    if (admin) return
    const el = videoRef.current; if (!el || !source?.id) return
    const position = Math.round(el.currentTime || 0)
    if (event === 'progress' && Math.abs(position - savedRef.current) < 10) return
    savedRef.current = position
    videoProgress({ video_id: source.id, position_sec: position, duration_sec: Math.round(el.duration || duration || 0), completed: el.duration > 0 && position >= el.duration * .95, event }).catch(() => {})
  }

  useEffect(() => {
    let alive = true; setLoading(true); setError(''); setSource(null)
    if (sourceOverride) {
      setSource(sourceOverride)
      return () => { alive = false }
    }
    ;(admin ? adminVideoPlay : videoPlay)(video.id).then((d) => { if (alive) setSource(d) }).catch((e) => { if (alive) { setError(e.message); setLoading(false) } })
    return () => { alive = false }
  }, [video.id, admin, sourceOverride])

  useEffect(() => {
    const el = videoRef.current
    if (!source || !el) return
    let hls = null
    if (source.direct_url) {
      el.src = source.direct_url
      setLevels([])
    } else if (source.hls_url && Hls.isSupported()) {
      hls = new Hls({ enableWorker: true, capLevelToPlayerSize: true, startLevel: -1, xhrSetup: (xhr) => { xhr.withCredentials = true } })
      hlsRef.current = hls; hls.loadSource(source.hls_url); hls.attachMedia(el)
      hls.on(Hls.Events.MANIFEST_PARSED, (_, data) => {
        setLevels((data.levels || []).map((x, i) => ({ index: i, height: x.height, label: `${x.height}p` })).sort((a, b) => b.height - a.height))
        setLoading(false)
      })
      hls.on(Hls.Events.ERROR, (_, data) => { if (data.fatal) { setError('Не удалось загрузить видео'); setLoading(false) } })
    } else if (source.hls_url && el.canPlayType('application/vnd.apple.mpegurl')) {
      el.src = source.hls_url; setLevels([]); setLoading(false)
    } else { setError('Этот браузер не поддерживает потоковое видео'); setLoading(false) }
    return () => { hls?.destroy(); hlsRef.current = null; el.removeAttribute('src'); el.load() }
  }, [source])

  useEffect(() => () => { clearTimeout(hideRef.current); persist('pause') }, [source])
  useEffect(() => {
    const onVis = () => { if (document.hidden) persist('pause') }
    document.addEventListener('visibilitychange', onVis)
    return () => document.removeEventListener('visibilitychange', onVis)
  })

  useImperativeHandle(ref, () => ({
    seekTo(sec) { const el = videoRef.current; if (!el) return; el.currentTime = Math.max(0, Math.min(el.duration || sec, sec)); el.play().catch(() => {}) },
    play() { return videoRef.current?.play() },
  }))

  const toggle = () => {
    const el = videoRef.current; if (!el) return
    if (el.paused) el.play().catch(() => {})
    else el.pause()
  }
  const seekBy = (delta) => { const el = videoRef.current; if (el) el.currentTime = Math.max(0, Math.min(el.duration || Infinity, el.currentTime + delta)) }
  const onKey = (e) => {
    if (['INPUT', 'SELECT', 'TEXTAREA'].includes(e.target.tagName)) return
    if (e.key === ' ' || e.key === 'k') { e.preventDefault(); toggle() }
    if (e.key === 'ArrowLeft') { e.preventDefault(); seekBy(-5) }
    if (e.key === 'ArrowRight') { e.preventDefault(); seekBy(5) }
    if (e.key.toLowerCase() === 'm') { setMuted((x) => !x) }
    if (e.key.toLowerCase() === 'f') rootRef.current?.requestFullscreen?.()
  }
  const seekPointer = (e) => {
    const el = videoRef.current; const rect = e.currentTarget.getBoundingClientRect(); if (!el || !rect.width || !el.duration) return
    el.currentTime = Math.max(0, Math.min(el.duration, (e.clientX - rect.left) / rect.width * el.duration)); setCurrent(el.currentTime)
  }
  const setRate = (next) => { const el = videoRef.current; if (el) el.playbackRate = next; setSpeed(next); setMenu(null); showControls() }
  const setQuality = (next) => { if (hlsRef.current) { hlsRef.current.currentLevel = next; hlsRef.current.nextLevel = next }; setLevel(next); setMenu(null); showControls() }
  const pip = async () => { try { if (document.pictureInPictureElement) await document.exitPictureInPicture(); else await videoRef.current?.requestPictureInPicture?.() } catch { /* браузер запретил */ } }
  const fullscreen = async () => { try { if (document.fullscreenElement) await document.exitFullscreen(); else await rootRef.current?.requestFullscreen?.() } catch { /* браузер запретил */ } }
  const currentQuality = source?.direct_url ? 'Оригинал' : level < 0 ? `Авто${levels[0]?.label ? ` ${levels[0].label}` : ''}` : levels.find((x) => x.index === level)?.label || 'Авто'

  return <div ref={rootRef} className={`hp-video ${controls || !playing ? 'controls-visible' : ''} ${playing ? 'is-playing' : 'is-paused'}`} tabIndex="0" onKeyDown={onKey} onPointerMove={showControls} onPointerLeave={() => playing && !menu && setControls(false)}>
    <video ref={videoRef} playsInline preload="metadata" poster={source?.poster_url || video.poster_url || undefined}
      muted={muted} onClick={toggle}
      onLoadedMetadata={(e) => { const el = e.currentTarget; setDuration(el.duration || source?.duration_sec || 0); const saved = Number(source?.progress?.position_sec || 0); if (saved > 0 && saved < el.duration * .95) { el.currentTime = saved; setCurrent(saved) } setLoading(false) }}
      onTimeUpdate={(e) => { const el = e.currentTarget; setCurrent(el.currentTime); if (el.duration && el.currentTime >= el.duration * .95) onComplete?.(); persist() }}
      onProgress={(e) => { const el = e.currentTarget; if (el.buffered.length && el.duration) setBuffered(el.buffered.end(el.buffered.length - 1) / el.duration * 100) }}
      onPlay={() => { setPlaying(true); persist('play'); showControls() }} onPause={() => { setPlaying(false); setControls(true); persist('pause') }}
      onEnded={() => { setPlaying(false); setControls(true); persist('ended'); onComplete?.() }} />

    {loading && <div className="hp-video__loading"><LoaderCircle size={34} /><span>Загружаем плеер…</span></div>}
    {error && <div className="hp-video__error"><b>Видео временно недоступно</b><span>{error}</span></div>}
    {!playing && !loading && !error && <button className="hp-video__center" onClick={toggle} aria-label="Смотреть"><Play size={38} fill="currentColor" /></button>}

    <div className="hp-video__shade" />
    <div className="hp-video__controls" onClick={(e) => e.stopPropagation()}>
      <div className="hp-video__timeline" onPointerDown={seekPointer} role="slider" aria-label="Позиция видео" aria-valuemin="0" aria-valuemax={duration || 0} aria-valuenow={current}>
        <span className="hp-video__buffer" style={{ width: `${buffered}%` }} /><span className="hp-video__played" style={{ width: `${duration ? current / duration * 100 : 0}%` }} /><i style={{ left: `${duration ? current / duration * 100 : 0}%` }} />
      </div>
      <div className="hp-video__barrow">
        <button className="hp-video__control" onClick={toggle} aria-label={playing ? 'Пауза' : 'Смотреть'}>{playing ? <Pause size={27} fill="currentColor" /> : <Play size={27} fill="currentColor" />}</button>
        <button className="hp-video__speed" onClick={() => setMenu(menu === 'speed' ? null : 'speed')} aria-label="Скорость">{speed === 1 ? '1x' : `${speed}x`}</button>
        <div className="hp-video__volume">
          <button className="hp-video__control" onClick={() => setMuted((x) => !x)} aria-label="Громкость">{muted || volume === 0 ? <VolumeX size={27} /> : <Volume2 size={27} fill="currentColor" />}</button>
          <label><span>Volume</span><input type="range" min="0" max="1" step="0.01" value={muted ? 0 : volume} onChange={(e) => { const v = Number(e.target.value); setVolume(v); setMuted(v === 0); if (videoRef.current) videoRef.current.volume = v }} /></label>
        </div>
        <span className="hp-video__time">{time(current)} / {time(duration)}</span>
        <span className="hp-video__spacer" />
        <button className="hp-video__control" onClick={() => setMenu(menu === 'main' ? null : 'main')} aria-label="Настройки"><Settings size={29} /></button>
        <button className="hp-video__control hp-video__pip" onClick={pip} aria-label="Картинка в картинке"><PictureInPicture size={28} /></button>
        <button className="hp-video__control" onClick={fullscreen} aria-label="Полный экран"><Maximize size={29} /></button>
      </div>
    </div>

    {menu === 'main' && <div className="hp-video__menu hp-video__menu--main">
      <button onClick={() => setMenu('speed')}><Gauge size={24} /><span>Скорость</span><em>{speed === 1 ? 'Обычная' : `${speed}x`}</em><ChevronRight size={22} /></button>
      {!source?.direct_url && <button onClick={() => setMenu('quality')}><SlidersHorizontal size={24} /><span>Качество</span><em>{currentQuality}</em><ChevronRight size={22} /></button>}
    </div>}
    {menu === 'speed' && <div className="hp-video__menu hp-video__menu--list"><div className="hp-video__menu-title"><button onClick={() => setMenu('main')}><ChevronLeft size={20} /></button><span>Скорость</span></div>{SPEEDS.map((x) => <button key={x} onClick={() => setRate(x)}><span>{x === 1 ? 'Обычная' : `${x}x`}</span>{speed === x && <Check size={20} />}</button>)}</div>}
    {menu === 'quality' && <div className="hp-video__menu hp-video__menu--list"><div className="hp-video__menu-title"><button onClick={() => setMenu('main')}><ChevronLeft size={20} /></button><span>Качество</span></div><button onClick={() => setQuality(-1)}><span>Авто</span>{level === -1 && <Check size={20} />}</button>{levels.map((x) => <button key={x.index ?? x.height} onClick={() => setQuality(x.index)}><span>{x.label}</span>{level === x.index && <Check size={20} />}</button>)}</div>}
  </div>
})

export default HundredPointsPlayer
