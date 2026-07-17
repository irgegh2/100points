import { useEffect, useState, useRef } from 'react'
import { ChevronLeft, ChevronRight, Video, BookOpen, GraduationCap, Radio, AlertCircle } from 'lucide-react'
import { scheduleGet } from '../api.js'

const DOW = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс']
const MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря']
const dnum = (iso) => parseInt(iso.slice(8, 10), 10)
const mon = (iso) => MONTHS[parseInt(iso.slice(5, 7), 10) - 1]
const KIND_LBL = { practice: 'Практика', theory: 'Теория', webinar: 'Вебинар', deadline: 'Дедлайн ДЗ', proctored: 'Пробник с наставником' }

const START = 10, END = 22, HOURS = END - START   // 10:00–22:00
const HEAD = 46, ALLDAY = 38
const toMin = (t) => parseInt(t.slice(0, 2), 10) * 60 + parseInt(t.slice(3, 5), 10)
const longDate = (iso) => iso ? `${dnum(iso)} ${mon(iso)}` : 'Дата не указана'
const endTime = (time, duration) => {
  if (!time) return ''
  const total = toMin(time) + (Number(duration) || 0)
  return `${String(Math.floor(total / 60) % 24).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`
}

export default function Schedule() {
  const [week, setWeek] = useState(null)
  const [data, setData] = useState(null)
  const [hourH, setHourH] = useState(48)
  const [pop, setPop] = useState(null)        // карточка-подсказка по наведению
  const [nowMin, setNowMin] = useState(() => { const d = new Date(); return d.getHours() * 60 + d.getMinutes() })
  const wrapRef = useRef(null)
  const hoverT = useRef(null)

  // линия текущего времени — двигаем раз в минуту
  useEffect(() => {
    const tick = () => { const d = new Date(); setNowMin(d.getHours() * 60 + d.getMinutes()) }
    const t = setInterval(tick, 60000)
    return () => clearInterval(t)
  }, [])

  useEffect(() => { scheduleGet(week).then(setData).catch(() => {}) }, [week])

  useEffect(() => {
    const measure = () => {
      const h = wrapRef.current?.clientHeight || 0
      const avail = h - HEAD - ALLDAY
      if (avail > 0) setHourH(Math.max(26, avail / HOURS))
    }
    measure()
    const ro = new ResizeObserver(measure)
    if (wrapRef.current) ro.observe(wrapRef.current)
    return () => ro.disconnect()
  }, [data])

  // Небольшая задержка не даёт подсказке мигать при случайном пересечении ячейки.
  const onEnter = (item, pointer) => {
    clearTimeout(hoverT.current)
    const x = Math.max(12, Math.min(pointer.clientX + 14, window.innerWidth - 374))
    const y = Math.max(12, Math.min(pointer.clientY + 14, window.innerHeight - 270))
    hoverT.current = setTimeout(() => setPop({ item, x, y }), 320)
  }
  const onLeave = () => { clearTimeout(hoverT.current); setPop(null) }
  useEffect(() => () => clearTimeout(hoverT.current), [])

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
        <div className="sched__week">
          <button onClick={() => setWeek(data?.prev)}><ChevronLeft size={16} /></button>
          <b>{label}</b>
          <button onClick={() => setWeek(data?.next)}><ChevronRight size={16} /></button>
        </div>
        {week && <button className="sched__filter" onClick={() => setWeek(null)}>Текущая неделя</button>}
      </div>

      <div className="sgrid-wrap" ref={wrapRef}>
        <div className="sgrid">
          <div className="scol stime">
            <div className="scol__head" style={{ height: HEAD }} />
            <div className="sallday sallday--lbl" style={{ height: ALLDAY }}>весь день</div>
            <div className="shours" style={{ height: gridH }}>
              {hours.map((h) => <div className="shour" key={h} style={{ top: (h - START) * hourH }}>{h}:00</div>)}
            </div>
          </div>

          {days.map((day, di) => (
            <div className={`scol ${day.today ? 'scol--today' : ''}`} key={di}>
              <div className="scol__head" style={{ height: HEAD }}>{DOW[di]} {day.date && <span className={day.today ? 'today' : ''}>{dnum(day.date)}</span>}</div>
              <div className="sallday" style={{ height: ALLDAY }}>
                {day.events.filter((e) => e.allday).map((e) => (
                  <div className="sallday__chip" key={e.id} onMouseEnter={(pointer) => onEnter(e, pointer)} onMouseLeave={onLeave}><AlertCircle size={11} /> {e.title}</div>
                ))}
              </div>
              <div className="sbody" style={{ height: gridH, backgroundSize: `100% ${hourH}px` }}>
                {showNow && <div className="snow" style={{ top: nowTop }}>{di === 0 && <span className="snow__dot" />}</div>}
                {day.events.filter((e) => !e.allday && e.time).map((e) => {
                  const top = Math.max(0, (toMin(e.time) - START * 60) / 60 * hourH)
                  // высоту чуть уменьшаем — зазор ~3px между событиями, идущими подряд
                  const h = Math.max(24, (e.duration || 60) / 60 * hourH - 3)
                  return (
                    <div className="sevent" key={e.id} style={{ top, height: h, borderLeftColor: e.color }}
                      onMouseEnter={(pointer) => onEnter(e, pointer)} onMouseLeave={onLeave}>
                      <div className="sevent__top">
                        <span className="sevent__time">{e.kind === 'practice' ? <BookOpen size={11} /> : e.kind === 'theory' ? <GraduationCap size={11} /> : <Video size={11} />} {e.time}</span>
                        {e.link && <a className="sevent__go" href={e.link} target="_blank" rel="noreferrer" onMouseEnter={onLeave}><Radio size={10} /> эфир</a>}
                      </div>
                      <div className="sevent__title">{e.title}</div>
                    </div>
                  )
                })}
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Карточка-подсказка по наведению */}
      {pop && (
        <div className="spop" style={{ left: pop.x, top: pop.y }}>
          <div className="spop__card" style={{ borderTopColor: pop.item.color }}>
            <div className="spop__type">{KIND_LBL[pop.item.kind] || 'Событие'}</div>
            <div className="spop__title">{pop.item.title}</div>
            <dl className="spop__details">
              <div><dt>Когда</dt><dd>{longDate(pop.item.date)}{pop.item.time ? ` · ${pop.item.time}${pop.item.duration ? `–${endTime(pop.item.time, pop.item.duration)}` : ''}` : ' · весь день'}</dd></div>
              {pop.item.duration > 0 && <div><dt>Длительность</dt><dd>{pop.item.duration} мин</dd></div>}
              <div><dt>Предмет</dt><dd><span className="spop__dot" style={{ background: pop.item.color }} />{pop.item.subject || 'Не указан'}</dd></div>
              {pop.item.course && <div><dt>Курс</dt><dd>{pop.item.course}</dd></div>}
            </dl>
            {pop.item.kind === 'deadline' && <div className="spop__note">Последний день сдачи — успей до конца дня.</div>}
            {pop.item.link && <div className="spop__go"><Radio size={13} /> Для события доступна ссылка на эфир</div>}
          </div>
        </div>
      )}
    </div>
  )
}
