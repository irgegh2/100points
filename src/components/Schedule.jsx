import { useEffect, useState, useRef } from 'react'
import { ChevronDown, ChevronLeft, ChevronRight, Video, BookOpen, GraduationCap, Radio, CalendarClock, Clock3, Crown, X, Landmark, Feather, Leaf, Users, Languages } from 'lucide-react'
import { coursesList, scheduleGet } from '../api.js'
import { writeHash } from '../hashNav.js'
import { countRu } from '../ruPlural.js'
import { useLiveRefresh } from '../liveUpdates.js'

const DOW = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс']
const MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря']
const dnum = (iso) => parseInt(iso.slice(8, 10), 10)
const mon = (iso) => MONTHS[parseInt(iso.slice(5, 7), 10) - 1]
const KIND_LBL = { practice: 'Практика', theory: 'Теория', webinar: 'Вебинар', deadline: 'Дедлайн ДЗ', proctored: 'Пробник с наставником' }
const SUBJECT_ICONS = { landmark: Landmark, feather: Feather, leaf: Leaf, cap: GraduationCap, social: Users, english: Languages, book: BookOpen }

function SubjectIcon({ name }) {
  if (name === 'pi') return <span className="sched__subject-pi">π</span>
  const Icon = SUBJECT_ICONS[name] || BookOpen
  return <Icon size={20} strokeWidth={2.15} />
}

const START = 10, END = 22, HOURS = END - START   // 10:00–22:00
const HEAD = 46, MIN_ALLDAY = 38
const toMin = (t) => parseInt(t.slice(0, 2), 10) * 60 + parseInt(t.slice(3, 5), 10)
const longDate = (iso) => iso ? `${dnum(iso)} ${mon(iso)}` : 'Дата не указана'
const rawDeadline = (value) => value ? `${longDate(value.slice(0, 10))} · ${value.length > 10 ? value.slice(11, 16) : '23:59'}` : 'Не указан'
const endTime = (time, duration) => {
  if (!time) return ''
  const total = toMin(time) + (Number(duration) || 0)
  return `${String(Math.floor(total / 60) % 24).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`
}

// События, которые пересекаются по времени, получают отдельные «дорожки» и
// делят ширину дня. Алгоритм работает и для двух, и для большего числа карточек.
const layoutTimedEvents = (events, hourH) => {
  const prepared = events.map((event) => {
    const top = Math.max(0, (toMin(event.time) - START * 60) / 60 * hourH)
    const height = Math.max(30, (event.duration || 60) / 60 * hourH - 3)
    return { event, top, height, lane: 0, lanes: 1 }
  }).sort((a, b) => a.top - b.top || a.event.id - b.event.id)
  const clusters = []
  let cluster = []
  let clusterEnd = -1
  for (const item of prepared) {
    if (cluster.length && item.top >= clusterEnd) {
      clusters.push(cluster); cluster = []; clusterEnd = -1
    }
    cluster.push(item)
    clusterEnd = Math.max(clusterEnd, item.top + item.height)
  }
  if (cluster.length) clusters.push(cluster)
  for (const group of clusters) {
    const laneEnds = []
    for (const item of group) {
      let lane = laneEnds.findIndex((end) => item.top >= end)
      if (lane < 0) lane = laneEnds.length
      item.lane = lane
      laneEnds[lane] = item.top + item.height
    }
    const lanes = Math.max(1, laneEnds.length)
    for (const item of group) item.lanes = lanes
  }
  return prepared
}

export default function Schedule({ onNavigate }) {
  const [week, setWeek] = useState(null)
  const [data, setData] = useState(null)
  const [subjects, setSubjects] = useState([])
  const [hiddenSubjects, setHiddenSubjects] = useState(() => new Set())
  const [hourH, setHourH] = useState(48)
  const [pop, setPop] = useState(null)        // карточка-подсказка по наведению
  const [allDayPop, setAllDayPop] = useState(null)
  const [nowMin, setNowMin] = useState(() => { const d = new Date(); return d.getHours() * 60 + d.getMinutes() })
  const wrapRef = useRef(null)
  const hoverT = useRef(null)
  const allDayH = MIN_ALLDAY

  // линия текущего времени — двигаем раз в минуту
  useEffect(() => {
    const tick = () => { const d = new Date(); setNowMin(d.getHours() * 60 + d.getMinutes()) }
    const t = setInterval(tick, 60000)
    return () => clearInterval(t)
  }, [])

  const reload = () => scheduleGet(week).then(setData).catch(() => {})
  useEffect(() => { reload() }, [week])
  const reloadSubjects = () => coursesList().then(({ courses = [] }) => {
    const unique = new Map()
    courses.filter((course) => course.type === 'Основные').forEach((course) => {
      if (!unique.has(course.subject)) unique.set(course.subject, {
        name: course.subject,
        icon: course.icon,
        color: course.color || '#3f9bff',
      })
    })
    setSubjects([...unique.values()])
  }).catch(() => {})
  useEffect(() => { reloadSubjects() }, [])
  useLiveRefresh(() => { reload(); reloadSubjects() })

  useEffect(() => {
    const measure = () => {
      const h = wrapRef.current?.clientHeight || 0
      const avail = h - HEAD - allDayH
      if (avail > 0) setHourH(Math.max(26, avail / HOURS))
    }
    measure()
    const ro = new ResizeObserver(measure)
    if (wrapRef.current) ro.observe(wrapRef.current)
    return () => ro.disconnect()
  }, [data, allDayH])

  // На телефоне календарь шире экрана. После загрузки мягко подводим к сегодняшнему дню.
  useEffect(() => {
    const wrap = wrapRef.current
    if (!wrap || !data?.days?.length || !window.matchMedia('(max-width: 620px)').matches) return
    const todayIndex = data.days.findIndex((day) => day.today)
    if (todayIndex < 0) return
    const dayWidth = (wrap.scrollWidth - 48) / 7
    wrap.scrollLeft = Math.max(0, 48 + todayIndex * dayWidth - 18)
  }, [data])

  // Небольшая задержка не даёт подсказке мигать при случайном пересечении ячейки.
  const onEnter = (item, pointer) => {
    if (window.matchMedia('(max-width: 620px)').matches) return
    clearTimeout(hoverT.current)
    const x = Math.max(12, Math.min(pointer.clientX + 14, window.innerWidth - 374))
    const y = Math.max(12, Math.min(pointer.clientY + 14, window.innerHeight - 270))
    hoverT.current = setTimeout(() => setPop({ item, x, y }), 320)
  }
  const onLeave = () => { clearTimeout(hoverT.current); setPop((current) => current?.touch ? current : null) }
  const onTap = (item) => {
    if (!window.matchMedia('(max-width: 620px)').matches) return
    clearTimeout(hoverT.current)
    setPop((current) => current?.touch && current.item.id === item.id ? null : { item, x: 0, y: 0, touch: true })
  }
  // Клик по событию ведёт к уроку внутри курса; ссылка на трансляцию остаётся в подробностях.
  const goToEvent = (item) => {
    setPop(null)
    setAllDayPop(null)
    if (item.kind === 'deadline') { onNavigate?.('homework'); return }
    if (item.kind === 'proctored') { onNavigate?.('mock'); return }
    // Раздел «Курсы» прочитает хеш и откроет курс, а внутри — конкретный урок (если событие из программы).
    if (item.course_id) writeHash(item.lesson_id ? ['courses', item.course_id, item.lesson_id] : ['courses', item.course_id])
    onNavigate?.('courses')
  }
  const toggleSubject = (subject) => {
    setHiddenSubjects((current) => {
      const next = new Set(current)
      if (next.has(subject)) next.delete(subject)
      else next.add(subject)
      return next
    })
    setPop(null)
    setAllDayPop(null)
  }
  useEffect(() => () => clearTimeout(hoverT.current), [])
  useEffect(() => {
    if (!allDayPop) return undefined
    const close = (event) => event.key === 'Escape' && setAllDayPop(null)
    window.addEventListener('keydown', close)
    return () => window.removeEventListener('keydown', close)
  }, [allDayPop])

  const days = data?.days || Array.from({ length: 7 }, () => ({ date: '', events: [] }))
  const label = data?.days?.length ? `${dnum(days[0].date)} ${mon(days[0].date)} – ${dnum(days[6].date)} ${mon(days[6].date)}` : '…'
  const hours = Array.from({ length: HOURS + 1 }, (_, i) => START + i)
  const gridH = hourH * HOURS
  const weekHasToday = days.some((d) => d.today)
  const showNow = weekHasToday && nowMin >= START * 60 && nowMin <= END * 60
  const nowTop = (nowMin - START * 60) / 60 * hourH

  return (
    <div className="sched">
      <div className="sched__top">
        <div className="sched__subject-filters" aria-label="Фильтр расписания по предметам">
          {subjects.map((subject) => {
            const hidden = hiddenSubjects.has(subject.name)
            return (
              <button
                className={`sched__subject-toggle ${hidden ? 'is-muted' : ''}`}
                type="button"
                key={subject.name}
                style={{ '--subject-color': subject.color }}
                aria-pressed={!hidden}
                aria-label={`${subject.name}: ${hidden ? 'показать' : 'скрыть'} в расписании`}
                data-tooltip={subject.name}
                onClick={() => toggleSubject(subject.name)}
              >
                <SubjectIcon name={subject.icon} />
              </button>
            )
          })}
        </div>
        <div className="sched__current-slot">
          <button className="sched__filter" hidden={!week || weekHasToday} onClick={() => setWeek(null)}>Текущая неделя</button>
        </div>
        <div className="sched__week">
          <button onClick={() => setWeek(data?.prev)}><ChevronLeft size={16} /></button>
          <b>{label}</b>
          <button onClick={() => setWeek(data?.next)}><ChevronRight size={16} /></button>
        </div>
      </div>

      <div className="sgrid-wrap" ref={wrapRef}>
        <div className="sgrid">
          <div className="scol stime">
            <div className="scol__head" style={{ height: HEAD }} />
            <div className="sallday sallday--lbl" style={{ height: allDayH }}>весь день</div>
            <div className="shours" style={{ height: gridH }}>
              {hours.map((h) => <div className={`shour ${h === START ? 'shour--first' : ''}`} key={h} style={{ top: (h - START) * hourH }}>{h}:00</div>)}
            </div>
          </div>

          {days.map((day, di) => {
            const allDayEvents = day.events.filter((event) => event.allday && !hiddenSubjects.has(event.subject))
            return (
            <div className={`scol ${day.today ? 'scol--today' : ''}`} key={di}>
              <div className="scol__head" style={{ height: HEAD }}>{DOW[di]} {day.date && <span className={day.today ? 'today' : ''}>{dnum(day.date)}</span>}</div>
              <div className="sallday" style={{ height: allDayH }}>
                {allDayEvents.length > 0 && (
                  <button className="sallday__summary" type="button"
                    aria-expanded={allDayPop?.date === day.date}
                    onClick={() => setAllDayPop((current) => current?.date === day.date ? null : { date: day.date, items: allDayEvents })}>
                    <span><CalendarClock size={12} /> {countRu(allDayEvents.length, 'дедлайн', 'дедлайна', 'дедлайнов')}</span>
                    <ChevronDown size={12} />
                  </button>
                )}
              </div>
              <div className="sbody" style={{ height: gridH, backgroundSize: `100% ${hourH}px` }}>
                {showNow && <div className="snow" style={{ top: nowTop }}>{di === 0 && <span className="snow__dot" />}</div>}
                {layoutTimedEvents(day.events.filter((e) => !e.allday && e.time && !hiddenSubjects.has(e.subject)), hourH).map(({ event: e, top, height: h, lane, lanes }) => {
                  // Карточка подстраивается под свою высоту, чтобы контент не резало:
                  // полная (время+эфир / название / «Перейти»), компактная (без «Перейти»),
                  // однострочная (время и название в одну строку). Детали — в попапе по клику/наведению.
                  const size = h < 46 ? 'sevent--xs' : h < 68 ? 'sevent--s' : ''
                  return (
                    <div className={`sevent ${size} ${lanes > 1 ? 'sevent--split' : ''}`} key={e.id} style={{ top, height: h, borderLeftColor: e.color, left: `calc(${lane * (100 / lanes)}% + 3px)`, width: `calc(${100 / lanes}% - 6px)`, right: 'auto' }}
                      onClick={() => window.matchMedia('(max-width: 620px)').matches ? onTap(e) : goToEvent(e)}
                      onMouseEnter={(pointer) => onEnter(e, pointer)} onMouseLeave={onLeave}>
                      <div className="sevent__top">
                        <span className="sevent__time">{e.kind === 'practice' ? <BookOpen size={11} /> : e.kind === 'theory' ? <GraduationCap size={11} /> : <Video size={11} />} {e.time}</span>
                        {size === 'sevent--xs' && <span className="sevent__title sevent__title--inline">{e.title}</span>}
                      </div>
                      {size !== 'sevent--xs' && <div className="sevent__title">{e.title}</div>}
                      {size === '' && <button className="sevent__action" onClick={(click) => { click.stopPropagation(); goToEvent(e) }}>Перейти <ChevronRight size={11} /></button>}
                    </div>
                  )
                })}
              </div>
            </div>
          )})}
        </div>
      </div>

      {allDayPop && (
        <div className="sdaypop" role="presentation" onMouseDown={() => setAllDayPop(null)}>
          <section className="sdaypop__card" role="dialog" aria-modal="true" aria-labelledby="sdaypop-title" onMouseDown={(event) => event.stopPropagation()}>
            <header className="sdaypop__head">
              <div className="sdaypop__mark"><CalendarClock size={20} /></div>
              <div>
                <span>Сроки сдачи</span>
                <h2 id="sdaypop-title">{longDate(allDayPop.date)}</h2>
              </div>
              <button type="button" onClick={() => setAllDayPop(null)} aria-label="Закрыть список дедлайнов"><X size={19} /></button>
            </header>
            <div className="sdaypop__list">
              {allDayPop.items.map((item) => {
                const hasBonus = Number(item.vip_bonus_days) > 0 || Number(item.extension_days) > 0
                return (
                  <button className="sdaypop__item" type="button" key={item.id} onClick={() => goToEvent(item)}>
                    <span className="sdaypop__dot" style={{ background: item.color }} />
                    <span className="sdaypop__main">
                      <b>{item.title}</b>
                      <small>{item.subject || 'Предмет не указан'}{item.course ? ` · ${item.course}` : ''}</small>
                      {hasBonus && item.base_deadline && <em>Было до {rawDeadline(item.base_deadline)}</em>}
                    </span>
                    <span className="sdaypop__when">
                      <strong><Clock3 size={13} /> до {item.time || '23:59'}</strong>
                      {Number(item.vip_bonus_days) > 0 && <small><Crown size={12} /> VIP +1 день</small>}
                      {Number(item.extension_days) > 0 && <small>Продление +{item.extension_days} дн.</small>}
                    </span>
                    <ChevronRight className="sdaypop__arrow" size={17} />
                  </button>
                )
              })}
            </div>
          </section>
        </div>
      )}

      {/* Карточка-подсказка по наведению */}
      {pop && (
        <div className={`spop ${pop.touch ? 'is-touch' : ''}`} style={pop.touch ? undefined : { left: pop.x, top: pop.y }} onClick={() => pop.touch && setPop(null)}>
          <div className="spop__card" style={{ borderTopColor: pop.item.color }} onClick={(click) => click.stopPropagation()}>
            {pop.touch && <button className="spop__close" onClick={() => setPop(null)} aria-label="Закрыть информацию"><X size={19} /></button>}
            <div className="spop__type">{KIND_LBL[pop.item.kind] || 'Событие'}</div>
            <div className="spop__title">{pop.item.title}</div>
            <dl className="spop__details">
              <div><dt>Когда</dt><dd>{longDate(pop.item.date)}{pop.item.time ? ` · ${pop.item.time}${pop.item.duration ? `–${endTime(pop.item.time, pop.item.duration)}` : ''}` : ' · весь день'}</dd></div>
              {pop.item.duration > 0 && <div><dt>Длительность</dt><dd>{pop.item.duration} мин</dd></div>}
              <div><dt>Предмет</dt><dd><span className="spop__dot" style={{ background: pop.item.color }} />{pop.item.subject || 'Не указан'}</dd></div>
              {pop.item.course && <div><dt>Курс</dt><dd>{pop.item.course}</dd></div>}
            </dl>
            {pop.item.kind === 'deadline' && <div className="spop__note">Последний день сдачи — успей до конца дня.</div>}
            {pop.item.link && <a className="spop__go" href={pop.item.link} target="_blank" rel="noreferrer"><Radio size={13} /> Открыть трансляцию</a>}
            {pop.touch && <button className="spop__action" onClick={() => goToEvent(pop.item)}>Перейти <ChevronRight size={16} /></button>}
          </div>
        </div>
      )}
    </div>
  )
}
