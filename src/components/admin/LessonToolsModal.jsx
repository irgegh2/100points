import { useEffect, useMemo, useState } from 'react'
import { ArrowRight, Check, Copy, Layers3, MoveRight, X } from 'lucide-react'
import { adminLessonToolRun, adminLessonToolsCatalog } from '../../api.js'
import { periodOptionLabel } from '../../coursePeriod.js'

const MODES = [
  { id: 'duplicate', label: 'Дублировать', icon: Copy },
  { id: 'move', label: 'Переместить', icon: MoveRight },
  { id: 'copy_fields', label: 'Перенести данные', icon: Layers3 },
]

const FIELD_GROUPS = [
  {
    title: 'Основное',
    fields: [
      ['title', 'Название'], ['type', 'Тип урока'], ['meta', 'Подпись'], ['description', 'Описание'],
    ],
  },
  {
    title: 'Видео и расписание',
    fields: [
      ['kinescope_id', 'Видео Kinescope'], ['duration', 'Длительность'], ['air', 'Подпись эфира'],
      ['air_at', 'Дата в расписании'], ['opens_at', 'Дата открытия'],
    ],
  },
  {
    title: 'Содержимое',
    fields: [
      ['materials', 'Материалы'], ['timecodes', 'Тайм-коды'], ['homeworks', 'ДЗ с вопросами'],
      ['prereqs', 'Условия открытия'],
    ],
  },
]

const id = (value) => Number(value || 0)

function findLessonPath(courses, lessonId) {
  for (const course of courses) for (const month of course.months || []) {
    for (const block of month.blocks || []) {
      const lesson = (block.lessons || []).find((item) => item.id === lessonId)
      if (lesson) return { course, month, block, lesson }
    }
  }
  return null
}

export default function LessonToolsModal({ sourceLesson, initialMode = 'duplicate', onClose, onDone }) {
  const [mode, setMode] = useState(initialMode)
  const [courses, setCourses] = useState([])
  const [courseId, setCourseId] = useState(0)
  const [monthId, setMonthId] = useState(0)
  const [blockId, setBlockId] = useState(0)
  const [lessonId, setLessonId] = useState(0)
  const [copyTitle, setCopyTitle] = useState(`${sourceLesson.title || 'Урок'} — копия`)
  const [fields, setFields] = useState([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [result, setResult] = useState(null)

  useEffect(() => {
    let alive = true
    adminLessonToolsCatalog()
      .then((data) => {
        if (!alive) return
        const list = data.courses || []
        setCourses(list)
        const source = findLessonPath(list, sourceLesson.id)
        const course = source?.course || list[0]
        const month = source?.month || course?.months?.[0]
        const block = source?.block || month?.blocks?.[0]
        setCourseId(course?.id || 0)
        setMonthId(month?.id || 0)
        setBlockId(block?.id || 0)
        const firstOther = (block?.lessons || []).find((item) => item.id !== sourceLesson.id)
        setLessonId(firstOther?.id || 0)
      })
      .catch((e) => setError(e.message))
      .finally(() => alive && setLoading(false))
    return () => { alive = false }
  }, [sourceLesson.id])

  const course = useMemo(() => courses.find((item) => item.id === courseId), [courses, courseId])
  const months = course?.months || []
  const month = months.find((item) => item.id === monthId)
  const blocks = month?.blocks || []
  const block = blocks.find((item) => item.id === blockId)
  const lessons = block?.lessons || []
  const targetLesson = lessons.find((item) => item.id === lessonId)
  const sourcePath = useMemo(() => findLessonPath(courses, sourceLesson.id), [courses, sourceLesson.id])

  const changeCourse = (value) => {
    const nextCourse = courses.find((item) => item.id === id(value))
    const nextMonth = nextCourse?.months?.[0]
    const nextBlock = nextMonth?.blocks?.[0]
    const nextLesson = (nextBlock?.lessons || []).find((item) => item.id !== sourceLesson.id)
    setCourseId(nextCourse?.id || 0); setMonthId(nextMonth?.id || 0); setBlockId(nextBlock?.id || 0); setLessonId(nextLesson?.id || 0)
  }
  const changeMonth = (value) => {
    const nextMonth = months.find((item) => item.id === id(value))
    const nextBlock = nextMonth?.blocks?.[0]
    const nextLesson = (nextBlock?.lessons || []).find((item) => item.id !== sourceLesson.id)
    setMonthId(nextMonth?.id || 0); setBlockId(nextBlock?.id || 0); setLessonId(nextLesson?.id || 0)
  }
  const changeBlock = (value) => {
    const nextBlock = blocks.find((item) => item.id === id(value))
    const nextLesson = (nextBlock?.lessons || []).find((item) => item.id !== sourceLesson.id)
    setBlockId(nextBlock?.id || 0); setLessonId(nextLesson?.id || 0)
  }

  const toggleField = (field) => setFields((list) => list.includes(field) ? list.filter((item) => item !== field) : [...list, field])
  const allFields = FIELD_GROUPS.flatMap((group) => group.fields.map(([field]) => field))
  const targetReady = mode === 'copy_fields' ? lessonId > 0 && lessonId !== sourceLesson.id : blockId > 0
  const sameBlockMove = mode === 'move' && sourcePath?.block?.id === blockId
  const canSubmit = targetReady && !sameBlockMove && (mode !== 'copy_fields' || fields.length > 0) && !busy && !result

  const submit = async () => {
    setBusy(true); setError('')
    try {
      const payload = { action: mode, source_lesson_id: sourceLesson.id }
      if (mode === 'duplicate') Object.assign(payload, { target_block_id: blockId, title: copyTitle.trim() })
      if (mode === 'move') Object.assign(payload, { target_block_id: blockId })
      if (mode === 'copy_fields') Object.assign(payload, { target_lesson_id: lessonId, fields })
      const data = await adminLessonToolRun(payload)
      setResult(data)
      onDone?.({ mode, blockId, lessonId, data })
    } catch (e) {
      setError(e.message)
    } finally {
      setBusy(false)
    }
  }

  const sourceLabel = sourcePath
    ? `${sourcePath.course.title} · ${periodOptionLabel(sourcePath.month)} · раздел ${sourcePath.block.n}`
    : 'Текущий урок'
  const destinationLabel = mode === 'copy_fields'
    ? (targetLesson?.title || 'Выберите урок')
    : (block ? `${course.title} · ${periodOptionLabel(month)} · раздел ${block.n}` : 'Выберите раздел')

  return (
    <div className="admin__modal lesson-tools" role="dialog" aria-modal="true" aria-label="Инструменты урока">
      <div className="admin__modal-box admin__modal-box--wide lesson-tools__box">
        <div className="admin__modal-head">
          <div><h3>Инструменты урока</h3><p>Источник: «{sourceLesson.title}»</p></div>
          <button className="admin__iconbtn" onClick={onClose} aria-label="Закрыть"><X size={18} /></button>
        </div>

        <div className="lesson-tools__tabs">
          {MODES.map(({ id: modeId, label, icon: Icon }) => (
            <button key={modeId} className={mode === modeId ? 'active' : ''} onClick={() => { setMode(modeId); setError(''); setResult(null) }}>
              <Icon size={16} /> {label}
            </button>
          ))}
        </div>

        <div className="lesson-tools__route">
          <div><small>ОТКУДА</small><b>{sourceLabel}</b><span>{sourceLesson.title}</span></div>
          <ArrowRight size={20} />
          <div><small>КУДА</small><b>{destinationLabel}</b><span>{mode === 'copy_fields' ? 'существующий урок' : 'место назначения'}</span></div>
        </div>

        {loading ? <div className="admin__empty">Загружаю структуру курсов…</div> : (
          <div className="lesson-tools__body">
            <div className="lesson-tools__destination">
              <label>Курс
                <select className="admin__inp" value={courseId} onChange={(e) => changeCourse(e.target.value)}>
                  {courses.map((item) => <option key={item.id} value={item.id}>{item.title}</option>)}
                </select>
              </label>
              <label>Период
                <select className="admin__inp" value={monthId} onChange={(e) => changeMonth(e.target.value)} disabled={!months.length}>
                  {months.map((item) => <option key={item.id} value={item.id}>{periodOptionLabel(item)}</option>)}
                </select>
              </label>
              <label>Раздел
                <select className="admin__inp" value={blockId} onChange={(e) => changeBlock(e.target.value)} disabled={!blocks.length}>
                  {blocks.map((item) => <option key={item.id} value={item.id}>Раздел {item.n} · {item.title}</option>)}
                </select>
              </label>
              {mode === 'copy_fields' && <label>Урок назначения
                <select className="admin__inp" value={lessonId} onChange={(e) => setLessonId(id(e.target.value))} disabled={!lessons.length}>
                  <option value="">Выберите урок</option>
                  {lessons.filter((item) => item.id !== sourceLesson.id).map((item) => <option key={item.id} value={item.id}>{item.title}</option>)}
                </select>
              </label>}
            </div>

            {mode === 'duplicate' && <div className="lesson-tools__section">
              <label>Название копии<input className="admin__inp" value={copyTitle} onChange={(e) => setCopyTitle(e.target.value)} /></label>
              <p>Будут скопированы видео, описание, даты, материалы, тайм-коды, ДЗ, вопросы и вложения. Ответы и прогресс учеников не копируются.</p>
            </div>}

            {mode === 'move' && <div className="lesson-tools__notice">
              <MoveRight size={18} /><span>Урок переедет целиком вместе с ДЗ и прогрессом учеников. При переносе в другой курс старые условия открытия будут сняты.</span>
            </div>}

            {mode === 'copy_fields' && <div className="lesson-tools__fields">
              <div className="lesson-tools__fields-head">
                <b>Что перенести</b>
                <button onClick={() => setFields(fields.length === allFields.length ? [] : allFields)}>{fields.length === allFields.length ? 'Снять всё' : 'Выбрать всё'}</button>
              </div>
              {FIELD_GROUPS.map((group) => <fieldset key={group.title}>
                <legend>{group.title}</legend>
                {group.fields.map(([field, label]) => <label key={field} className={fields.includes(field) ? 'selected' : ''}>
                  <input type="checkbox" checked={fields.includes(field)} onChange={() => toggleField(field)} />
                  <span>{label}</span>{fields.includes(field) && <Check size={14} />}
                </label>)}
              </fieldset>)}
              <p>Поля заменят данные урока назначения. Материалы, тайм-коды и ДЗ добавятся к существующим; условия открытия заменятся.</p>
            </div>}
          </div>
        )}

        {sameBlockMove && <div className="lesson-tools__error">Выберите другой раздел: урок уже находится здесь.</div>}
        {error && <div className="lesson-tools__error">{error}</div>}
        {result && <div className="lesson-tools__success"><Check size={17} />
          <span>{mode === 'duplicate' ? 'Копия урока создана.' : mode === 'move' ? 'Урок перемещён.' : 'Выбранные данные перенесены.'}{result.warning ? ` ${result.warning}` : ''}</span>
        </div>}

        <div className="lesson-tools__footer">
          <button className="btn btn-light btn-sm" onClick={onClose}>{result ? 'Готово' : 'Отмена'}</button>
          {!result && <button className="btn btn-orange btn-sm" disabled={!canSubmit} onClick={submit}>
            {busy ? 'Выполняю…' : mode === 'duplicate' ? 'Создать копию' : mode === 'move' ? 'Переместить урок' : `Перенести поля · ${fields.length}`}
          </button>}
        </div>
      </div>
    </div>
  )
}
