import { useState, useEffect } from 'react'
import {
  ArrowLeft, Play, ChevronDown, ChevronLeft, ChevronRight,
  FileText, Download, BookOpen, Check, Lock, Radio,
} from 'lucide-react'
import { courseGet, lessonGet, lessonProgressSet } from '../api.js'
import HomeworkTest from './HomeworkTest.jsx'

// Первый теоретический урок программы — его показываем слева при открытии курса.
function firstTheoryId(months) {
  for (const m of months)
    for (const b of m.blocks)
      for (const l of b.lessons)
        if (l.type === 'theory') return l.id
  return null
}

export default function CourseInside({ courseId, onBack }) {
  const [data, setData] = useState(null)        // { course, months }
  const [error, setError] = useState('')
  const [monthIdx, setMonthIdx] = useState(0)
  const [open, setOpen] = useState(() => new Set()) // раскрытые блоки (по id)
  const [lessonId, setLessonId] = useState(null)
  const [lesson, setLesson] = useState(null)    // { lesson, materials, timecodes }
  const [lessonLoading, setLessonLoading] = useState(false)
  const [tcOpen, setTcOpen] = useState(false)
  const [hwOpen, setHwOpen] = useState(null)   // id открытого ДЗ
  const [doneIds, setDoneIds] = useState(() => new Set()) // id пройденных уроков
  const [hidePassed, setHidePassed] = useState(false)     // фильтр «Скрыть пройденное»

  // Загрузка программы курса.
  useEffect(() => {
    let alive = true
    courseGet(courseId)
      .then((d) => {
        if (!alive) return
        setData(d)
        const firstBlock = d.months?.[0]?.blocks?.[0]
        if (firstBlock) setOpen(new Set([firstBlock.id]))
        setLessonId(firstTheoryId(d.months || []))
        // собираем отметки «пройдено» из программы
        const done = new Set()
        for (const m of d.months || []) for (const b of m.blocks || []) for (const l of b.lessons || []) if (l.done) done.add(l.id)
        setDoneIds(done)
      })
      .catch((e) => { if (alive) setError(e.message) })
    return () => { alive = false }
  }, [courseId])

  // Отметить урок пройденным / снять отметку (оптимистично, с откатом при ошибке).
  const toggleDone = async (e, id) => {
    e.stopPropagation()
    const next = !doneIds.has(id)
    setDoneIds((s) => { const x = new Set(s); next ? x.add(id) : x.delete(id); return x })
    try { await lessonProgressSet(id, next) }
    catch { setDoneIds((s) => { const x = new Set(s); next ? x.delete(id) : x.add(id); return x }) }
  }

  // Загрузка выбранного урока (видео + материалы + тайм-коды).
  useEffect(() => {
    if (!lessonId) { setLesson(null); return }
    let alive = true
    setLessonLoading(true); setTcOpen(false)
    lessonGet(lessonId)
      .then((d) => { if (alive) { setLesson(d); setLessonLoading(false) } })
      .catch(() => { if (alive) { setLesson(null); setLessonLoading(false) } })
    return () => { alive = false }
  }, [lessonId])

  const toggle = (id) => setOpen((s) => { const x = new Set(s); x.has(id) ? x.delete(id) : x.add(id); return x })

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

  if (hwOpen) return <HomeworkTest homeworkId={hwOpen} onBack={() => setHwOpen(null)} />

  const { course, months } = data
  const hasAccess = data.has_access
  const month = months[monthIdx]
  const L = lesson?.lesson
  const kin = L?.kinescope_id
  const locked = lesson?.locked

  return (
    <div className="lesson">
      <button className="lesson__back" onClick={onBack}><ArrowLeft size={17} /> Все курсы</button>

      <div className="lesson__grid">
        {/* левая часть — выбранный урок */}
        <div className="lesson__main">
          {locked
            ? <div className="lesson__video lesson__video--locked">
                <Lock size={34} />
                <b>Доступ к курсу пока закрыт</b>
                <span>Доступ к этому курсу выдаёт администратор.</span>
              </div>
            : kin
              ? <iframe className="lesson__video" title={L.title} src={`https://kinescope.io/embed/${kin}`}
                        frameBorder="0" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowFullScreen />
              : <div className="lesson__video lesson__video--empty">
                  <Play size={30} />
                  <b>{lessonLoading ? 'Загрузка…' : L ? 'Видео скоро появится' : 'Выберите урок в программе'}</b>
                  {L && <span>Урок доступен — смотри материалы и тест ниже.</span>}
                </div>}

          {!locked && lesson?.homeworks?.length > 0 && (
            <div className="lesson__hw">
              {lesson.homeworks.map((hw) => (
                <button
                  key={hw.id}
                  className={`btn btn-lg btn-block ${hw.level === 'hard' ? 'btn-purple' : 'btn-blue'}`}
                  onClick={() => setHwOpen(hw.id)}
                >
                  {hw.locked && <Lock size={15} />} {hw.level === 'hard' ? 'Домашка повышенной сложности' : 'Решить домашку по уроку'}
                </button>
              ))}
            </div>
          )}

          <div className="lesson__card">
            <span className="ccourse__tag">{L?.subject || course.subject}</span>
            <h2 className="lesson__title">{lessonLoading ? 'Загрузка…' : (L?.title || 'Выберите урок в программе')}</h2>

            {lesson?.timecodes?.length > 0 && (
              <>
                <button className="lesson__timecodes" onClick={() => setTcOpen((v) => !v)}>
                  Таймкоды <ChevronDown size={16} style={{ transform: tcOpen ? 'rotate(180deg)' : 'none', transition: 'transform .15s' }} />
                </button>
                {tcOpen && (
                  <div className="lesson__tclist">
                    {lesson.timecodes.map((tc) => (
                      <div className="lesson__tc" key={tc.id}><b>{tc.t}</b><span>{tc.label}</span></div>
                    ))}
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
        </div>

        {/* правая часть — программа курса */}
        <aside className="lesson__side">
          {!hasAccess && (
            <div className="lesson__lockbanner"><Lock size={14} /> Нет доступа к этому курсу — обратись к администратору</div>
          )}

          <div className="lesson__month">
            <div className="lesson__month-nav">
              <button disabled={monthIdx === 0} onClick={() => setMonthIdx((i) => Math.max(0, i - 1))}><ChevronLeft size={16} /></button>
              <b>{month?.name || '—'}</b>
              <button disabled={monthIdx >= months.length - 1} onClick={() => setMonthIdx((i) => Math.min(months.length - 1, i + 1))}><ChevronRight size={16} /></button>
            </div>
            <label className="lesson__toggle"><input type="checkbox" checked={hidePassed} onChange={(e) => setHidePassed(e.target.checked)} /> <span>Скрыть пройденное</span></label>
          </div>

          {(month?.blocks || []).map((b) => {
            const visible = hidePassed ? b.lessons.filter((l) => !doneIds.has(l.id)) : b.lessons
            const theory = visible.filter((l) => l.type === 'theory')
            const practice = visible.filter((l) => l.type !== 'theory')
            if (hidePassed && theory.length === 0 && practice.length === 0) return null
            return (
              <div className={`lblock ${open.has(b.id) ? 'open' : ''}`} key={b.id}>
                <button className="lblock__head" onClick={() => toggle(b.id)}>
                  <span className="lblock__n">{b.n}</span>
                  <span className="lblock__title">{b.title}</span>
                  <span className="lblock__chev"><ChevronDown size={18} /></span>
                </button>

                {open.has(b.id) && (
                  <div className="lblock__body">
                    {theory.length > 0 && (
                      <div className="lsection">
                        <div className="lsection__head theory"><BookOpen size={15} /> Теория <span>Раскрыть</span></div>
                        {theory.map((t) => (
                          <div className={`llesson ${t.id === lessonId ? 'active' : ''} ${doneIds.has(t.id) ? 'is-done' : ''}`} key={t.id} onClick={() => setLessonId(t.id)}>
                            {hasAccess && (
                              <button className={`llesson__done ${doneIds.has(t.id) ? 'on' : ''}`} title={doneIds.has(t.id) ? 'Пройдено — снять отметку' : 'Отметить пройденным'} onClick={(e) => toggleDone(e, t.id)}><Check size={13} /></button>
                            )}
                            <div className="llesson__info"><b>{t.title}</b><span>{t.meta}</span></div>
                            {t.duration
                              ? <span className="lpill green"><Play size={12} /> {t.duration}</span>
                              : <span className="lpill green"><Check size={13} /> Реши тест</span>}
                          </div>
                        ))}
                      </div>
                    )}

                    {practice.length > 0 && (
                      <div className="lsection">
                        <div className="lsection__head practice"><Radio size={15} /> Практика <span>{practice[0].air}</span></div>
                        {practice.map((p) => (
                          <div
                            className={`llesson ${p.id === lessonId ? 'active' : ''} ${p.locked_hint ? 'is-locked' : ''} ${doneIds.has(p.id) ? 'is-done' : ''}`}
                            key={p.id}
                            onClick={p.locked_hint ? undefined : () => setLessonId(p.id)}
                          >
                            {hasAccess && !p.locked_hint && (
                              <button className={`llesson__done ${doneIds.has(p.id) ? 'on' : ''}`} title={doneIds.has(p.id) ? 'Пройдено — снять отметку' : 'Отметить пройденным'} onClick={(e) => toggleDone(e, p.id)}><Check size={13} /></button>
                            )}
                            <div className="llesson__info"><b>{p.title}</b></div>
                            {p.locked_hint
                              ? <span className="lpill locked"><Lock size={12} /> {p.locked_hint}</span>
                              : <span className="lpill green"><Play size={12} /> Смотреть</span>}
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                )}
              </div>
            )
          })}
        </aside>
      </div>
    </div>
  )
}
