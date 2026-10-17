import { useEffect, useState, useCallback, useRef } from 'react'
import {
  ArrowLeft, Plus, Trash2, Save, ChevronRight, Upload, FileText, Clock, Settings, Image, X, Lock, Copy, Layers3, Video,
} from 'lucide-react'
import { catList, catCreate, catUpdate, catDelete, materialUpload, monthPhotoUpload, adminCourseLessons, adminVideos, adminVideoUpdate } from '../../api.js'
import RichReviewEditor from './RichReviewEditor.jsx'
import { COURSE_TYPES } from './AdminCourses.jsx'
import ReorderList from './ReorderList.jsx'
import AdminQuestionsModal from './AdminQuestionsModal.jsx'
import LessonToolsModal from './LessonToolsModal.jsx'
import { useDangerConfirm } from './DangerConfirm.jsx'
import { periodOptionLabel, periodWord } from '../../coursePeriod.js'

const LESSON_TYPES = [['theory', 'Теория'], ['practice', 'Практика'], ['webinar', 'Вебинар'], ['text', 'Текстовый']]
const typeLabel = (t) => (LESSON_TYPES.find((x) => x[0] === t) || [, t])[1]

export default function AdminCourseEditor({ courseId, subjects, onBack }) {
  const confirmDelete = useDangerConfirm()
  const [meta, setMeta] = useState(null)
  const [newPeriodKind, setNewPeriodKind] = useState('month')
  const [level, setLevel] = useState('months')      // months | blocks | lessons | lesson
  const [settingsOpen, setSettingsOpen] = useState(false)

  const [months, setMonths] = useState([])
  const [blocks, setBlocks] = useState([])
  const [lessons, setLessons] = useState([])
  const [parts, setParts] = useState([])
  const [selMonth, setSelMonth] = useState(null)
  const [selBlock, setSelBlock] = useState(null)
  const [selLesson, setSelLesson] = useState(null)
  const [parentLesson, setParentLesson] = useState(null)
  const [materials, setMaterials] = useState([])
  const [timecodes, setTimecodes] = useState([])
  const [homeworks, setHomeworks] = useState([])
  const [prereqs, setPrereqs] = useState([])           // уроки-условия открытого урока
  const [courseLessons, setCourseLessons] = useState([]) // все уроки курса (для выбора условий)
  const [videoLibrary, setVideoLibrary] = useState([])
  const [qModal, setQModal] = useState(null)   // ДЗ, у которого открыт редактор вопросов
  const [lessonTool, setLessonTool] = useState(null) // { source, mode }
  // При быстром переключении месяцев ответы API иногда приходят не по порядку.
  // Счётчики не дают запоздалому июльскому ответу перезаписать открытый август.
  const blocksRequest = useRef(0)
  const lessonsRequest = useRef(0)

  // ── загрузка уровней ──
  const loadMeta = useCallback(async () => {
    const c = await catList('courses')
    const next = c.items.find((x) => x.id === courseId) || null
    setMeta(next)
    if (next) setNewPeriodKind(next.type === 'Основные' ? 'block' : 'month')
  }, [courseId])
  const loadMonths = useCallback(() => catList('months', { course_id: courseId }).then((d) => setMonths(d.items)), [courseId])
  const loadBlocks = async (monthId) => {
    const request = ++blocksRequest.current
    setBlocks([])
    const d = await catList('blocks', { month_id: monthId })
    if (request !== blocksRequest.current) return
    setBlocks((d.items || []).filter((b) => Number(b.month_id) === Number(monthId)))
  }
  const loadLessons = async (blockId) => {
    const request = ++lessonsRequest.current
    setLessons([])
    const d = await catList('lessons', { block_id: blockId })
    if (request !== lessonsRequest.current) return
    setLessons((d.items || []).filter((l) => Number(l.block_id) === Number(blockId) && !Number(l.parent_lesson_id)))
  }
  const loadExtras = (lessonId) => Promise.all([
    catList('lessons', { parent_lesson_id: lessonId }).then((d) => setParts((d.items || []).filter((l) => Number(l.parent_lesson_id) === Number(lessonId)))),
    catList('materials', { lesson_id: lessonId }).then((d) => setMaterials(d.items)),
    catList('timecodes', { lesson_id: lessonId }).then((d) => setTimecodes(d.items)),
    catList('homeworks', { lesson_id: lessonId }).then((d) => setHomeworks(d.items)),
    catList('lesson_prereqs', { lesson_id: lessonId }).then((d) => setPrereqs(d.items)),
    adminCourseLessons(courseId).then((d) => setCourseLessons(d.items)),
    adminVideos({ status: 'ready' }).then((d) => setVideoLibrary(d.items || [])),
  ])
  const loadHomeworks = (lessonId) => catList('homeworks', { lesson_id: lessonId }).then((d) => setHomeworks(d.items))

  useEffect(() => { loadMeta(); loadMonths() }, [loadMeta, loadMonths])

  // ── навигация ──
  const openMonth = (m) => {
    ++lessonsRequest.current
    setSelMonth(m); setSelBlock(null); setSelLesson(null); setParentLesson(null); setLessons([]); setParts([])
    loadBlocks(m.id).catch((e) => alert(e.message))
    setLevel('blocks')
  }
  const openBlock = (b) => {
    setSelBlock(b); setSelLesson(null); setParentLesson(null); setParts([])
    loadLessons(b.id).catch((e) => alert(e.message))
    setLevel('lessons')
  }
  const openLesson = (l) => { setParentLesson(null); setSelLesson(l); loadExtras(l.id); setLevel('lesson') }
  const openPart = (part) => {
    setParentLesson(selLesson)
    setSelLesson(part)
    loadExtras(part.id)
    setLevel('lesson')
  }
  const openParentLesson = () => {
    if (!parentLesson) return
    const parent = parentLesson
    setParentLesson(null)
    setSelLesson(parent)
    loadExtras(parent.id)
    setLevel('lesson')
  }

  // ── курс (мета) ──
  const saveMeta = async () => {
    try {
      const { item } = await catUpdate('courses', {
        id: meta.id,
        subject_id: meta.subject_id,
        title: meta.title,
        type: meta.type,
        color: meta.color,
        teacher_channel_url: meta.teacher_channel_url || '',
      })
      setMeta(item)
      setSettingsOpen(false)
    }
    catch (e) { alert(e.message) }
  }

  // ── месяцы ──
  const addMonth = () => catCreate('months', { course_id: courseId, name: newPeriodKind === 'block' ? 'Новый блок' : 'Новый месяц', kind: newPeriodKind, position: months.length }).then((d) => setMonths((list) => [...list, d.item]))
  const saveMonth = (m) => catUpdate('months', { id: m.id, name: m.name, kind: m.kind === 'block' ? 'block' : 'month', starts_on: m.starts_on || '', ends_on: m.ends_on || '', gallery_label: m.gallery_label || '' }).catch((e) => alert(e.message))
  const delMonth = async (m) => {
    const label = periodWord(m, 'one', true)
    if (!await confirmDelete({ title: `Удалить ${periodWord(m, 'one')} курса?`, target: `${label} «${m.name}»`, consequence: `Все разделы, уроки, материалы, ДЗ, вопросы и ответы учеников внутри ${periodWord(m, 'gen')} будут удалены.` })) return
    try { await catDelete('months', m.id); setMonths((list) => list.filter((x) => x.id !== m.id)) } catch (e) { alert(e.message) }
  }
  const patchMonth = (id, k, v) => setMonths((a) => a.map((m) => m.id === id ? { ...m, [k]: v } : m))

  // ── фото-расписание месяца (галерея до 10 фото) ──
  const [galleryMonth, setGalleryMonth] = useState(null)  // id месяца с открытой галереей
  const [monthPhotos, setMonthPhotos] = useState([])
  const toggleGallery = (m) => {
    if (galleryMonth === m.id) { setGalleryMonth(null); return }
    setGalleryMonth(m.id); setMonthPhotos([])
    catList('month_photos', { month_id: m.id }).then((d) => setMonthPhotos(d.items || [])).catch((e) => alert(e.message))
  }
  const uploadMonthPhoto = async (file) => {
    try { const d = await monthPhotoUpload(galleryMonth, file); setMonthPhotos((list) => [...list, d.item]) }
    catch (e) { alert(e.message) }
  }
  const delMonthPhoto = async (p) => {
    if (!await confirmDelete({ title: 'Удалить фото?', target: `Фото из расписания ${periodWord(months.find((m) => m.id === galleryMonth), 'gen')}`, consequence: 'Фото пропадёт из галереи у учеников.' })) return
    try { await catDelete('month_photos', p.id); setMonthPhotos((list) => list.filter((x) => x.id !== p.id)) } catch (e) { alert(e.message) }
  }

  // ── блоки ──
  const addBlock = () => catCreate('blocks', { course_id: courseId, month_id: selMonth.id, n: blocks.length + 1, title: 'Новый раздел', position: blocks.length }).then((d) => setBlocks((list) => [...list, d.item]))
  const saveBlock = (b) => catUpdate('blocks', { id: b.id, n: b.n, title: b.title }).catch((e) => alert(e.message))
  const delBlock = async (b) => {
    if (!await confirmDelete({ title: 'Удалить раздел?', target: `Раздел «${b.title}»`, consequence: 'Все уроки, материалы, ДЗ, вопросы и ответы учеников внутри раздела будут удалены.' })) return
    try { await catDelete('blocks', b.id); setBlocks((list) => list.filter((x) => x.id !== b.id)) } catch (e) { alert(e.message) }
  }
  const patchBlock = (id, k, v) => setBlocks((a) => a.map((b) => b.id === id ? { ...b, [k]: v } : b))

  // ── уроки ──
  const addLesson = (type) => catCreate('lessons', { block_id: selBlock.id, type, title: type === 'theory' ? 'Новый урок' : type === 'text' ? 'Текстовый урок' : 'Практика', position: lessons.length }).then((d) => setLessons((list) => [...list, d.item]))
  // Из вставленного значения достаём чистый ID ролика Kinescope: принимает голый id,
  // ссылку kinescope.io/… или целиком скопированный embed-код с iframe.
  const kinescopeId = (v) => {
    const s = String(v || '').trim()
    const m = s.match(/kinescope\.io\/(?:embed\/)?([\w-]+)/)
    return m ? m[1] : s
  }

  // Длительность вводится как «чч:мм» (01:17) и превращается в минуты («77 мин»).
  // Голое число тоже понимаем как минуты; уже готовую строку («38 мин») не трогаем.
  const durationMinutes = (v) => {
    const s = String(v || '').trim()
    const hm = s.match(/^(\d{1,2}):([0-5]?\d)$/)
    if (hm) {
      const total = Number(hm[1]) * 60 + Number(hm[2])
      return total > 0 ? `${total} мин` : ''
    }
    if (/^\d+$/.test(s)) return Number(s) > 0 ? `${Number(s)} мин` : ''
    return s
  }

  const saveLesson = async (l) => {
    try {
      const source = l.video_source || (l.kinescope_id ? 'kinescope' : 'none')
      if (source === 'native' && !Number(l.video_id)) throw new Error('Выбери ролик из нашей видеотеки')
      if (source === 'link' && !String(l.video_url || '').trim()) throw new Error('Вставь ссылку на видео')
      const { item } = await catUpdate('lessons', { id: l.id, block_id: l.block_id, parent_lesson_id: l.parent_lesson_id || null, type: l.type, title: l.title, meta: l.meta, kinescope_id: kinescopeId(l.kinescope_id), video_source: source, video_id: source === 'native' ? Number(l.video_id) : null, video_url: l.video_url || '', air: l.air, air_at: l.air_at ? l.air_at.replace('T', ' ').slice(0, 16) : '', duration: durationMinutes(l.duration), opens_at: l.opens_at ? l.opens_at.replace('T', ' ').slice(0, 16) : '', description: l.description || '', restream_url: l.restream_url || '', restream_duration: Number(l.restream_duration) || 120 })
      if (source === 'native') await adminVideoUpdate({ video_id: Number(l.video_id), lesson_id: l.id, activate: true, title: videoLibrary.find((v) => v.id === Number(l.video_id))?.title || l.title })
      const fixed = { ...l, ...item, video_source: source, video_id: source === 'native' ? Number(l.video_id) : null, duration: durationMinutes(l.duration), kinescope_id: kinescopeId(l.kinescope_id) }
      if (fixed.parent_lesson_id) setParts((list) => list.map((x) => x.id === fixed.id ? { ...x, ...fixed } : x))
      else setLessons((list) => list.map((x) => x.id === fixed.id ? { ...x, ...fixed } : x))
      if (selLesson?.id === fixed.id) setSelLesson(fixed)
    } catch (e) { alert(e.message) }
  }
  const delLesson = async (l) => {
    if (!await confirmDelete({ title: 'Удалить урок?', target: `Урок «${l.title}»`, consequence: 'Материалы, тайм-коды, ДЗ, вопросы и ответы учеников этого урока будут удалены.' })) return
    try {
      await catDelete('lessons', l.id)
      if (l.parent_lesson_id && parentLesson) {
        const parent = parentLesson
        setParentLesson(null)
        setSelLesson(parent)
        await loadExtras(parent.id)
      } else {
        setLessons((list) => list.filter((x) => x.id !== l.id))
        setLevel('lessons')
      }
    } catch (e) { alert(e.message) }
  }
  const patchLessonRow = (id, k, v) => setLessons((a) => a.map((l) => l.id === id ? { ...l, [k]: v } : l))
  const patchSel = (k, v) => setSelLesson((l) => ({ ...l, [k]: v }))
  const patchPart = (id, k, v) => setParts((list) => list.map((part) => part.id === id ? { ...part, [k]: v } : part))
  const addPart = async () => {
    if (!selLesson || selLesson.parent_lesson_id) return
    if (parts.length >= 10) return alert('В одном уроке может быть не больше 10 занятий')
    try {
      const { item } = await catCreate('lessons', {
        block_id: selLesson.block_id,
        parent_lesson_id: selLesson.id,
        type: 'text',
        title: `Занятие ${parts.length + 1}`,
        meta: 'Внутри составного урока',
        video_source: 'none',
        position: parts.length,
      })
      setParts((list) => [...list, item])
    } catch (e) { alert(e.message) }
  }
  const lessonToolDone = ({ mode, blockId }) => {
    if (mode === 'move') {
      loadLessons(selBlock.id)
      setSelLesson(null)
      setLevel('lessons')
    } else if (mode === 'duplicate' && blockId === selBlock.id) {
      loadLessons(selBlock.id)
    }
  }

  // ── блокировка: уроки-условия (lesson_prereqs) ──
  const addPrereq = (requiredId) =>
    catCreate('lesson_prereqs', { lesson_id: selLesson.id, required_lesson_id: Number(requiredId), position: prereqs.length })
      .then((d) => setPrereqs((list) => [...list, d.item]))
      .catch((e) => alert(e.message))
  const delPrereq = (p) =>
    catDelete('lesson_prereqs', p.id)
      .then(() => setPrereqs((list) => list.filter((x) => x.id !== p.id)))
      .catch((e) => alert(e.message))
  const prereqTitle = (p) => {
    const l = courseLessons.find((x) => x.id === p.required_lesson_id)
    return l ? l.title : `Урок #${p.required_lesson_id}`
  }

  // ── материалы / тайм-коды ──
  const uploadMat = async (file) => { try { const d = await materialUpload(selLesson.id, file); setMaterials((list) => [...list, d.item]) } catch (e) { alert(e.message) } }
  const addLinkMat = () => {
    const name = prompt('Название материала'); if (!name) return
    const url = prompt('Ссылка (URL)') || ''
    catCreate('materials', { lesson_id: selLesson.id, name, url, size: '—', kind: 'link', position: materials.length }).then((d) => setMaterials((list) => [...list, d.item]))
  }
  const delMat = async (material) => {
    if (!await confirmDelete({ title: 'Удалить материал урока?', target: `Материал «${material.name || 'Без названия'}»`, consequence: 'Материал исчезнет из урока и больше не будет доступен ученикам.' })) return
    try { await catDelete('materials', material.id); setMaterials((list) => list.filter((x) => x.id !== material.id)) } catch (e) { alert(e.message) }
  }
  // Метку времени приводим к «мм:сс» / «ч:мм:сс» (принимаем «3:40», «03:40», «1:02:05»).
  const normTc = (v) => {
    const m = String(v || '').trim().match(/^(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?$/)
    if (!m) return String(v || '').trim()
    const parts = m[3] != null ? [m[1], m[2], m[3]] : [m[1], m[2]]
    return parts.map((p, i) => i === 0 ? String(Number(p)) : String(Number(p)).padStart(2, '0')).join(':')
  }
  const [tcForm, setTcForm] = useState({ t: '', label: '' })
  const addTc = () => {
    const t = normTc(tcForm.t)
    if (!/^\d{1,2}(:\d{2}){1,2}$/.test(t)) return alert('Время в формате мм:сс, например 03:40')
    catCreate('timecodes', { lesson_id: selLesson.id, t, label: tcForm.label.trim(), position: timecodes.length })
      .then((d) => { setTimecodes((list) => [...list, d.item]); setTcForm({ t: '', label: '' }) })
      .catch((e) => alert(e.message))
  }
  const patchTc = (id, k, v) => setTimecodes((list) => list.map((x) => x.id === id ? { ...x, [k]: v } : x))
  const saveTc = (tc) => catUpdate('timecodes', { id: tc.id, t: normTc(tc.t), label: tc.label })
    .then(() => setTimecodes((list) => list.map((x) => x.id === tc.id ? { ...x, t: normTc(tc.t) } : x)))
    .catch((e) => alert(e.message))
  const delTc = async (timecode) => {
    if (!await confirmDelete({ title: 'Удалить тайм-код?', target: `${timecode.t || 'Без времени'} · ${timecode.label || 'Без подписи'}`, consequence: 'Метка времени исчезнет из навигации по уроку.' })) return
    try { await catDelete('timecodes', timecode.id); setTimecodes((list) => list.filter((x) => x.id !== timecode.id)) } catch (e) { alert(e.message) }
  }

  // ── домашние задания урока ──
  // Уровни ДЗ: normal — базовый, medium — средний, hard — продвинутый (по подписке).
  // У урока может быть любой набор уровней (от нуля до всех трёх).
  const HW_LEVELS = { normal: 'базовое', medium: 'среднее', hard: 'продвинутое' }
  const HW_TITLES = { normal: 'Домашняя работа', medium: 'Средний уровень', hard: 'Продвинутый уровень' }
  const addHomework = (level) => catCreate('homeworks', { lesson_id: selLesson.id, level, title: HW_TITLES[level], deadline: '', position: homeworks.length }).then((d) => setHomeworks((list) => [...list, d.item]))
  const saveHomework = (hw) => catUpdate('homeworks', { id: hw.id, title: hw.title, deadline: hw.deadline, level: hw.level }).catch((e) => alert(e.message))
  const delHomework = async (hw) => {
    if (!await confirmDelete({ title: 'Удалить домашнее задание?', target: `ДЗ «${hw.title}»`, consequence: 'Вопросы, сдачи, ответы, файлы учеников и продления дедлайна этого ДЗ будут удалены.' })) return
    try { await catDelete('homeworks', hw.id); setHomeworks((list) => list.filter((x) => x.id !== hw.id)) } catch (e) { alert(e.message) }
  }
  const patchHw = (id, k, v) => setHomeworks((a) => a.map((h) => h.id === id ? { ...h, [k]: v } : h))

  if (!meta) return <div className="admin-wrap"><div className="admin__empty">Загрузка курса…</div></div>

  // ── хлебные крошки ──
  const crumbs = (
    <div className="admin__crumbs">
      <button className="lesson__back" onClick={onBack}><ArrowLeft size={16} /> Курсы</button>
      <ChevronRight size={14} className="admin__crumb-sep" />
      <button className={level === 'months' ? 'admin__crumb active' : 'admin__crumb'} onClick={() => setLevel('months')}>{meta.title}</button>
      {selMonth && (level === 'blocks' || level === 'lessons' || level === 'lesson') && <>
        <ChevronRight size={14} className="admin__crumb-sep" />
        <button className={level === 'blocks' ? 'admin__crumb active' : 'admin__crumb'} onClick={() => setLevel('blocks')}>{selMonth.name}</button>
      </>}
      {selBlock && (level === 'lessons' || level === 'lesson') && <>
        <ChevronRight size={14} className="admin__crumb-sep" />
        <button className={level === 'lessons' ? 'admin__crumb active' : 'admin__crumb'} onClick={() => setLevel('lessons')}>Раздел {selBlock.n}</button>
      </>}
      {selLesson && level === 'lesson' && <>
        <ChevronRight size={14} className="admin__crumb-sep" />
        {parentLesson && <>
          <button className="admin__crumb" onClick={openParentLesson}>{parentLesson.title}</button>
          <ChevronRight size={14} className="admin__crumb-sep" />
        </>}
        <button className="admin__crumb active">{selLesson.title}</button>
      </>}
    </div>
  )

  return (
    <div className="admin__editor">
      {crumbs}

      {/* ── УРОВЕНЬ: МЕСЯЦЫ ── */}
      {level === 'months' && (
        <section className="admin__panel">
          <div className="admin__panel-head">
            <h2>Периоды курса</h2>
            <div className="admin__head-btns">
              <button className="btn btn-light btn-sm" onClick={() => setSettingsOpen((v) => !v)}><Settings size={15} /> Настройки</button>
              <select className="admin__inp admin__inp--period-kind" value={newPeriodKind} onChange={(e) => setNewPeriodKind(e.target.value)} aria-label="Тип нового периода">
                <option value="month">Месяц</option><option value="block">Блок</option>
              </select>
              <button className="btn btn-orange btn-sm" onClick={addMonth}><Plus size={15} /> Добавить</button>
            </div>
          </div>

          {settingsOpen && (
            <div className="admin__settings">
              <label>Название курса <input className="admin__inp" value={meta.title || ''} onChange={(e) => setMeta({ ...meta, title: e.target.value })} /></label>
              <div className="admin__row3">
                <label>Предмет <select className="admin__inp" value={meta.subject_id} onChange={(e) => setMeta({ ...meta, subject_id: Number(e.target.value) })}>{subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></label>
                <label>Тип <select className="admin__inp" value={meta.type} onChange={(e) => setMeta({ ...meta, type: e.target.value })}>{COURSE_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}</select></label>
                <label>Цвет <input type="color" className="admin__color" value={meta.color || '#3f9bff'} onChange={(e) => setMeta({ ...meta, color: e.target.value })} /></label>
              </div>
              <label>Закрытый канал преподавателя <span>(ссылка на Telegram, VK или другую площадку)</span>
                <input className="admin__inp" type="url" value={meta.teacher_channel_url || ''} onChange={(e) => setMeta({ ...meta, teacher_channel_url: e.target.value })} placeholder="https://t.me/..." />
              </label>
              <button className="btn btn-orange btn-sm" onClick={saveMeta}><Save size={14} /> Сохранить</button>
            </div>
          )}

          {months.length === 0 && <div className="admin__empty">Пока нет периодов. Выберите «Месяц» или «Блок» и добавьте первый.</div>}
          <ReorderList entity="months" items={months} onChange={setMonths} renderRow={(m) => (
            <div className="admin__mrow">
              <div className="admin__drow">
                <select className="admin__inp admin__inp--period-kind" value={m.kind === 'block' ? 'block' : 'month'} onChange={(e) => { patchMonth(m.id, 'kind', e.target.value); saveMonth({ ...m, kind: e.target.value }) }} aria-label={`Тип периода «${m.name}»`}>
                  <option value="month">Месяц</option><option value="block">Блок</option>
                </select>
                <input className="admin__inp admin__inp--bold" value={m.name} onChange={(e) => patchMonth(m.id, 'name', e.target.value)} onBlur={() => saveMonth(m)} />
                <input className="admin__inp admin__inp--date" type="date" title="Идёт с (для автовыбора периода у ученика)" value={m.starts_on || ''} onChange={(e) => { patchMonth(m.id, 'starts_on', e.target.value); saveMonth({ ...m, starts_on: e.target.value }) }} />
                <span className="admin__date-sep">—</span>
                <input className="admin__inp admin__inp--date" type="date" title="Идёт по (включительно)" value={m.ends_on || ''} onChange={(e) => { patchMonth(m.id, 'ends_on', e.target.value); saveMonth({ ...m, ends_on: e.target.value }) }} />
                <button className={`admin__iconbtn ${galleryMonth === m.id ? 'admin__iconbtn--active' : ''}`} title={`Фото-расписание ${periodWord(m, 'gen')}`} onClick={() => toggleGallery(m)}><Image size={15} /></button>
                <button className="admin__iconbtn" title="Сохранить" onClick={() => saveMonth(m)}><Save size={15} /></button>
                <button className="admin__iconbtn admin__iconbtn--danger" title="Удалить" onClick={() => delMonth(m)}><Trash2 size={15} /></button>
                <button className="admin__open" onClick={() => openMonth(m)}>Разделы <ChevronRight size={15} /></button>
              </div>
              {galleryMonth === m.id && (
                <div className="admin__mgallery">
                  <label>Название кнопки у ученика
                    <input className="admin__inp" placeholder="Например: Расписание июля" value={m.gallery_label || ''}
                           onChange={(e) => patchMonth(m.id, 'gallery_label', e.target.value)} onBlur={() => saveMonth(m)} />
                  </label>
                  <div className="admin__mgallery-grid">
                    {monthPhotos.map((p) => (
                      <div className="admin__mphoto" key={p.id}>
                        <img src={p.url} alt="" loading="lazy" />
                        <button className="admin__mphoto-del" title="Удалить фото" onClick={() => delMonthPhoto(p)}><Trash2 size={13} /></button>
                      </div>
                    ))}
                    {monthPhotos.length < 10 && (
                      <label className="admin__mphoto-add" title="Загрузить фото (JPG/PNG/WebP)">
                        <Upload size={16} /> <span>{monthPhotos.length}/10</span>
                        <input type="file" accept="image/jpeg,image/png,image/webp" hidden
                               onChange={(e) => { if (e.target.files[0]) uploadMonthPhoto(e.target.files[0]); e.target.value = '' }} />
                      </label>
                    )}
                  </div>
                  <p className="admin__hint">Кнопка с галереей видна ученику под плеером, пока он не выбрал урок. Без фото кнопка не показывается.</p>
                </div>
              )}
            </div>
          )} />
        </section>
      )}

      {/* ── УРОВЕНЬ: БЛОКИ ── */}
      {level === 'blocks' && (
        <section className="admin__panel">
          <div className="admin__panel-head">
            <h2>Разделы · {selMonth.name}</h2>
            <button className="btn btn-orange btn-sm" onClick={addBlock}><Plus size={15} /> Раздел</button>
          </div>
          {blocks.length === 0 && <div className="admin__empty">В этом {periodWord(selMonth, 'prep')} пока нет разделов.</div>}
          <ReorderList entity="blocks" items={blocks} onChange={setBlocks} renderRow={(b) => (
            <div className="admin__drow">
              <input className="admin__inp admin__inp--n" type="number" value={b.n} onChange={(e) => patchBlock(b.id, 'n', e.target.value)} onBlur={() => saveBlock(b)} />
              <input className="admin__inp" value={b.title} onChange={(e) => patchBlock(b.id, 'title', e.target.value)} onBlur={() => saveBlock(b)} />
              <button className="admin__iconbtn" title="Сохранить" onClick={() => saveBlock(b)}><Save size={15} /></button>
              <button className="admin__iconbtn admin__iconbtn--danger" title="Удалить" onClick={() => delBlock(b)}><Trash2 size={15} /></button>
              <button className="admin__open" onClick={() => openBlock(b)}>Уроки <ChevronRight size={15} /></button>
            </div>
          )} />
        </section>
      )}

      {/* ── УРОВЕНЬ: УРОКИ ── */}
      {level === 'lessons' && (
        <section className="admin__panel">
          <div className="admin__panel-head">
            <h2>Уроки · Раздел {selBlock.n}</h2>
            <div className="admin__head-btns">
              <button className="btn btn-light btn-sm" onClick={() => addLesson('theory')}><Plus size={14} /> теория</button>
              <button className="btn btn-light btn-sm" onClick={() => addLesson('practice')}><Plus size={14} /> практика</button>
              <button className="btn btn-light btn-sm" onClick={() => addLesson('webinar')}><Plus size={14} /> вебинар</button>
              <button className="btn btn-light btn-sm" onClick={() => addLesson('text')}><Plus size={14} /> текстовый</button>
            </div>
          </div>
          {lessons.length === 0 && <div className="admin__empty">В этом разделе пока нет уроков.</div>}
          <ReorderList entity="lessons" items={lessons} onChange={setLessons} renderRow={(l) => (
            <div className="admin__drow">
              <span className={`admin__ltype admin__ltype--${l.type}`}>{typeLabel(l.type)}</span>
              <input className="admin__inp" value={l.title} onChange={(e) => patchLessonRow(l.id, 'title', e.target.value)} onBlur={() => saveLesson(l)} />
              <button className="admin__iconbtn" title="Сохранить" onClick={() => saveLesson(l)}><Save size={15} /></button>
              <button className="admin__iconbtn" title="Дублировать урок" onClick={() => setLessonTool({ source: l, mode: 'duplicate' })}><Copy size={15} /></button>
              <button className="admin__iconbtn admin__iconbtn--danger" title="Удалить" onClick={() => delLesson(l)}><Trash2 size={15} /></button>
              <button className="admin__open" onClick={() => openLesson(l)}>Открыть <ChevronRight size={15} /></button>
            </div>
          )} />
        </section>
      )}

      {/* ── УРОВЕНЬ: УРОК (детали) ── */}
      {level === 'lesson' && selLesson && (
        <>
          <section className="admin__panel">
            <div className="admin__panel-head">
              <h2>{parentLesson ? 'Занятие внутри урока' : 'Урок'}</h2>
              {!parentLesson && <button className="btn btn-light btn-sm" onClick={() => setLessonTool({ source: selLesson, mode: 'duplicate' })}><Layers3 size={15} /> Действия с уроком</button>}
            </div>
            <div className="admin__form">
              <label>Название <input className="admin__inp" value={selLesson.title || ''} onChange={(e) => patchSel('title', e.target.value)} /></label>
              <div className="admin__row3">
                <label>Тип <select className="admin__inp" value={selLesson.type} onChange={(e) => patchSel('type', e.target.value)}>{LESSON_TYPES.map(([v, t]) => <option key={v} value={v}>{t}</option>)}</select></label>
                <label>Подпись (meta) <input className="admin__inp" value={selLesson.meta || ''} onChange={(e) => patchSel('meta', e.target.value)} placeholder="Видео и тест" /></label>
              </div>
              <div className="admin-video-source">
                <div className="admin-video-source__head"><Video size={17} /><div><b>Видео урока</b><span>Старые ролики Kinescope остаются на месте; источник можно переключать в любой момент.</span></div></div>
                <div className="admin-video-source__tabs">
                  <button type="button" className={(selLesson.video_source || (selLesson.kinescope_id ? 'kinescope' : 'none')) === 'kinescope' ? 'active' : ''} onClick={() => patchSel('video_source', 'kinescope')}>Kinescope</button>
                  <button type="button" className={selLesson.video_source === 'native' ? 'active' : ''} onClick={() => patchSel('video_source', 'native')}>Наш видеосервис</button>
                  <button type="button" className={selLesson.video_source === 'link' ? 'active' : ''} onClick={() => patchSel('video_source', 'link')}>Ссылка</button>
                  <button type="button" className={(selLesson.video_source || 'none') === 'none' && !selLesson.kinescope_id ? 'active' : ''} onClick={() => patchSel('video_source', 'none')}>Без видео</button>
                </div>
                {(selLesson.video_source || (selLesson.kinescope_id ? 'kinescope' : 'none')) === 'kinescope' && (
                  <label>Kinescope ID <span>(можно вставить ссылку или embed-код целиком)</span><input className="admin__inp" value={selLesson.kinescope_id || ''} onChange={(e) => patchSel('kinescope_id', kinescopeId(e.target.value))} placeholder="id ролика" /></label>
                )}
                {selLesson.video_source === 'native' && (
                  <label>Ролик из видеотеки
                    <select className="admin__inp" value={selLesson.video_id || ''} onChange={(e) => patchSel('video_id', Number(e.target.value) || null)}>
                      <option value="">Выбери готовое видео</option>
                      {videoLibrary.map((v) => <option key={v.id} value={v.id}>{v.title}{v.course_title ? ` · ${v.course_title}${v.month_name ? ` · ${periodOptionLabel({ kind: v.period_kind, name: v.month_name })}` : ''}` : ' · без урока'}</option>)}
                    </select>
                    <span>Новое видео загружается в отдельном разделе «Видеотека».</span>
                  </label>
                )}
                {selLesson.video_source === 'link' && (
                  <label>Постоянная ссылка на видео <span>(YouTube, VK, RUTUBE, WebRestream или EduContent — показывается внутри урока)</span>
                    <input className="admin__inp" type="url" value={selLesson.video_url || ''} onChange={(e) => patchSel('video_url', e.target.value)} placeholder="https://..." />
                  </label>
                )}
              </div>
              <div className="admin__row3">
                <label>Длительность <span>(чч:мм — сохранится в минутах)</span><input className="admin__inp" value={selLesson.duration || ''} onChange={(e) => patchSel('duration', e.target.value)} placeholder="01:17" /></label>
                <label>Эфир <span>(подпись)</span><input className="admin__inp" value={selLesson.air || ''} onChange={(e) => patchSel('air', e.target.value)} placeholder="Эфир 5 окт, 15:00" /></label>
                <label>Открыть доступ <span>(до этого времени урок закрыт для всех)</span>
                  <input className="admin__inp" type="datetime-local" value={(selLesson.opens_at || '').replace(' ', 'T').slice(0, 16)} onChange={(e) => patchSel('opens_at', e.target.value)} />
                </label>
              </div>
              <label>Дата в расписании <span>(эфир теории/практики/вебинара — необязательно)</span>
                <input className="admin__inp" type="datetime-local" value={(selLesson.air_at || '').replace(' ', 'T').slice(0, 16)} onChange={(e) => patchSel('air_at', e.target.value)} />
              </label>
              <div className="admin__row2">
                <label>Ссылка на эфир <span>(RUTUBE, VK и YouTube откроются внутри урока; остальные — отдельной кнопкой)</span>
                  <input className="admin__inp" type="url" value={selLesson.restream_url || ''} onChange={(e) => patchSel('restream_url', e.target.value)} placeholder="YouTube, VK, RUTUBE или webrestream.site/p/..." />
                </label>
                <label>Длительность эфира <span>(минут; ещё 30 минут даются автоматически)</span>
                  <input className="admin__inp" type="number" min="1" max="1440" value={selLesson.restream_duration || 120} onChange={(e) => patchSel('restream_duration', Number(e.target.value) || 120)} />
                </label>
              </div>
              <label><Lock size={13} /> Блокировка <span>(урок откроется, когда ученик пройдёт выбранные уроки; у закрытого урока видно «Пройти N уроков»)</span></label>
              <div className="admin__prereqs">
                {prereqs.map((p) => (
                  <span className="admin__prereq" key={p.id}>
                    {prereqTitle(p)}
                    <button title="Убрать условие" onClick={() => delPrereq(p)}><X size={13} /></button>
                  </span>
                ))}
                <select className="admin__inp admin__prereq-add" value=""
                        onChange={(e) => { if (e.target.value) addPrereq(e.target.value) }}>
                  <option value="">+ добавить урок-условие</option>
                  {courseLessons
                    .filter((x) => x.id !== selLesson.id && !prereqs.some((p) => p.required_lesson_id === x.id))
                    .map((x) => <option key={x.id} value={x.id}>{periodWord({ kind: x.period_kind }, 'one', true)} · {x.month} · раздел {x.block} · {x.title}</option>)}
                </select>
              </div>
              <label>Описание занятия <span>(Markdown — у ученика под названием урока)</span></label>
              <RichReviewEditor value={selLesson.description || ''} onChange={(v) => patchSel('description', v)} />
              <div className="admin__edit-btns">
                <button className="btn btn-orange btn-sm" onClick={() => saveLesson(selLesson)}><Save size={14} /> Сохранить урок</button>
                <button className="btn admin__delbtn btn-sm" onClick={() => delLesson(selLesson)}><Trash2 size={14} /> Удалить</button>
              </div>
            </div>
          </section>

          {!selLesson.parent_lesson_id && (
            <section className="admin__panel admin-parts">
              <div className="admin__panel-head">
                <div>
                  <h2><Layers3 size={17} /> Занятия внутри урока</h2>
                  <p className="admin-parts__hint">Если список заполнен, ученик увидит справа один урок, а здесь сможет переключаться между отдельными занятиями.</p>
                </div>
                <button className="btn btn-light btn-sm" disabled={parts.length >= 10} onClick={addPart}><Plus size={14} /> Добавить занятие</button>
              </div>
              {parts.length === 0 && <div className="admin__empty admin__empty--sm">Обычный урок без внутренних занятий. Можно добавить до 10.</div>}
              {parts.length > 0 && (
                <>
                  <div className="admin-parts__count">{parts.length} из 10</div>
                  <ReorderList entity="lessons" items={parts} onChange={setParts} renderRow={(part, index) => (
                    <div className="admin__drow admin-parts__row">
                      <span className="admin-parts__number">{index + 1}</span>
                      <span className={`admin__ltype admin__ltype--${part.type}`}>{typeLabel(part.type)}</span>
                      <input className="admin__inp" value={part.title || ''} onChange={(e) => patchPart(part.id, 'title', e.target.value)} onBlur={() => saveLesson(part)} />
                      <button className="admin__iconbtn" title="Сохранить" onClick={() => saveLesson(part)}><Save size={15} /></button>
                      <button className="admin__open" onClick={() => openPart(part)}>Настроить <ChevronRight size={15} /></button>
                    </div>
                  )} />
                </>
              )}
            </section>
          )}

          <section className="admin__panel">
            <div className="admin__panel-head">
              <h2><FileText size={17} /> Материалы</h2>
              <div className="admin__head-btns">
                <label className="btn btn-light btn-sm"><Upload size={14} /> Файл<input type="file" hidden onChange={(e) => e.target.files[0] && uploadMat(e.target.files[0])} /></label>
                <button className="btn btn-light btn-sm" onClick={addLinkMat}><Plus size={14} /> Ссылка</button>
              </div>
            </div>
            {materials.length === 0 && <div className="admin__empty admin__empty--sm">Нет материалов</div>}
            <ReorderList entity="materials" items={materials} onChange={setMaterials} renderRow={(mt) => (
              <div className="admin__drow">
                <FileText size={15} /><b className="admin__drow-name">{mt.name}</b>
                <span className="admin__extra-meta">{mt.size} · {mt.kind}</span>
                <button className="admin__iconbtn admin__iconbtn--danger" onClick={() => delMat(mt)}><Trash2 size={14} /></button>
              </div>
            )} />
          </section>

          <section className="admin__panel">
            <div className="admin__panel-head">
              <h2><Clock size={17} /> Тайм-коды</h2>
            </div>
            <p className="admin__hint">У ученика клик по тайм-коду перематывает видео на этот момент.</p>
            <div className="admin__drow admin__tc-add">
              <input className="admin__inp admin__inp--n" value={tcForm.t} placeholder="03:40"
                     onChange={(e) => setTcForm((f) => ({ ...f, t: e.target.value }))}
                     onKeyDown={(e) => e.key === 'Enter' && addTc()} />
              <input className="admin__inp" value={tcForm.label} placeholder="Что происходит в этот момент"
                     onChange={(e) => setTcForm((f) => ({ ...f, label: e.target.value }))}
                     onKeyDown={(e) => e.key === 'Enter' && addTc()} />
              <button className="btn btn-light btn-sm" onClick={addTc}><Plus size={14} /> Добавить</button>
            </div>
            {timecodes.length === 0 && <div className="admin__empty admin__empty--sm">Нет тайм-кодов</div>}
            <ReorderList entity="timecodes" items={timecodes} onChange={setTimecodes} renderRow={(tc) => (
              <div className="admin__drow">
                <input className="admin__inp admin__inp--n" value={tc.t} onChange={(e) => patchTc(tc.id, 't', e.target.value)} onBlur={() => saveTc(tc)} />
                <input className="admin__inp" value={tc.label || ''} onChange={(e) => patchTc(tc.id, 'label', e.target.value)} onBlur={() => saveTc(tc)} placeholder="подпись" />
                <button className="admin__iconbtn admin__iconbtn--danger" onClick={() => delTc(tc)}><Trash2 size={14} /></button>
              </div>
            )} />
          </section>

          <section className="admin__panel">
            <div className="admin__panel-head">
              <h2>Домашние задания</h2>
              <div className="admin__head-btns">
                <button className="btn btn-light btn-sm" onClick={() => addHomework('normal')}><Plus size={14} /> базовое</button>
                <button className="btn btn-light btn-sm" onClick={() => addHomework('medium')}><Plus size={14} /> среднее</button>
                <button className="btn btn-light btn-sm" onClick={() => addHomework('hard')}><Plus size={14} /> продвинутое</button>
              </div>
            </div>
            {homeworks.length === 0 && <div className="admin__empty admin__empty--sm">Нет ДЗ на этом уроке</div>}
            <ReorderList entity="homeworks" items={homeworks} onChange={setHomeworks} renderRow={(hw) => (
              <div className="admin__drow">
                <select className="admin__inp admin__inp--hwlevel" value={hw.level}
                        onChange={(e) => { patchHw(hw.id, 'level', e.target.value); saveHomework({ ...hw, level: e.target.value }) }}>
                  {Object.entries(HW_LEVELS).map(([v, t]) => <option key={v} value={v}>{t}</option>)}
                </select>
                <input className="admin__inp" value={hw.title} onChange={(e) => patchHw(hw.id, 'title', e.target.value)} onBlur={() => saveHomework(hw)} />
                <input className="admin__inp admin__inp--deadline" type="datetime-local" title="Дедлайн: после него — штраф −1 жизнь; продления из магазина отсчитываются от него"
                       value={(hw.deadline || '').replace(' ', 'T').slice(0, 16)}
                       onChange={(e) => { const v = e.target.value ? e.target.value.replace('T', ' ').slice(0, 16) : ''; patchHw(hw.id, 'deadline', v); saveHomework({ ...hw, deadline: v }) }} />
                <button className="admin__iconbtn" title="Сохранить" onClick={() => saveHomework(hw)}><Save size={15} /></button>
                <button className="admin__iconbtn admin__iconbtn--danger" title="Удалить" onClick={() => delHomework(hw)}><Trash2 size={15} /></button>
                <button className="admin__open" onClick={() => setQModal(hw)}>Вопросы <ChevronRight size={15} /></button>
              </div>
            )} />
          </section>
        </>
      )}

      {qModal && <AdminQuestionsModal parent={{ key: 'homework_id', id: qModal.id, title: qModal.title, level: qModal.level }} onClose={() => setQModal(null)} />}
      {lessonTool && <LessonToolsModal
        sourceLesson={lessonTool.source}
        initialMode={lessonTool.mode}
        onDone={lessonToolDone}
        onClose={() => setLessonTool(null)}
      />}
    </div>
  )
}
