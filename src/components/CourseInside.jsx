import { useState, useEffect, useRef } from 'react'
import {
  ArrowLeft, Play, ChevronDown, ChevronLeft, ChevronRight,
  FileText, Download, BookOpen, Check, Lock, Unlock, Radio, CalendarDays, Clock3, X, ExternalLink, ClipboardCheck, Layers3,
} from 'lucide-react'
import { courseGet, lessonGet, lessonProgressSet } from '../api.js'
import HomeworkTest from './HomeworkTest.jsx'
import MarkdownContent from './MarkdownContent.jsx'
import { readHash, writeHash } from '../hashNav.js'
import { loadKinescopePlayerFactory } from '../kinescope.js'
import { pluralRu } from '../ruPlural.js'
import { useLiveRefresh } from '../liveUpdates.js'
import HundredPointsPlayer from './HundredPointsPlayer.jsx'
import { AUTO_SELECT_COURSE_PERIOD_BY_DATE, periodWord } from '../coursePeriod.js'

// «2026-07-21 18:00» → «21 июля в 18:00» (год пишем, только если он не текущий).
const RU_MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря']
function fmtOpensAt(v, now) {
  const m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/)
  if (!m) return String(v || '')
  const [, y, mo, d, h, mi] = m
  const year = String(now || '').slice(0, 4) === y ? '' : ` ${y}`
  return `${Number(d)} ${RU_MONTHS[Number(mo) - 1]}${year} в ${h}:${mi}`
}

// Дата из структурированного поля расписания всегда важнее старой ручной
// подписи «Эфир …»: администратор мог перенести урок, не переписывая текст.
function fmtAirAt(v) {
  const m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/)
  if (!m) return ''
  const [, , mo, d, h, mi] = m
  return `Эфир ${Number(d)} ${RU_MONTHS[Number(mo) - 1].slice(0, 3)}, ${h}:${mi}`
}

function fmtMonthRange(month) {
  const parse = (value) => {
    const m = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/)
    return m ? { y: m[1], mo: Number(m[2]), d: Number(m[3]) } : null
  }
  const start = parse(month?.starts_on)
  const end = parse(month?.ends_on)
  if (!start && !end) return 'Даты уточняются'
  if (start && end && start.y === end.y && start.mo === end.mo) return `${start.d}–${end.d} ${RU_MONTHS[start.mo - 1]} ${start.y}`
  const one = (x) => x ? `${x.d} ${RU_MONTHS[x.mo - 1]} ${x.y}` : ''
  return [one(start), one(end)].filter(Boolean).join(' — ')
}

function countdownParts(ms) {
  const total = Math.max(0, Math.ceil(ms / 1000))
  const days = Math.floor(total / 86400)
  const hours = Math.floor((total % 86400) / 3600)
  const minutes = Math.floor((total % 3600) / 60)
  const seconds = total % 60
  return [
    { key: 'days', value: days, label: pluralRu(days, 'день', 'дня', 'дней') },
    { key: 'hours', value: hours, label: pluralRu(hours, 'час', 'часа', 'часов') },
    { key: 'minutes', value: minutes, label: pluralRu(minutes, 'минута', 'минуты', 'минут') },
    { key: 'seconds', value: seconds, label: pluralRu(seconds, 'секунда', 'секунды', 'секунд') },
  ]
}

// «12:30» / «1:02:05» → секунды (для перемотки видео по тайм-коду).
function tcSeconds(t) {
  const parts = String(t || '').trim().split(':').map(Number)
  if (parts.some(Number.isNaN) || parts.length < 2 || parts.length > 3) return null
  return parts.reduce((acc, p) => acc * 60 + p, 0)
}

// Какой месяц открыть по умолчанию. Даты «с — по» задаёт админ, today — серверная дата (МСК).
// Сегодня в диапазоне месяца → он текущий; иначе последний уже начавшийся; иначе первый.
function currentMonthIdx(months, today) {
  if (!AUTO_SELECT_COURSE_PERIOD_BY_DATE || !today) return 0
  const inRange = months.findIndex((m) => m.starts_on && today >= m.starts_on && (!m.ends_on || today <= m.ends_on))
  if (inRange !== -1) return inRange
  let started = -1
  months.forEach((m, i) => { if (m.starts_on && m.starts_on <= today) started = i })
  return started !== -1 ? started : 0
}

export default function CourseInside({ courseId, onBack, onNavigate }) {
  const [data, setData] = useState(null)        // { course, months }
  const [error, setError] = useState('')
  const [monthIdx, setMonthIdx] = useState(0)
  const [open, setOpen] = useState(() => new Set()) // раскрытые блоки (по id)
  const [lessonId, setLessonId] = useState(null)
  const [lesson, setLesson] = useState(null)    // { lesson, materials, timecodes }
  const [lessonShell, setLessonShell] = useState(null) // родитель составного урока + лёгкий список занятий
  const [lessonParts, setLessonParts] = useState([])
  const [partId, setPartId] = useState(null)
  const [lessonLoading, setLessonLoading] = useState(false)
  const [tcOpen, setTcOpen] = useState(false)
  const [hwOpen, setHwOpen] = useState(null)   // открытое ДЗ ({ id, level }) или null
  const [doneIds, setDoneIds] = useState(() => new Set()) // id пройденных уроков
  const [hidePassed, setHidePassed] = useState(false)     // фильтр «Скрыть пройденное»
  const [galleryAt, setGalleryAt] = useState(null)        // индекс открытого фото галереи месяца (null — закрыта)
  const [clockNow, setClockNow] = useState(Date.now())    // синхронизируется с сервером для таймера эфира
  // Урок из хеша (#courses/11/33) — переход из расписания или восстановление после F5.
  // Читаем в инициализаторе, до эффектов: родительские эффекты могут переписать хеш.
  const [wantLessonId] = useState(() => Number(readHash()[2]) || null)
  const [wantPartId] = useState(() => Number(readHash()[3]) || null)
  // Видео: основной путь — официальный Kinescope Player API (seekTo без перезагрузки).
  // Если его скрипт не загрузился — откат на обычный iframe, где перемотка идёт
  // перезагрузкой с ?t=<сек> (n растёт, чтобы повторный клик по тому же коду сработал).
  const boxRef = useRef(null)              // контейнер плеера API
  const nativePlayerRef = useRef(null)     // собственный HLS-плеер
  const playerRef = useRef(null)           // созданный player (для seekTo)
  const [playerFailed, setPlayerFailed] = useState(false)
  const [seek, setSeek] = useState(null)   // { sec, n } — только для fallback-iframe

  // Загрузка программы курса.
  useEffect(() => {
    let alive = true
    courseGet(courseId)
      .then((d) => {
        if (!alive) return
        setData(d)
        let mi = currentMonthIdx(d.months || [], d.today)
        // Если в хеше задан урок — открываем его месяц, блок и сам урок.
        let target = null
        if (wantLessonId) {
          for (let i = 0; i < (d.months || []).length && !target; i++)
            for (const b of d.months[i].blocks || []) {
              if ((b.lessons || []).some((l) => l.id === wantLessonId)) { mi = i; target = { lesson: wantLessonId, block: b.id }; break }
            }
        }
        setMonthIdx(mi)
        const cur = d.months?.[mi]
        const firstBlock = cur?.blocks?.[0]
        if (target) {
          setOpen(new Set([target.block]))
          setLessonId(target.lesson)
        } else {
          if (firstBlock) setOpen(new Set([firstBlock.id]))
          // Курс всегда открывается с обзора месяца. Урок пользователь выбирает сам.
          setLessonId(null)
        }
        // собираем отметки «пройдено» из программы
        const done = new Set()
        for (const m of d.months || []) for (const b of m.blocks || []) for (const l of b.lessons || []) if (l.done) done.add(l.id)
        setDoneIds(done)
      })
      .catch((e) => { if (alive) setError(e.message) })
    return () => { alive = false }
  }, [courseId])

  // Статус «пройден» ставится сам: сдача обычного ДЗ, досмотр видео (≥95%) или
  // 3 секунды на текстовом уроке. Ручной отметки больше нет.
  const doneRef = useRef(doneIds)
  useEffect(() => { doneRef.current = doneIds }, [doneIds])
  const autoDone = (id) => {
    if (!id || doneRef.current.has(id)) return
    doneRef.current = new Set(doneRef.current).add(id)
    setDoneIds((s) => { const x = new Set(s); x.add(id); return x })
    lessonProgressSet(id, true).catch(() => {})
    // Для составного урока пункт программы считается пройденным, когда готовы
    // все занятия внутри. На сервере это же правило пересчитывается при загрузке.
    setLessonParts((list) => {
      if (!list.some((part) => part.id === id)) return list
      const next = list.map((part) => part.id === id ? { ...part, done: true } : part)
      if (lessonId && next.length > 0 && next.every((part) => part.done) && !doneRef.current.has(lessonId)) {
        doneRef.current = new Set(doneRef.current).add(lessonId)
        setDoneIds((s) => { const x = new Set(s); x.add(lessonId); return x })
        lessonProgressSet(lessonId, true).catch(() => {})
      }
      return next
    })
  }

  // Загрузка выбранного пункта программы. Если он составной, сначала получаем
  // лёгкий список занятий, затем — контент только выбранного занятия.
  useEffect(() => {
    if (!lessonId) { setLesson(null); setLessonShell(null); setLessonParts([]); setPartId(null); return }
    let alive = true
    setLessonLoading(true); setTcOpen(false); setSeek(null)
    lessonGet(lessonId)
      .then(async (d) => {
        if (!alive) return
        setLessonShell(d)
        const parts = d.parts || []
        setLessonParts(parts)
        if (parts.length === 0) {
          setPartId(null); setLesson(d); setLessonLoading(false); return
        }
        const preferred = parts.find((part) => part.id === wantPartId && !part.locked)
          || parts.find((part) => !part.locked)
          || null
        if (!preferred) {
          setPartId(null); setLesson(d); setLessonLoading(false); return
        }
        setPartId(preferred.id)
        try {
          const detail = await lessonGet(preferred.id)
          if (alive) setLesson(detail)
        } catch { if (alive) setLesson(null) }
        if (alive) setLessonLoading(false)
      })
      .catch(() => { if (alive) { setLesson(null); setLessonShell(null); setLessonParts([]); setPartId(null); setLessonLoading(false) } })
    return () => { alive = false }
  }, [lessonId])

  const selectPart = (part) => {
    if (!part || part.locked || part.id === partId) return
    setPartId(part.id)
    setLessonLoading(true); setTcOpen(false); setSeek(null)
    lessonGet(part.id)
      .then((d) => { setLesson(d); setLessonLoading(false) })
      .catch(() => { setLesson(null); setLessonLoading(false) })
  }

  // Обновление программы/урока из админки без сброса выбранного месяца,
  // раскрытых блоков, позиции страницы или открытого видеоплеера.
  useLiveRefresh(() => {
    courseGet(courseId).then((fresh) => {
      setData(fresh)
      setMonthIdx((idx) => Math.min(idx, Math.max(0, (fresh.months || []).length - 1)))
      const done = new Set()
      for (const m of fresh.months || []) for (const b of m.blocks || []) for (const l of b.lessons || []) if (l.done) done.add(l.id)
      setDoneIds(done)
    }).catch(() => {})
    if (lessonId && !hwOpen) {
      lessonGet(lessonId).then((shell) => {
        setLessonShell(shell)
        setLessonParts(shell.parts || [])
        const contentId = partId && (shell.parts || []).some((part) => part.id === partId && !part.locked) ? partId : lessonId
        return contentId === lessonId ? shell : lessonGet(contentId)
      }).then(setLesson).catch(() => setLesson(null))
    }
  })

  const toggle = (id) => setOpen((s) => { const x = new Set(s); x.has(id) ? x.delete(id) : x.add(id); return x })

  // При смене месяца выбранный урок тоже должен меняться. Иначе справа уже
  // август, а слева продолжает отображаться июльское занятие.
  const selectMonth = (nextIdx) => {
    const next = data?.months?.[nextIdx]
    setMonthIdx(nextIdx)
    setOpen(next?.blocks?.[0] ? new Set([next.blocks[0].id]) : new Set())
    setLessonId(null)
    setLesson(null)
    setLessonShell(null)
    setLessonParts([])
    setPartId(null)
    setHwOpen(null)
  }

  // При смене месяца закрываем галерею (индекс фото другого месяца может не существовать).
  useEffect(() => { setGalleryAt(null) }, [monthIdx])

  // Выбранный урок держим в хеше — F5 и ссылки возвращают к нему же.
  useEffect(() => {
    writeHash(lessonId ? ['courses', courseId, lessonId, ...(partId ? [partId] : [])] : ['courses', courseId])
  }, [lessonId, partId, courseId])

  // Создание плеера Kinescope API при смене видео (и пересоздание после возврата из ДЗ,
  // где вся карточка урока размонтируется). При падении скрипта — откат на iframe.
  const nativeVideo = lesson?.video || null
  const kinId = !nativeVideo && lesson?.lesson?.kinescope_id ? lesson.lesson.kinescope_id : null
  const curLessonId = lesson?.lesson?.id || null
  const airAtMs = Number(lesson?.air_at_ts || 0) * 1000
  const waitingForAir = Boolean(airAtMs && lesson?.lesson?.type !== 'text' && airAtMs > clockNow)
  const restreamExpiresMs = Number(lesson?.restream?.expires_at_ts || 0) * 1000
  const restreamVisible = Boolean(lesson?.restream?.visible && (!restreamExpiresMs || restreamExpiresMs > clockNow))
  const restreamEmbedUrl = restreamVisible ? String(lesson?.restream?.embed_url || '') : ''
  const externalVideo = lesson?.external_video || null
  const externalVideoEmbedUrl = String(externalVideo?.embed_url || '')
  // Базовое ДЗ приоритетно для любого типа занятия: пока оно не сдано,
  // просмотр видео/текста не ставит «изучено».
  const watchCounts = !(lesson?.homeworks || []).some((h) => (h.level === 'normal' || !h.level) && !h.done)
  const hasUnsolvedBasicHomework = (lesson?.homeworks || []).some((h) => (h.level === 'normal' || !h.level) && !h.done)
  useEffect(() => {
    if (!kinId || playerFailed || hwOpen || waitingForAir) return
    let dead = false
    let inst = null
    loadKinescopePlayerFactory()
      .then((factory) => {
        if (dead || !boxRef.current) return null
        boxRef.current.innerHTML = ''
        const holder = document.createElement('div')
        holder.id = 'kinescope-holder'
        boxRef.current.appendChild(holder)
        return factory.create('kinescope-holder', {
          url: `https://kinescope.io/${kinId}`,
          size: { width: '100%', height: '100%' },
        })
      })
      .then((p) => {
        if (!p) return
        if (dead) { try { p.destroy?.() } catch { /* пусто */ } return }
        inst = p
        playerRef.current = p
        // Автозачёт по досмотру: конец ролика или ≥95% длительности.
        if (watchCounts && curLessonId && p.on) {
          let durationSec = 0
          const fetchDuration = () => Promise.resolve(p.getDuration?.())
            .then((d) => { if (typeof d === 'number' && d > 0) durationSec = d })
            .catch(() => {})
          fetchDuration()
          const evs = p.Events || {}
          if (evs.Ended) p.on(evs.Ended, () => { if (!dead) autoDone(curLessonId) })
          if (evs.TimeUpdate) p.on(evs.TimeUpdate, (ev) => {
            if (dead) return
            if (!durationSec) { fetchDuration(); return }
            const cur = ev?.data?.currentTime ?? 0
            if (cur >= durationSec * 0.95) autoDone(curLessonId)
          })
        }
      })
      .catch(() => { if (!dead) setPlayerFailed(true) })
    return () => {
      dead = true
      playerRef.current = null
      try { inst?.destroy?.() } catch { /* пусто */ }
    }
  }, [kinId, curLessonId, watchCounts, playerFailed, hwOpen, waitingForAir])

  // Живой отсчёт строится по времени сервера (МСК), а не по часам устройства.
  // Когда остаётся 0 секунд, waitingForAir меняется и плеер монтируется сам.
  useEffect(() => {
    const eventTs = Number(lesson?.air_at_ts || 0)
    const serverTs = Number(lesson?.server_ts || 0)
    if (!eventTs || !serverTs) return
    const offset = serverTs * 1000 - Date.now()
    const tick = () => setClockNow(Date.now() + offset)
    tick()
    const timer = setInterval(tick, 1000)
    return () => clearInterval(timer)
  }, [lesson?.air_at_ts, lesson?.server_ts])

  // Текстовый урок (без видео): время на странице засчитывает его только тогда,
  // когда у урока нет нерешённого базового ДЗ. Практики это правило не касается.
  useEffect(() => {
    const Lx = lesson?.lesson
    if (!Lx || Lx.type !== 'text' || lesson.locked || lesson.not_open || lesson.need_lessons || hasUnsolvedBasicHomework) return
    const t = setTimeout(() => autoDone(Lx.id), 3000)
    return () => clearTimeout(t)
  }, [lesson, hasUnsolvedBasicHomework])

  // Перемотка к тайм-коду: через API мгновенно; в fallback — перезагрузка iframe с ?t=.
  const seekToSec = (sec) => {
    if (nativePlayerRef.current?.seekTo) { nativePlayerRef.current.seekTo(sec); return }
    const p = playerRef.current
    if (p?.seekTo) Promise.resolve(p.seekTo(sec)).then(() => p.play?.()).catch(() => {})
    else setSeek((s) => ({ sec, n: (s?.n || 0) + 1 }))
  }

  if (error) return (
    <div className="lesson">
      <button className="lesson__back" onClick={onBack}><ArrowLeft size={17} /> Все курсы</button>
      <div className="courses-lk__empty">Не удалось загрузить курс: {error}</div>
    </div>
  )
  if (!data) return (
    <div className="lesson">
      <button className="lesson__back" onClick={onBack}><ArrowLeft size={17} /> Все курсы</button>
      <div className="courses-lk__empty">Загружаем курс…</div>
    </div>
  )

  if (hwOpen) return (
    <HomeworkTest
      homeworkId={hwOpen.id}
      onBack={() => setHwOpen(null)}
      onSubmitted={() => {
        // Кнопка ДЗ в уроке сразу становится зелёной «Посмотреть результат».
        setLesson((d) => d ? { ...d, homeworks: (d.homeworks || []).map((h) => h.id === hwOpen.id ? { ...h, done: true } : h) } : d)
        const unresolvedBasicAfterSubmit = (lesson?.homeworks || []).some((h) => (h.level === 'normal' || !h.level) && h.id !== hwOpen.id && !h.done)
        if (hwOpen.level !== 'hard' && !unresolvedBasicAfterSubmit) autoDone(lesson?.lesson?.id || lessonId)
      }}
    />
  )

  const { course, months } = data
  const hasAccess = data.has_access
  const month = months[monthIdx]
  const periodGenitive = periodWord(month, 'gen')
  const periodPrepositional = periodWord(month, 'prep')
          const L = lesson?.lesson
  const kin = L?.video_source === 'native' ? null : L?.kinescope_id
  const ownVideo = lesson?.video
  const locked = lesson?.locked
  const notOpen = lesson?.not_open       // урок закрыт до даты opens_at
  const needLessons = lesson?.need_lessons // названия непройденных уроков-условий
  const countdown = waitingForAir ? countdownParts(airAtMs - clockNow) : []
  const monthBlocks = month?.blocks || []
  const monthLessons = monthBlocks.reduce((sum, b) => sum + (b.lessons?.length || 0), 0)
  const monthDone = monthBlocks.reduce((sum, b) => sum + (b.lessons || []).filter((l) => doneIds.has(l.id)).length, 0)
  const partsDone = lessonParts.filter((part) => part.done).length

  // Заголовки всех уроков программы (для подсказки «какие уроки пройти»).
  const titleById = {}
  for (const m of months) for (const b of m.blocks) for (const l of b.lessons) titleById[l.id] = l.title

  // Замок урока в программе: сначала «откроется по дате», потом «пройди уроки».
  const lockOf = (l) => {
    if (l.opens_at && data.now && l.opens_at > data.now)
      return { label: `Откроется ${fmtOpensAt(l.opens_at, data.now)}`, tip: 'Урок откроется автоматически в это время' }
    const left = (l.prereq_ids || []).filter((id) => !doneIds.has(id))
    if (left.length)
      return { label: `Пройти ${left.length} ${pluralRu(left.length, 'урок', 'урока', 'уроков')}`,
               tip: 'Сначала пройди:\n' + left.map((id) => `• ${titleById[id] || 'урок'}`).join('\n') }
    return null
  }

  return (
    <div className="lesson">
      <div className="lesson__topnav">
        <button className="lesson__back" onClick={onBack}><ArrowLeft size={17} /> Все курсы</button>
        {lessonId && (
          <button className="lesson-month__return" onClick={() => { setLessonId(null); setLesson(null); setLessonShell(null); setLessonParts([]); setPartId(null); setHwOpen(null) }}>
            <ChevronLeft size={16} /> К обзору {periodGenitive}
          </button>
        )}
      </div>

      <div className="lesson__grid">
        {/* левая часть — выбранный урок */}
        <div className="lesson__main">
          {!L && !lessonLoading ? (
            <section className="lesson-month" data-month={month?.name || ''} aria-labelledby="lesson-month-title">
              <div className="lesson-month__eyebrow"><CalendarDays size={16} /> Программа {periodGenitive}</div>
              <h2 id="lesson-month-title">{month?.name || `${periodWord(month, 'one', true)} пока не добавлен`}</h2>
              <p className="lesson-month__range">{fmtMonthRange(month)}</p>
              <p className="lesson-month__lead">
                Выбери раздел и урок в программе справа. Здесь можно посмотреть расписание и вернуться к нему в любой момент.
              </p>

              <div className="lesson-month__stats" aria-label={`Содержание ${periodGenitive}`}>
                <div><b>{monthBlocks.length}</b><span>{pluralRu(monthBlocks.length, 'раздел', 'раздела', 'разделов')}</span></div>
                <div><b>{monthLessons}</b><span>{pluralRu(monthLessons, 'урок', 'урока', 'уроков')}</span></div>
                <div><b>{monthDone}</b><span>пройдено</span></div>
              </div>

              <div className="lesson-month__actions">
                {month?.photos?.length > 0 && (
                  <button className="btn btn-orange btn-lg lesson-month__schedule" onClick={() => setGalleryAt(0)}>
                    <CalendarDays size={18} /> Посмотреть расписание
                  </button>
                )}
                {data.has_access && course.teacher_channel_url && (
                  <a className="btn btn-blue btn-lg lesson-month__channel" href={course.teacher_channel_url} target="_blank" rel="noreferrer">
                    Перейти в закрытый канал <ExternalLink size={17} />
                  </a>
                )}
                {data.has_access && (
                  <button className="btn btn-light btn-lg lesson-month__mocks" onClick={() => onNavigate?.('mock', course.subject)}>
                    <ClipboardCheck size={18} /> Перейти в пробники
                  </button>
                )}
              </div>
              {month?.photos?.length === 0 && !course.teacher_channel_url && (
                <div className="lesson-month__empty">Расписание в этом {periodPrepositional} пока не добавлено.</div>
              )}
            </section>
          ) : <>
          {lessonParts.length > 0 && (
            <nav className="lesson-parts" aria-label="Занятия внутри урока" style={{ '--lesson-accent': course.color || '#3f7be0' }}>
              <div className="lesson-parts__head">
                <div className="lesson-parts__heading">
                  <span className="lesson-parts__mark"><Layers3 size={17} /></span>
                  <div>
                    <b>{lessonShell?.lesson?.title || 'Составной урок'}</b>
                    <span>{lessonParts.length} {pluralRu(lessonParts.length, 'занятие', 'занятия', 'занятий')} внутри</span>
                  </div>
                </div>
                <span className="lesson-parts__progress">{partsDone}/{lessonParts.length}</span>
              </div>
              <div className="lesson-parts__list">
                {lessonParts.map((part, index) => {
                  const active = part.id === partId
                  const status = part.lock_type === 'date' && part.opens_at
                    ? `Откроется ${fmtOpensAt(part.opens_at, data.now)}`
                    : part.lock_type === 'prereq'
                      ? `Сначала пройти ${part.need_lessons?.length || 1} ${pluralRu(part.need_lessons?.length || 1, 'урок', 'урока', 'уроков')}`
                      : part.done ? 'Пройдено' : (part.meta || (part.has_video ? 'Видео' : 'Материалы урока'))
                  return (
                    <button
                      type="button"
                      key={part.id}
                      className={`lesson-part ${active ? 'active' : ''} ${part.locked ? 'locked' : ''} ${part.done ? 'done' : ''}`}
                      disabled={part.locked}
                      aria-current={active ? 'step' : undefined}
                      title={part.locked && part.need_lessons?.length ? `Сначала пройди: ${part.need_lessons.join(' · ')}` : undefined}
                      onClick={() => selectPart(part)}
                    >
                      <span className="lesson-part__lock" aria-hidden="true">
                        {part.locked ? <Lock size={15} /> : part.done ? <Check size={15} /> : <Unlock size={15} />}
                      </span>
                      <span className="lesson-part__number">{index + 1}</span>
                      <span className="lesson-part__copy"><b>{part.title}</b><small>{status}</small></span>
                      <ChevronRight className="lesson-part__chevron" size={17} aria-hidden="true" />
                    </button>
                  )
                })}
              </div>
            </nav>
          )}
          {locked
            ? <div className="lesson__video lesson__video--locked">
                <Lock size={34} />
                <b>Доступ к курсу пока закрыт</b>
                <span>Доступ к этому курсу выдаёт администратор.</span>
              </div>
            : notOpen
            ? <div className="lesson__video lesson__video--locked">
                <Lock size={34} />
                <b>Урок ещё закрыт</b>
                <span>Откроется {fmtOpensAt(lesson.opens_at, data.now)}</span>
              </div>
            : needLessons
            ? <div className="lesson__video lesson__video--locked">
                <Lock size={34} />
                <b>Сначала пройди {needLessons.length === 1 ? 'урок' : 'уроки'}:</b>
                <span>{needLessons.join(' · ')}</span>
              </div>
            : externalVideo
            ? externalVideo?.player?.hls_url
              ? <div className="lesson__video lesson__video--api">
                  <HundredPointsPlayer
                    ref={nativePlayerRef}
                    video={{ id: 0, title: L.title, poster_url: null }}
                    sourceOverride={externalVideo.player}
                    onComplete={() => markLessonDone(L.id)}
                  />
                </div>
              : externalVideoEmbedUrl
              ? <div className={`lesson__video lesson__video--external-stream${externalVideo.provider === 'educontent' ? ' lesson__video--educontent' : ''}`}>
                  <iframe
                    src={externalVideoEmbedUrl}
                    title={`${{ vk: 'VK Видео', rutube: 'RUTUBE', youtube: 'YouTube', webrestream: 'WebRestream', educontent: 'EduContent' }[externalVideo.provider] || 'Видео'} — урок`}
                    frameBorder="0"
                    allow="autoplay; fullscreen; picture-in-picture; encrypted-media; clipboard-write"
                    allowFullScreen
                  />
                </div>
              : <div className="lesson__video lesson__video--restream">
                  <b>Видео урока доступно</b>
                  <span>Ссылка откроется в новой вкладке.</span>
                  <a className="btn btn-orange btn-lg" href={externalVideo.url} target="_blank" rel="noreferrer">
                    Открыть видео <ExternalLink size={17} />
                  </a>
                </div>
            : restreamVisible
            ? restreamEmbedUrl
              ? <div className={`lesson__video lesson__video--external-stream${lesson.restream.provider === 'educontent' ? ' lesson__video--educontent' : ''}`}>
                  <iframe
                    src={restreamEmbedUrl}
                    title={`${{ vk: 'VK Видео', rutube: 'RUTUBE', youtube: 'YouTube', webrestream: 'WebRestream', educontent: 'EduContent' }[lesson.restream.provider] || 'Прямой эфир'} — прямой эфир`}
                    frameBorder="0"
                    allow="autoplay; fullscreen; picture-in-picture; encrypted-media; clipboard-write"
                    allowFullScreen
                  />
                </div>
              : <div className="lesson__video lesson__video--restream">
                <div className="lesson-restream__signal"><i aria-hidden="true" /> Прямой эфир</div>
                <b>Вебинар уже доступен</b>
                <span>Трансляция откроется в новой вкладке.</span>
                <a className="btn btn-orange btn-lg" href={lesson.restream.url} target="_blank" rel="noreferrer">
                  Открыть эфир <ExternalLink size={17} />
                </a>
              </div>
            : waitingForAir
            ? <div className="lesson__video lesson__video--countdown" aria-label={`До начала вебинара ${countdown.map((part) => `${part.value} ${part.label}`).join(' ')}`}>
                <div className="lesson-countdown__signal"><i aria-hidden="true" /> Вебинар начнётся</div>
                <div className="lesson-countdown__title">До начала осталось</div>
                <div className="lesson-countdown__date"><CalendarDays size={15} /> {fmtOpensAt(L.air_at, data.now)}</div>
                <div className="lesson-countdown__clock" aria-hidden="true">
                  {countdown.map((part) => (
                    <div className="lesson-countdown__unit" key={part.key}>
                      <b>{String(part.value).padStart(2, '0')}</b>
                      <span>{part.label}</span>
                    </div>
                  ))}
                </div>
                <div className="lesson-countdown__note"><Clock3 size={14} /> Плеер появится здесь автоматически</div>
              </div>
            : L?.type === 'text'
            ? null
            : ownVideo
              ? <HundredPointsPlayer ref={nativePlayerRef} video={ownVideo} onComplete={() => watchCounts && autoDone(curLessonId)} />
            : kin
              ? (playerFailed
                  ? <iframe className="lesson__video" title={L.title} key={seek ? `s${seek.n}` : 'v'}
                            src={`https://kinescope.io/embed/${kin}${seek ? `?t=${seek.sec}&autoplay=1` : ''}`}
                            frameBorder="0" allow="autoplay; fullscreen; picture-in-picture; encrypted-media; gyroscope; accelerometer; clipboard-write; screen-wake-lock" allowFullScreen />
                  : <div className="lesson__video lesson__video--api" ref={boxRef} />)
              : <div className="lesson__video lesson__video--empty">
                  <Play size={30} />
                  <b>{lessonLoading ? 'Загрузка…' : L ? 'Видео скоро появится' : 'Выберите урок в программе'}</b>
                  {L && <span>Урок доступен — смотри материалы и тест ниже.</span>}
                </div>}

          {!locked && lesson?.homeworks?.length > 0 && (
            <div className="lesson__hw">
              {lesson.homeworks.map((hw) => {
                // Кнопка в уроке подписывается ТОЛЬКО уровнем (селект в админке);
                // название ДЗ (текстовое поле) видно в разделе «Домашка» и при прохождении.
                const lvl = hw.level === 'hard' ? 'продвинутое' : hw.level === 'medium' ? 'среднее' : 'базовое'
                return (
                  <button
                    key={hw.id}
                    className={`btn btn-lg btn-block ${hw.done ? 'btn-green' : hw.level === 'hard' ? 'btn-purple' : hw.level === 'medium' ? 'btn-orange' : 'btn-blue'}`}
                    onClick={() => setHwOpen(hw)}
                  >
                    {hw.locked && <Lock size={15} />}
                    {hw.done ? <><Check size={16} /> Посмотреть результат — {lvl} ДЗ</> : <>Решить {lvl} ДЗ</>}
                  </button>
                )
              })}
            </div>
          )}

          <div className="lesson__card">
            <span className="ccourse__tag">{L?.subject || course.subject}</span>
            <h2 className="lesson__title">{lessonLoading ? 'Загрузка…' : (L?.title || 'Выберите урок в программе')}</h2>

            {L?.description && (
              <div className="lesson__desc"><MarkdownContent source={L.description} empty="" /></div>
            )}

            {lesson?.timecodes?.length > 0 && (
              <>
                <button className="lesson__timecodes" onClick={() => setTcOpen((v) => !v)}>
                  Таймкоды <ChevronDown size={16} style={{ transform: tcOpen ? 'rotate(180deg)' : 'none', transition: 'transform .15s' }} />
                </button>
                {tcOpen && (
                  <div className="lesson__tclist">
                    {lesson.timecodes.map((tc) => {
                      const sec = (kin || ownVideo) ? tcSeconds(tc.t) : null
                      return sec != null
                        ? <button className="lesson__tc lesson__tc--link" key={tc.id} title="Перемотать видео к этому моменту"
                                  onClick={() => seekToSec(sec)}>
                            <b><Play size={11} /> {tc.t}</b><span>{tc.label}</span>
                          </button>
                        : <div className="lesson__tc" key={tc.id}><b>{tc.t}</b><span>{tc.label}</span></div>
                    })}
                  </div>
                )}
              </>
            )}

            {lesson?.materials?.length > 0 && (
              <>
                <h4 className="lesson__mat-head">Материалы по уроку</h4>
                <div className="lesson__materials">
                  {lesson.materials.map((m) => (
                    <div className="lfile" key={m.id}>
                      <span className="lfile__ico"><FileText size={18} /></span>
                      <div className="lfile__info"><b>{m.name}</b><span>{m.size}</span></div>
                      {m.url
                        ? <a className="lfile__dl" href={m.url} target="_blank" rel="noreferrer"><Download size={14} /> Скачать</a>
                        : <button className="lfile__dl"><Download size={14} /> Скачать</button>}
                    </div>
                  ))}
                </div>
              </>
            )}
          </div>
          </>}
        </div>

        {/* правая часть — программа курса */}
        <aside className="lesson__side">
          {!hasAccess && (
            <div className="lesson__lockbanner"><Lock size={14} /> Нет доступа к этому курсу — обратись к администратору</div>
          )}

          <div className="lesson__month">
            <div className="lesson__month-nav">
              <button disabled={monthIdx === 0} onClick={() => selectMonth(Math.max(0, monthIdx - 1))}><ChevronLeft size={16} /></button>
              <b>{month?.name || '—'}</b>
              <button disabled={monthIdx >= months.length - 1} onClick={() => selectMonth(Math.min(months.length - 1, monthIdx + 1))}><ChevronRight size={16} /></button>
            </div>
            <label className="lesson__toggle"><input type="checkbox" checked={hidePassed} onChange={(e) => setHidePassed(e.target.checked)} /> <span>Скрыть пройденное</span></label>
          </div>

          {(month?.blocks || []).map((b) => {
            const visible = hidePassed ? b.lessons.filter((l) => !doneIds.has(l.id)) : b.lessons
            // Порядок уроков — как в админке; в секцию объединяем только ПОДРЯД идущие
            // уроки одного вида (теория/текстовый ↔ практика/вебинар). Секций «Теория» и
            // «Практика» в блоке может быть сколько угодно.
            const groups = []
            for (const l of visible) {
              const kind = (l.type === 'theory' || l.type === 'text') ? 'theory' : 'practice'
              const last = groups[groups.length - 1]
              // Практики объединяем только при одной и той же дате эфира.
              // Иначе заголовок первой практики ошибочно подписывал все следующие.
              const scheduleKey = kind === 'theory' ? 'theory' : (l.air_at || l.air || `lesson-${l.id}`)
              if (last && last.kind === kind && last.scheduleKey === scheduleKey) last.items.push(l)
              else groups.push({ kind, scheduleKey, items: [l] })
            }
            if (hidePassed && groups.length === 0) return null
            return (
              <div className={`lblock ${open.has(b.id) ? 'open' : ''}`} key={b.id}>
                <button className="lblock__head" onClick={() => toggle(b.id)}>
                  <span className="lblock__n">{b.n}</span>
                  <span className="lblock__title">{b.title}</span>
                  <span className="lblock__chev"><ChevronDown size={18} /></span>
                </button>

                {open.has(b.id) && (
                  <div className="lblock__body">
                    {groups.map((g, gi) => (
                      <div className="lsection" key={`${g.kind}-${g.items[0].id}-${gi}`}>
                        {g.kind === 'theory'
                          ? <div className="lsection__head theory"><BookOpen size={15} /> Теория</div>
                          : <div className="lsection__head practice"><Radio size={15} /> Практика <span>{fmtAirAt(g.items[0].air_at) || g.items[0].air}</span></div>}
                        {g.items.map((t) => {
                          const lock = lockOf(t)
                          return (
                            <div className={`llesson ${t.id === lessonId ? 'active' : ''} ${lock ? 'is-locked' : ''} ${doneIds.has(t.id) ? 'is-done' : ''}`}
                                 key={t.id} onClick={lock ? undefined : () => { setPartId(null); setLessonId(t.id) }}>
                              {doneIds.has(t.id) && (
                                <span className="llesson__done on llesson__done--auto" title="Пройдено"><Check size={13} /></span>
                              )}
                              <div className="llesson__info"><b>{t.title}</b>{g.kind === 'theory' && <span>{t.meta}</span>}</div>
                              {lock
                                ? <span className="lpill locked" title={lock.tip}><Lock size={12} /> {lock.label}</span>
                                : Number(t.part_count) > 0
                                  ? <span className="lpill green"><Layers3 size={12} /> {t.part_count} {pluralRu(Number(t.part_count), 'занятие', 'занятия', 'занятий')}</span>
                                : t.type === 'text'
                                  ? <span className="lpill green"><BookOpen size={12} /> Читать</span>
                                  : (t.has_video || g.kind === 'practice')
                                    ? <span className="lpill green"><Play size={12} /> Смотреть</span>
                                    : <span className="lpill green"><Check size={13} /> Реши тест</span>}
                            </div>
                          )
                        })}
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )
          })}
        </aside>
      </div>

      {/* Галерея фото-расписания месяца */}
      {galleryAt !== null && month?.photos?.length > 0 && (
        <div className="lgallery" onClick={() => setGalleryAt(null)}>
          <button className="lgallery__close" title="Закрыть" onClick={() => setGalleryAt(null)}><X size={22} /></button>
          <div className="lgallery__body" onClick={(e) => e.stopPropagation()}>
            {month.photos.length > 1 && (
              <button className="lgallery__nav" disabled={galleryAt === 0}
                      onClick={() => setGalleryAt((i) => Math.max(0, i - 1))}><ChevronLeft size={22} /></button>
            )}
            <img className="lgallery__img" src={month.photos[galleryAt]} alt={`${month.gallery_label || 'Расписание'} — фото ${galleryAt + 1}`} />
            {month.photos.length > 1 && (
              <button className="lgallery__nav" disabled={galleryAt >= month.photos.length - 1}
                      onClick={() => setGalleryAt((i) => Math.min(month.photos.length - 1, i + 1))}><ChevronRight size={22} /></button>
            )}
          </div>
          {month.photos.length > 1 && <div className="lgallery__count">{galleryAt + 1} / {month.photos.length}</div>}
        </div>
      )}
    </div>
  )
}
