import { useEffect, useState, useCallback } from 'react'
import {
  ArrowLeft, Plus, Trash2, Save, ChevronRight, Upload, FileText, Clock, Settings,
} from 'lucide-react'
import { catList, catCreate, catUpdate, catDelete, materialUpload } from '../../api.js'
import { COURSE_TYPES } from './AdminCourses.jsx'
import ReorderList from './ReorderList.jsx'
import AdminQuestionsModal from './AdminQuestionsModal.jsx'

const LESSON_TYPES = [['theory', 'Теория'], ['practice', 'Практика'], ['webinar', 'Вебинар']]
const typeLabel = (t) => (LESSON_TYPES.find((x) => x[0] === t) || [, t])[1]

export default function AdminCourseEditor({ courseId, subjects, onBack }) {
  const [meta, setMeta] = useState(null)
  const [level, setLevel] = useState('months')      // months | blocks | lessons | lesson
  const [settingsOpen, setSettingsOpen] = useState(false)

  const [months, setMonths] = useState([])
  const [blocks, setBlocks] = useState([])
  const [lessons, setLessons] = useState([])
  const [selMonth, setSelMonth] = useState(null)
  const [selBlock, setSelBlock] = useState(null)
  const [selLesson, setSelLesson] = useState(null)
  const [materials, setMaterials] = useState([])
  const [timecodes, setTimecodes] = useState([])
  const [homeworks, setHomeworks] = useState([])
  const [qModal, setQModal] = useState(null)   // ДЗ, у которого открыт редактор вопросов

  // ── загрузка уровней ──
  const loadMeta = useCallback(async () => {
    const c = await catList('courses')
    setMeta(c.items.find((x) => x.id === courseId) || null)
  }, [courseId])
  const loadMonths = useCallback(() => catList('months', { course_id: courseId }).then((d) => setMonths(d.items)), [courseId])
  const loadBlocks = (monthId) => catList('blocks', { month_id: monthId }).then((d) => setBlocks(d.items))
  const loadLessons = (blockId) => catList('lessons', { block_id: blockId }).then((d) => setLessons(d.items))
  const loadExtras = (lessonId) => Promise.all([
    catList('materials', { lesson_id: lessonId }).then((d) => setMaterials(d.items)),
    catList('timecodes', { lesson_id: lessonId }).then((d) => setTimecodes(d.items)),
    catList('homeworks', { lesson_id: lessonId }).then((d) => setHomeworks(d.items)),
  ])
  const loadHomeworks = (lessonId) => catList('homeworks', { lesson_id: lessonId }).then((d) => setHomeworks(d.items))

  useEffect(() => { loadMeta(); loadMonths() }, [loadMeta, loadMonths])

  // ── навигация ──
  const openMonth = (m) => { setSelMonth(m); loadBlocks(m.id); setLevel('blocks') }
  const openBlock = (b) => { setSelBlock(b); loadLessons(b.id); setLevel('lessons') }
  const openLesson = (l) => { setSelLesson(l); loadExtras(l.id); setLevel('lesson') }

  // ── курс (мета) ──
  const saveMeta = async () => {
    try { await catUpdate('courses', { id: meta.id, subject_id: meta.subject_id, title: meta.title, type: meta.type, color: meta.color }); setSettingsOpen(false) }
    catch (e) { alert(e.message) }
  }

  // ── месяцы ──
  const addMonth = () => catCreate('months', { course_id: courseId, name: 'Новый месяц', position: months.length }).then((d) => setMonths((list) => [...list, d.item]))
  const saveMonth = (m) => catUpdate('months', { id: m.id, name: m.name }).catch((e) => alert(e.message))
  const delMonth = (m) => confirm(`Удалить месяц «${m.name}» со всеми блоками и уроками?`) && catDelete('months', m.id).then(() => setMonths((list) => list.filter((x) => x.id !== m.id)))
  const patchMonth = (id, v) => setMonths((a) => a.map((m) => m.id === id ? { ...m, name: v } : m))

  // ── блоки ──
  const addBlock = () => catCreate('blocks', { course_id: courseId, month_id: selMonth.id, n: blocks.length + 1, title: 'Новый блок', position: blocks.length }).then((d) => setBlocks((list) => [...list, d.item]))
  const saveBlock = (b) => catUpdate('blocks', { id: b.id, n: b.n, title: b.title }).catch((e) => alert(e.message))
  const delBlock = (b) => confirm(`Удалить блок «${b.title}» со всеми уроками?`) && catDelete('blocks', b.id).then(() => setBlocks((list) => list.filter((x) => x.id !== b.id)))
  const patchBlock = (id, k, v) => setBlocks((a) => a.map((b) => b.id === id ? { ...b, [k]: v } : b))

  // ── уроки ──
  const addLesson = (type) => catCreate('lessons', { block_id: selBlock.id, type, title: type === 'theory' ? 'Новый урок' : 'Практика', position: lessons.length }).then((d) => setLessons((list) => [...list, d.item]))
  const saveLesson = (l) => catUpdate('lessons', { id: l.id, type: l.type, title: l.title, meta: l.meta, kinescope_id: l.kinescope_id, air: l.air, air_at: l.air_at ? l.air_at.replace('T', ' ').slice(0, 16) : '', duration: l.duration, locked_hint: l.locked_hint }).then(() => { setLessons((list) => list.map((x) => x.id === l.id ? { ...x, ...l } : x)); setSelLesson(l) }).catch((e) => alert(e.message))
  const delLesson = (l) => { if (confirm(`Удалить урок «${l.title}»?`)) catDelete('lessons', l.id).then(() => { setLessons((list) => list.filter((x) => x.id !== l.id)); setLevel('lessons') }) }
  const patchLessonRow = (id, k, v) => setLessons((a) => a.map((l) => l.id === id ? { ...l, [k]: v } : l))
  const patchSel = (k, v) => setSelLesson((l) => ({ ...l, [k]: v }))

  // ── материалы / тайм-коды ──
  const uploadMat = async (file) => { try { const d = await materialUpload(selLesson.id, file); setMaterials((list) => [...list, d.item]) } catch (e) { alert(e.message) } }
  const addLinkMat = () => {
    const name = prompt('Название материала'); if (!name) return
    const url = prompt('Ссылка (URL)') || ''
    catCreate('materials', { lesson_id: selLesson.id, name, url, size: '—', kind: 'link', position: materials.length }).then((d) => setMaterials((list) => [...list, d.item]))
  }
  const delMat = (id) => catDelete('materials', id).then(() => setMaterials((list) => list.filter((x) => x.id !== id)))
  const addTc = () => {
    const t = prompt('Метка времени (например 03:40)'); if (!t) return
    const label = prompt('Подпись') || ''
    catCreate('timecodes', { lesson_id: selLesson.id, t, label, position: timecodes.length }).then((d) => setTimecodes((list) => [...list, d.item]))
  }
  const delTc = (id) => catDelete('timecodes', id).then(() => setTimecodes((list) => list.filter((x) => x.id !== id)))

  // ── домашние задания урока ──
  const addHomework = (level) => catCreate('homeworks', { lesson_id: selLesson.id, level, title: level === 'hard' ? 'Повышенная сложность' : 'Домашняя работа', deadline: '', position: homeworks.length }).then((d) => setHomeworks((list) => [...list, d.item]))
  const saveHomework = (hw) => catUpdate('homeworks', { id: hw.id, title: hw.title, deadline: hw.deadline, level: hw.level }).catch((e) => alert(e.message))
  const delHomework = (hw) => confirm(`Удалить ДЗ «${hw.title}» со всеми вопросами?`) && catDelete('homeworks', hw.id).then(() => setHomeworks((list) => list.filter((x) => x.id !== hw.id)))
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
        <button className={level === 'lessons' ? 'admin__crumb active' : 'admin__crumb'} onClick={() => setLevel('lessons')}>Блок {selBlock.n}</button>
      </>}
      {selLesson && level === 'lesson' && <>
        <ChevronRight size={14} className="admin__crumb-sep" />
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
            <h2>Месяцы курса</h2>
            <div className="admin__head-btns">
              <button className="btn btn-light btn-sm" onClick={() => setSettingsOpen((v) => !v)}><Settings size={15} /> Настройки</button>
              <button className="btn btn-orange btn-sm" onClick={addMonth}><Plus size={15} /> Месяц</button>
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
              <button className="btn btn-orange btn-sm" onClick={saveMeta}><Save size={14} /> Сохранить</button>
            </div>
          )}

          {months.length === 0 && <div className="admin__empty">Пока нет месяцев. Добавьте первый.</div>}
          <ReorderList entity="months" items={months} onChange={setMonths} renderRow={(m) => (
            <div className="admin__drow">
              <input className="admin__inp admin__inp--bold" value={m.name} onChange={(e) => patchMonth(m.id, e.target.value)} />
              <button className="admin__iconbtn" title="Сохранить" onClick={() => saveMonth(m)}><Save size={15} /></button>
              <button className="admin__iconbtn admin__iconbtn--danger" title="Удалить" onClick={() => delMonth(m)}><Trash2 size={15} /></button>
              <button className="admin__open" onClick={() => openMonth(m)}>Блоки <ChevronRight size={15} /></button>
            </div>
          )} />
        </section>
      )}

      {/* ── УРОВЕНЬ: БЛОКИ ── */}
      {level === 'blocks' && (
        <section className="admin__panel">
          <div className="admin__panel-head">
            <h2>Блоки · {selMonth.name}</h2>
            <button className="btn btn-orange btn-sm" onClick={addBlock}><Plus size={15} /> Блок</button>
          </div>
          {blocks.length === 0 && <div className="admin__empty">В этом месяце пока нет блоков.</div>}
          <ReorderList entity="blocks" items={blocks} onChange={setBlocks} renderRow={(b) => (
            <div className="admin__drow">
              <input className="admin__inp admin__inp--n" type="number" value={b.n} onChange={(e) => patchBlock(b.id, 'n', e.target.value)} />
              <input className="admin__inp" value={b.title} onChange={(e) => patchBlock(b.id, 'title', e.target.value)} />
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
            <h2>Уроки · Блок {selBlock.n}</h2>
            <div className="admin__head-btns">
              <button className="btn btn-light btn-sm" onClick={() => addLesson('theory')}><Plus size={14} /> теория</button>
              <button className="btn btn-light btn-sm" onClick={() => addLesson('practice')}><Plus size={14} /> практика</button>
              <button className="btn btn-light btn-sm" onClick={() => addLesson('webinar')}><Plus size={14} /> вебинар</button>
            </div>
          </div>
          {lessons.length === 0 && <div className="admin__empty">В этом блоке пока нет уроков.</div>}
          <ReorderList entity="lessons" items={lessons} onChange={setLessons} renderRow={(l) => (
            <div className="admin__drow">
              <span className={`admin__ltype admin__ltype--${l.type}`}>{typeLabel(l.type)}</span>
              <input className="admin__inp" value={l.title} onChange={(e) => patchLessonRow(l.id, 'title', e.target.value)} />
              <button className="admin__iconbtn" title="Сохранить" onClick={() => saveLesson(l)}><Save size={15} /></button>
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
            <h2>Урок</h2>
            <div className="admin__form">
              <label>Название <input className="admin__inp" value={selLesson.title || ''} onChange={(e) => patchSel('title', e.target.value)} /></label>
              <div className="admin__row3">
                <label>Тип <select className="admin__inp" value={selLesson.type} onChange={(e) => patchSel('type', e.target.value)}>{LESSON_TYPES.map(([v, t]) => <option key={v} value={v}>{t}</option>)}</select></label>
                <label>Подпись (meta) <input className="admin__inp" value={selLesson.meta || ''} onChange={(e) => patchSel('meta', e.target.value)} placeholder="Видео и тест" /></label>
                <label>Kinescope ID <input className="admin__inp" value={selLesson.kinescope_id || ''} onChange={(e) => patchSel('kinescope_id', e.target.value)} placeholder="id ролика" /></label>
              </div>
              <div className="admin__row3">
                <label>Длительность <input className="admin__inp" value={selLesson.duration || ''} onChange={(e) => patchSel('duration', e.target.value)} placeholder="38 мин" /></label>
                <label>Эфир <span>(подпись)</span><input className="admin__inp" value={selLesson.air || ''} onChange={(e) => patchSel('air', e.target.value)} placeholder="Эфир 5 окт, 15:00" /></label>
                <label>Блокировка <input className="admin__inp" value={selLesson.locked_hint || ''} onChange={(e) => patchSel('locked_hint', e.target.value)} placeholder="Нужно решить 2 урока" /></label>
              </div>
              <label>Дата в расписании <span>(эфир теории/практики/вебинара — необязательно)</span>
                <input className="admin__inp" type="datetime-local" value={(selLesson.air_at || '').replace(' ', 'T').slice(0, 16)} onChange={(e) => patchSel('air_at', e.target.value)} />
              </label>
              <div className="admin__edit-btns">
                <button className="btn btn-orange btn-sm" onClick={() => saveLesson(selLesson)}><Save size={14} /> Сохранить урок</button>
                <button className="btn admin__delbtn btn-sm" onClick={() => delLesson(selLesson)}><Trash2 size={14} /> Удалить</button>
              </div>
            </div>
          </section>

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
                <button className="admin__iconbtn admin__iconbtn--danger" onClick={() => delMat(mt.id)}><Trash2 size={14} /></button>
              </div>
            )} />
          </section>

          <section className="admin__panel">
            <div className="admin__panel-head">
              <h2><Clock size={17} /> Тайм-коды</h2>
              <button className="btn btn-light btn-sm" onClick={addTc}><Plus size={14} /> Добавить</button>
            </div>
            {timecodes.length === 0 && <div className="admin__empty admin__empty--sm">Нет тайм-кодов</div>}
            <ReorderList entity="timecodes" items={timecodes} onChange={setTimecodes} renderRow={(tc) => (
              <div className="admin__drow">
                <b className="admin__tc-t">{tc.t}</b><span className="admin__drow-name">{tc.label}</span>
                <button className="admin__iconbtn admin__iconbtn--danger" onClick={() => delTc(tc.id)}><Trash2 size={14} /></button>
              </div>
            )} />
          </section>

          <section className="admin__panel">
            <div className="admin__panel-head">
              <h2>Домашние задания</h2>
              <div className="admin__head-btns">
                <button className="btn btn-light btn-sm" onClick={() => addHomework('normal')}><Plus size={14} /> обычное</button>
                <button className="btn btn-light btn-sm" onClick={() => addHomework('hard')}><Plus size={14} /> повышенное</button>
              </div>
            </div>
            {homeworks.length === 0 && <div className="admin__empty admin__empty--sm">Нет ДЗ на этом уроке</div>}
            <ReorderList entity="homeworks" items={homeworks} onChange={setHomeworks} renderRow={(hw) => (
              <div className="admin__drow">
                {hw.level === 'hard' && <span className="admin__ltype admin__ltype--webinar">повыш.</span>}
                <input className="admin__inp" value={hw.title} onChange={(e) => patchHw(hw.id, 'title', e.target.value)} />
                <input className="admin__inp admin__inp--deadline" value={hw.deadline || ''} onChange={(e) => patchHw(hw.id, 'deadline', e.target.value)} placeholder="дедлайн" />
                <button className="admin__iconbtn" title="Сохранить" onClick={() => saveHomework(hw)}><Save size={15} /></button>
                <button className="admin__iconbtn admin__iconbtn--danger" title="Удалить" onClick={() => delHomework(hw)}><Trash2 size={15} /></button>
                <button className="admin__open" onClick={() => setQModal(hw)}>Вопросы <ChevronRight size={15} /></button>
              </div>
            )} />
          </section>
        </>
      )}

      {qModal && <AdminQuestionsModal parent={{ key: 'homework_id', id: qModal.id, title: qModal.title, level: qModal.level }} onClose={() => setQModal(null)} />}
    </div>
  )
}
