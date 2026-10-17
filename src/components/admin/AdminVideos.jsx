import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  Video, UploadCloud, Search, Link2, CheckCircle2, Clock3, AlertCircle,
  Film, HardDrive, ImagePlus, RefreshCw, Unlink, Play, Trash2, X,
} from 'lucide-react'
import {
  adminVideos, adminVideoUploadStart, adminVideoUploadFinish, adminVideoUpdate,
  adminVideoDelete, adminVideoPosterUpload, directPutWithProgress,
} from '../../api.js'
import HundredPointsPlayer from '../HundredPointsPlayer.jsx'
import { periodOptionLabel } from '../../coursePeriod.js'
import { useDangerConfirm } from './DangerConfirm.jsx'

const STATUS = {
  uploading: ['Загрузка не завершена', 'wait'], queued: ['В очереди', 'wait'],
  processing: ['Обрабатывается', 'work'], deleting: ['Удаляем', 'work'],
  ready: ['Готово', 'ready'], failed: ['Ошибка', 'error'],
}
const fmtBytes = (n) => {
  const value = Number(n || 0)
  if (value < 1024) return `${value} Б`
  const units = ['КБ', 'МБ', 'ГБ', 'ТБ']; let x = value / 1024; let i = 0
  while (x >= 1024 && i < units.length - 1) { x /= 1024; i++ }
  return `${x >= 10 ? x.toFixed(1) : x.toFixed(2)} ${units[i]}`.replace('.0 ', ' ')
}
const fmtTime = (s) => {
  const n = Math.max(0, Number(s || 0)); const h = Math.floor(n / 3600); const m = Math.floor(n % 3600 / 60); const sec = Math.floor(n % 60)
  return h ? `${h}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}` : `${m}:${String(sec).padStart(2, '0')}`
}

export default function AdminVideos() {
  const confirmDelete = useDangerConfirm()
  const [data, setData] = useState({ items: [], courses: [], months: [], lessons: [] })
  const [filters, setFilters] = useState({ course_id: '', month_id: '', q: '' })
  const [file, setFile] = useState(null)
  const [title, setTitle] = useState('')
  const [target, setTarget] = useState({ course_id: '', month_id: '', lesson_id: '' })
  const [upload, setUpload] = useState(null)
  const [error, setError] = useState('')
  const [busyId, setBusyId] = useState(0)
  const [preview, setPreview] = useState(null)
  const fileRef = useRef(null)

  const load = useCallback(async () => {
    try {
      const fresh = await adminVideos(filters)
      setData(fresh)
      setUpload((current) => {
        if (current?.stage !== 'processing') return current
        const item = fresh.items.find((video) => Number(video.id) === Number(current.videoId))
        return item && ['ready', 'failed'].includes(item.status) ? null : current
      })
    } catch (e) { setError(e.message) }
  }, [filters.course_id, filters.month_id, filters.q])
  useEffect(() => { const t = setTimeout(load, filters.q ? 260 : 0); return () => clearTimeout(t) }, [load])
  const hasJobs = data.items.some((v) => ['uploading', 'queued', 'processing'].includes(v.status) || ['Обложка в очереди', 'Создаём обложку'].includes(v.process_stage))
  useEffect(() => {
    if (!hasJobs) return
    const timer = setInterval(load, 2500)
    return () => clearInterval(timer)
  }, [hasJobs, load])

  const monthsFor = (courseId) => data.months.filter((m) => !courseId || Number(m.course_id) === Number(courseId))
  const lessonsFor = (courseId, monthId) => data.lessons.filter((l) => (!courseId || Number(l.course_id) === Number(courseId)) && (!monthId || Number(l.month_id) === Number(monthId)))
  const lessonLabel = (l) => `${periodOptionLabel({ kind: l.period_kind, name: l.month_name })} · раздел ${l.block_n} · ${l.title}`
  const chooseFile = (next) => { setFile(next || null); setTitle(next ? next.name.replace(/\.[^.]+$/, '') : ''); setError('') }

  const startUpload = async () => {
    if (!file) return setError('Сначала выбери видеофайл')
    setError(''); setUpload({ stage: 'reserve', percent: 0, text: 'Готовим защищённую загрузку' })
    try {
      const reserved = await adminVideoUploadStart({ name: file.name, title, size: file.size, mime: file.type, lesson_id: Number(target.lesson_id) || 0 })
      setUpload({ stage: 'upload', percent: 0, text: 'Загружаем исходник в S3', videoId: reserved.video_id })
      await directPutWithProgress(reserved.upload_url, file, (percent) => setUpload((s) => ({ ...s, percent, text: `Загружаем исходник · ${percent}%` })))
      setUpload((s) => ({ ...s, stage: 'verify', percent: 100, text: 'Проверяем целостность файла' }))
      await adminVideoUploadFinish(reserved.video_id)
      if (target.lesson_id) await adminVideoUpdate({ video_id: reserved.video_id, lesson_id: Number(target.lesson_id), activate: true, title })
      setUpload((s) => ({ ...s, stage: 'ready', percent: 100, text: 'Видео готово к просмотру в исходном качестве' }))
      setFile(null); setTitle(''); if (fileRef.current) fileRef.current.value = ''
      await load()
    } catch (e) { setError(e.message); setUpload(null) }
  }

  const attach = async (video, lessonId) => {
    setBusyId(video.id); setError('')
    try { await adminVideoUpdate({ video_id: video.id, lesson_id: Number(lessonId) || 0, activate: Boolean(lessonId), title: video.title }); await load() }
    catch (e) { setError(e.message) } finally { setBusyId(0) }
  }
  const rename = async (video, nextTitle) => {
    const clean = nextTitle.trim(); if (!clean || clean === video.title) return
    try { await adminVideoUpdate({ video_id: video.id, title: clean }); await load() } catch (e) { setError(e.message) }
  }
  const poster = async (video, nextFile) => {
    if (!nextFile) return
    setBusyId(video.id)
    try { await adminVideoPosterUpload(video.id, nextFile); await load() } catch (e) { setError(e.message) } finally { setBusyId(0) }
  }
  const remove = async (video) => {
    if (!await confirmDelete({
      title: 'Удалить видео?',
      target: `Видео «${video.title}»`,
      consequence: 'Карточка, исходный файл, обложка, подготовленные качества и история просмотра будут удалены без возможности восстановления.',
    })) return
    setBusyId(video.id); setError('')
    try { await adminVideoDelete(video.id); await load() }
    catch (e) { setError(e.message) } finally { setBusyId(0) }
  }

  const shownMonths = useMemo(() => monthsFor(filters.course_id), [data.months, filters.course_id])
  const targetMonths = useMemo(() => monthsFor(target.course_id), [data.months, target.course_id])
  const targetLessons = useMemo(() => lessonsFor(target.course_id, target.month_id), [data.lessons, target.course_id, target.month_id])

  return <div className="admin-video">
    <section className="admin-video__hero">
      <div><span className="admin-video__eyebrow"><Film size={15} /> Собственный видеосервис</span><h2>Видеотека</h2><p>Оригиналы лежат в приватном S3 и сразу после загрузки доступны ученикам без перекодирования.</p></div>
      <div className="admin-video__hero-stat"><Video size={22} /><b>{data.items.length}</b><span>роликов в выборке</span></div>
    </section>

    <section className="admin__panel admin-video__upload">
      <div className="admin__panel-head"><h2><UploadCloud size={18} /> Загрузить видео</h2><span className="admin-video__secure"><HardDrive size={14} /> напрямую в S3</span></div>
      <div className="admin-video__upload-grid">
        <button className={`admin-video__drop ${file ? 'has-file' : ''}`} onClick={() => fileRef.current?.click()}>
          {file ? <><CheckCircle2 size={28} /><b>{file.name}</b><span>{fmtBytes(file.size)} · нажми, чтобы заменить</span></> : <><UploadCloud size={30} /><b>Выбрать видео</b><span>MP4, MOV, WebM или MKV · до 20 ГБ</span></>}
        </button>
        <input ref={fileRef} type="file" accept="video/mp4,video/quicktime,video/webm,video/x-matroska,.m4v,.mkv" hidden onChange={(e) => chooseFile(e.target.files?.[0])} />
        <div className="admin-video__upload-fields">
          <label>Название<input className="admin__inp" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Название ролика" /></label>
          <div className="admin-video__selects">
            <label>Курс<select className="admin__inp" value={target.course_id} onChange={(e) => setTarget({ course_id: e.target.value, month_id: '', lesson_id: '' })}><option value="">Без курса</option>{data.courses.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}</select></label>
            <label>Период<select className="admin__inp" value={target.month_id} onChange={(e) => setTarget((x) => ({ ...x, month_id: e.target.value, lesson_id: '' }))}><option value="">Любой период</option>{targetMonths.map((m) => <option key={m.id} value={m.id}>{periodOptionLabel(m)}</option>)}</select></label>
          </div>
          <label>Сразу привязать к уроку<select className="admin__inp" value={target.lesson_id} onChange={(e) => setTarget((x) => ({ ...x, lesson_id: e.target.value }))}><option value="">Пока оставить в видеотеке</option>{targetLessons.map((l) => <option key={l.id} value={l.id}>{lessonLabel(l)}</option>)}</select></label>
          <button className="btn btn-orange btn-lg" disabled={!file || upload?.stage === 'upload' || upload?.stage === 'verify'} onClick={startUpload}><UploadCloud size={17} /> Загрузить и обработать</button>
        </div>
      </div>
      {upload && <div className={`admin-video__progress is-${upload.stage}`}><div><b>{upload.text}</b><span>{upload.stage === 'upload' ? `${upload.percent}%` : upload.stage === 'processing' ? 'прогресс появится в карточке ниже' : ''}</span></div><div className="admin-video__bar"><i style={{ width: `${upload.stage === 'upload' || upload.stage === 'verify' ? upload.percent : 100}%` }} /></div></div>}
      {error && <div className="admin__msg admin__msg--err"><AlertCircle size={15} /> {error}</div>}
    </section>

    <section className="admin__panel">
      <div className="admin-video__filters">
        <label className="admin-video__search"><Search size={17} /><input value={filters.q} onChange={(e) => setFilters((x) => ({ ...x, q: e.target.value }))} placeholder="Найти видео или урок" />{filters.q && <button onClick={() => setFilters((x) => ({ ...x, q: '' }))}><X size={15} /></button>}</label>
        <select className="admin__inp" value={filters.course_id} onChange={(e) => setFilters({ ...filters, course_id: e.target.value, month_id: '' })}><option value="">Все курсы</option>{data.courses.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}</select>
        <select className="admin__inp" value={filters.month_id} onChange={(e) => setFilters({ ...filters, month_id: e.target.value })}><option value="">Все периоды</option>{shownMonths.map((m) => <option key={m.id} value={m.id}>{periodOptionLabel(m)}</option>)}</select>
        <button className="admin__iconbtn" title="Обновить" onClick={load}><RefreshCw size={16} /></button>
      </div>

      <div className="admin-video__grid">
        {data.items.map((video) => {
          const [statusText, statusClass] = STATUS[video.status] || [video.status, 'wait']
          return <article className="admin-video__card" key={video.id}>
            <button className="admin-video__poster" disabled={video.status !== 'ready'} onClick={() => video.status === 'ready' && setPreview(video)} aria-label={video.status === 'ready' ? `Смотреть ${video.title}` : undefined}>
              {video.poster_url ? <img src={`${video.poster_url}&v=${encodeURIComponent(video.updated_at || '')}`} alt="" /> : <Film size={36} />}
              {video.status === 'ready' && <span className="admin-video__poster-play"><Play size={18} fill="currentColor" /></span>}
              <span className={`admin-video__status is-${statusClass}`}>{statusClass === 'ready' ? <CheckCircle2 size={13} /> : statusClass === 'error' ? <AlertCircle size={13} /> : <Clock3 size={13} />}{statusText}</span>
              {video.duration_sec > 0 && <span className="admin-video__duration">{fmtTime(video.duration_sec)}</span>}
            </button>
            <div className="admin-video__card-body">
              <input className="admin-video__title" defaultValue={video.title} onBlur={(e) => rename(video, e.target.value)} />
              <p>{video.course_title ? `${video.course_title} · ${periodOptionLabel({ kind: video.period_kind, name: video.month_name })}` : 'Не привязано к курсу'}</p>
              {['queued', 'processing'].includes(video.status) && <div className="admin-video__encoding"><div><span>{video.process_stage || 'Обработка'}</span><b>{video.process_percent}%</b></div><div className="admin-video__bar"><i style={{ width: `${video.process_percent}%` }} /></div></div>}
              {video.status === 'failed' && <div className="admin-video__fail">{video.error || 'Обработка остановилась'}</div>}
              <div className="admin-video__facts"><span>{fmtBytes(video.size_bytes)}</span>{video.height > 0 && <span>{video.width}×{video.height}</span>}{video.qualities?.length > 0 && <span>{video.qualities.map((q) => q.label).join(' · ')}</span>}</div>
              <label className="admin-video__link"><Link2 size={15} /><select value={video.lesson_id || ''} disabled={busyId === video.id || video.status !== 'ready'} onChange={(e) => attach(video, e.target.value)}><option value="">Без урока</option>{data.lessons.map((l) => <option key={l.id} value={l.id}>{l.course_title} · {lessonLabel(l)}</option>)}</select></label>
              <div className="admin-video__actions">
                <label className="btn btn-light btn-sm"><ImagePlus size={14} /> Обложка<input type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={(e) => { poster(video, e.target.files?.[0]); e.target.value = '' }} /></label>
                {video.lesson_id && <button className="btn btn-light btn-sm" onClick={() => attach(video, '')}><Unlink size={14} /> Отвязать</button>}
                <button className="btn admin__delbtn btn-sm" disabled={busyId === video.id || ['processing', 'deleting'].includes(video.status)} title={video.status === 'processing' ? 'Дождитесь завершения обработки' : 'Удалить видео'} onClick={() => remove(video)}><Trash2 size={14} /> Удалить</button>
              </div>
            </div>
          </article>
        })}
      </div>
      {!data.items.length && <div className="admin__empty">По этим фильтрам видео нет.</div>}
    </section>
    {preview && <div className="admin__modal admin-video__preview" onClick={() => setPreview(null)}>
      <div className="admin__modal-box admin-video__preview-box" onClick={(event) => event.stopPropagation()}>
        <div className="admin__modal-head"><div><h3>{preview.title}</h3><p>{preview.course_title ? `${preview.course_title} · ${periodOptionLabel({ kind: preview.period_kind, name: preview.month_name })}` : 'Видео из общей библиотеки'}</p></div><button className="admin__iconbtn" onClick={() => setPreview(null)} aria-label="Закрыть"><X size={18} /></button></div>
        <HundredPointsPlayer key={preview.id} video={preview} admin />
      </div>
    </div>}
  </div>
}
