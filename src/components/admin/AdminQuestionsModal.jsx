import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  AlertCircle, ArrowDown, ArrowUp, AudioLines, Bold, CheckCircle2, Copy, Eye,
  FileText, Image as ImageIcon, Italic, Link2, List, ListOrdered, Plus, Save,
  Search, Sigma, Table2, Trash2, Upload, Video, X,
} from 'lucide-react'
import { catList, catCreate, catUpdate, catDelete, questionAttachmentUpload } from '../../api.js'
import { useDangerConfirm } from './DangerConfirm.jsx'
import AudioMessage from '../AudioMessage.jsx'
import KinescopeVideo from '../KinescopeVideo.jsx'
import MarkdownContent from '../MarkdownContent.jsx'
import { kinescopeEmbedUrl, kinescopeVideoId } from '../../kinescope.js'

const TYPES = [['single', 'Один из вариантов'], ['multi', 'Несколько вариантов'], ['text', 'Короткий ответ'], ['textarea', 'Развёрнутый ответ'], ['file', 'Ответ + файлы'], ['audio', 'Устный ответ']]

const GENERAL_TOOLS = [
  { title: 'Жирный текст', icon: Bold, before: '**', after: '**', fallback: 'важный текст' },
  { title: 'Курсив', icon: Italic, before: '*', after: '*', fallback: 'термин' },
  { title: 'Маркированный список', icon: List, before: '- ', after: '\n- ', fallback: 'первый пункт' },
  { title: 'Нумерованный список', icon: ListOrdered, before: '1. ', after: '\n2. ', fallback: 'первый пункт' },
  { title: 'Ссылка', icon: Link2, before: '[', after: '](https://)', fallback: 'название ссылки' },
  { title: 'Таблица 3 × 3', icon: Table2, raw: '| Столбец 1 | Столбец 2 | Столбец 3 |\n| --- | --- | --- |\n| Значение | Значение | Значение |\n| Значение | Значение | Значение |' },
]

const MATH_TOOLS = [
  { label: 'x²', title: 'Степень', before: '$', after: '^{2}$', fallback: 'x' },
  { label: 'a⁄b', title: 'Дробь', raw: '$\\frac{a}{b}$', select: [7, 8] },
  { label: '√x', title: 'Корень', before: '$\\sqrt{', after: '}$', fallback: 'x' },
  { label: '±', title: 'Плюс-минус', raw: '$\\pm$' },
  { label: '×', title: 'Умножение', raw: '$\\cdot$' },
  { label: 'π', title: 'Число пи', raw: '$\\pi$' },
  { label: 'α', title: 'Альфа', raw: '$\\alpha$' },
  { label: '°', title: 'Градусы', before: '$', after: '^\\circ$', fallback: '30' },
  { label: 'sin', title: 'Синус', before: '$\\sin(', after: ')$', fallback: 'x' },
  { label: 'log', title: 'Логарифм', raw: '$\\log_{a}{b}$' },
  { label: '|x|', title: 'Модуль', before: '$\\left|', after: '\\right|$', fallback: 'x' },
  { label: '{…', title: 'Система уравнений', raw: '$$\\begin{cases}x+y=0\\\\x-y=2\\end{cases}$$' },
]

function safeJSON(s, d) { try { const v = JSON.parse(s); return v ?? d } catch { return d } }

function toForm(q) {
  const options = q.options ? safeJSON(q.options, []) : []
  let correctSet = [], accepted = []
  const c = q.correct ? safeJSON(q.correct, null) : null
  if (q.type === 'single') correctSet = Array.isArray(c) ? c : (c != null ? [c] : [])
  else if (q.type === 'multi') correctSet = Array.isArray(c) ? c : []
  else if (q.type === 'text') accepted = Array.isArray(c) ? c : (c != null ? [String(c)] : [])
  return {
    id: q.id, type: q.type, text: q.text || '', points: q.points ?? 1,
    optional: !!q.optional, hard: !!q.hard, solution: q.solution || '', hint: q.hint || '',
    case_sensitive: !!q.case_sensitive, grade_mode: q.grade_mode || 'full',
    file_limit: Math.max(1, Math.min(10, Number(q.file_limit) || 1)),
    options: options.length ? options : ['', ''], correctSet, accepted: accepted.length ? accepted : [''],
    _dirty: false, _rev: 0,
  }
}

function toPayload(f, parentKey, parentId, position) {
  const p = {
    [parentKey]: parentId, type: f.type, text: f.text, points: Number(f.points) || 0,
    optional: f.optional ? 1 : 0, hard: f.hard ? 1 : 0, solution: f.solution, hint: f.hint,
    case_sensitive: f.case_sensitive ? 1 : 0, grade_mode: f.grade_mode, position,
    file_limit: Math.max(1, Math.min(10, Number(f.file_limit) || 1)),
  }
  if (f.type === 'single' || f.type === 'multi') {
    const kept = f.options.map((option, index) => ({ option: option.trim(), index })).filter((item) => item.option)
    const indexMap = new Map(kept.map((item, index) => [item.index, index]))
    p.options = JSON.stringify(kept.map((item) => item.option))
    p.correct = JSON.stringify(f.correctSet.filter((index) => indexMap.has(index)).map((index) => indexMap.get(index)))
  } else if (f.type === 'text') {
    p.options = null
    p.correct = JSON.stringify(f.accepted.map((answer) => answer.trim()).filter(Boolean))
  } else {
    p.options = null
    p.correct = null
  }
  return p
}

function questionIssue(q) {
  if (!q.text.trim()) return 'Нет текста вопроса'
  if (['single', 'multi'].includes(q.type)) {
    if (q.options.filter((option) => option.trim()).length < 2) return 'Нужно минимум 2 варианта'
    if (!q.correctSet.some((index) => q.options[index]?.trim())) return 'Не отмечен верный ответ'
  }
  if (q.type === 'text' && !q.accepted.some((answer) => answer.trim())) return 'Нет автоматического ответа'
  return ''
}

function RichTextarea({ label, value, onChange, placeholder, rows = 5, compact = false }) {
  const textareaRef = useRef(null)
  const insert = (tool) => {
    const node = textareaRef.current
    if (!node) return
    const start = node.selectionStart ?? value.length
    const end = node.selectionEnd ?? start
    const selected = value.slice(start, end)
    const content = tool.raw ?? `${tool.before || ''}${selected || tool.fallback || ''}${tool.after || ''}`
    const multiline = tool.raw?.includes('\n')
    const spacerBefore = start > 0 && !value.slice(0, start).endsWith('\n') && multiline ? '\n' : ''
    const spacerAfter = end < value.length && !value.slice(end).startsWith('\n') && multiline ? '\n' : ''
    onChange(`${value.slice(0, start)}${spacerBefore}${content}${spacerAfter}${value.slice(end)}`)
    const selectionStart = start + spacerBefore.length + (tool.select?.[0] ?? (tool.before?.length || 0))
    const selectionEnd = tool.select ? start + spacerBefore.length + tool.select[1] : selectionStart + (selected || tool.fallback || '').length
    requestAnimationFrame(() => { node.focus(); node.setSelectionRange(selectionStart, selectionEnd) })
  }

  return (
    <label className={`hw-editor__field ${compact ? 'is-compact' : ''}`}>
      {label && <span className="hw-editor__field-label">{label}</span>}
      <div className="hw-editor__rich">
        <div className="hw-editor__toolbar" aria-label="Форматирование текста">
          <span className="hw-editor__toolbar-group">
            {GENERAL_TOOLS.map((tool) => { const Icon = tool.icon; return <button type="button" key={tool.title} title={tool.title} onClick={() => insert(tool)}><Icon size={15} /></button> })}
          </span>
          <span className="hw-editor__toolbar-divider" />
          <span className="hw-editor__toolbar-math"><Sigma size={15} /><b>Математика</b></span>
          <span className="hw-editor__toolbar-group hw-editor__toolbar-group--math">
            {MATH_TOOLS.map((tool) => <button type="button" key={tool.title} title={tool.title} onClick={() => insert(tool)}>{tool.label}</button>)}
          </span>
        </div>
        <textarea ref={textareaRef} className="admin__textarea hw-editor__textarea" rows={rows} value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} />
      </div>
    </label>
  )
}

function AttachmentEditor({ q, attach, videoLink, setVideoLink, uploadAttach, delAttach, addVideo }) {
  const images = attach.filter((item) => item.kind === 'image')
  const audio = attach.filter((item) => item.kind === 'audio')
  const videos = attach.filter((item) => item.kind === 'video')
  const files = attach.filter((item) => item.kind === 'file')
  return (
    <details className="hw-editor__section" open={attach.length > 0}>
      <summary><span><ImageIcon size={16} /> Материалы к вопросу</span><small>{attach.length ? `${attach.length} влож.` : 'картинки, аудио, видео, файлы'}</small></summary>
      <div className="admin__qattach hw-editor__attachments">
        <div className="admin__qattach-col"><div className="admin__opts-hint">Картинки — над условием</div><div className="admin__mgallery-grid">{images.map((item) => <div className="admin__mphoto" key={item.id}><img src={item.url} alt="" loading="lazy" /><button className="admin__mphoto-del" title="Удалить картинку" onClick={() => delAttach(item)}><Trash2 size={13} /></button></div>)}{images.length < 10 && <label className="admin__mphoto-add" title="JPG, PNG или WebP"><Upload size={16} /><span>{images.length}/10</span><input type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={(event) => { if (event.target.files[0]) uploadAttach(q, 'image', event.target.files[0]); event.target.value = '' }} /></label>}</div></div>
        <div className="admin__qattach-col"><div className="admin__opts-hint">Аудио — как голосовое сообщение</div>{audio.map((item) => <div className="admin__qaudio" key={item.id}><AudioMessage src={item.url} name={item.name} /><button className="admin__iconbtn admin__iconbtn--danger" title="Удалить аудио" onClick={() => delAttach(item)}><Trash2 size={14} /></button></div>)}{audio.length < 10 && <label className="btn btn-light btn-sm admin__qfile-add"><AudioLines size={14} /> Загрузить аудио<input type="file" accept="audio/mpeg,audio/mp4,audio/x-m4a,audio/ogg,audio/wav,audio/x-wav,audio/flac" hidden onChange={(event) => { if (event.target.files[0]) uploadAttach(q, 'audio', event.target.files[0]); event.target.value = '' }} /></label>}</div>
        <div className="admin__qattach-col admin__qattach-col--video"><div className="admin__opts-hint">Видео — ссылка Kinescope</div>{videos.map((item) => <div className="admin__qvideo" key={item.id}><KinescopeVideo url={item.url} title="Предпросмотр видео" /><div className="admin__qvideo-meta"><Video size={14} /><span>Kinescope · {kinescopeVideoId(item.url)}</span></div><button className="admin__qvideo-del admin__iconbtn admin__iconbtn--danger" title="Удалить видео" onClick={() => delAttach(item)}><Trash2 size={14} /></button></div>)}{videos.length < 3 && <div className="admin__qvideo-add"><div className="admin__qvideo-input"><Link2 size={15} /><input value={videoLink} onChange={(event) => setVideoLink(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); addVideo(q) } }} placeholder="https://kinescope.io/…" /></div><button className="btn btn-light btn-sm" type="button" onClick={() => addVideo(q)}><Plus size={14} /> Прикрепить</button></div>}</div>
        <div className="admin__qattach-col"><div className="admin__opts-hint">Файлы — под условием</div>{files.map((item) => <div className="admin__qfile" key={item.id}><FileText size={15} /><span className="admin__qfile-name">{item.name} <span className="admin__qfile-size">{item.size}</span></span><button className="admin__iconbtn admin__iconbtn--danger" title="Удалить файл" onClick={() => delAttach(item)}><Trash2 size={14} /></button></div>)}{files.length < 10 && <label className="btn btn-light btn-sm admin__qfile-add"><Upload size={14} /> Загрузить файл<input type="file" hidden onChange={(event) => { if (event.target.files[0]) uploadAttach(q, 'file', event.target.files[0]); event.target.value = '' }} /></label>}</div>
      </div>
    </details>
  )
}

function QuestionPreview({ q, attach }) {
  return (
    <aside className="hw-editor__preview">
      <div className="hw-editor__preview-head"><Eye size={16} /><span>Как увидит ученик</span></div>
      <div className="hw-editor__student-card">
        <div className="hw-editor__student-meta"><span>{q.hard ? 'Сложное задание' : 'Задание'}</span><b>{q.points} б.</b></div>
        {attach.filter((item) => item.kind === 'image').map((item) => <img className="hw-editor__preview-image" key={item.id} src={item.url} alt="" />)}
        <MarkdownContent source={q.text} empty="Здесь появится текст вопроса" />
        {['single', 'multi'].includes(q.type) && <div className="hw-editor__preview-options">{q.options.filter((option) => option.trim()).map((option, index) => <div key={index}><span>{q.type === 'single' ? '○' : '□'}</span><MarkdownContent source={option} /></div>)}</div>}
        {q.type === 'text' && <div className="hw-editor__preview-input">Короткий ответ ученика</div>}
        {q.type === 'textarea' && <div className="hw-editor__preview-area">Развёрнутый ответ ученика</div>}
        {q.type === 'file' && <div className="hw-editor__preview-area"><Upload size={17} /> Ответ и файлы (до {q.file_limit})</div>}
        {q.type === 'audio' && <div className="hw-editor__preview-area"><AudioLines size={17} /> Записать устный ответ</div>}
        {q.hint && <details className="hw-editor__preview-note"><summary>Подсказка</summary><MarkdownContent source={q.hint} /></details>}
      </div>
      {q.solution && <div className="hw-editor__preview-solution"><b>После сдачи: решение</b><MarkdownContent source={q.solution} /></div>}
      <div className="hw-editor__syntax-help"><b>Шпаргалка</b><span><code>$x^2$</code> — формула в строке</span><span><code>$$x^2$$</code> — отдельной строкой</span><span><code>**текст**</code> — жирный</span><span>Таблицу проще вставить кнопкой <Table2 size={12} /></span></div>
    </aside>
  )
}

export default function AdminQuestionsModal({ parent, onClose }) {
  const confirmDelete = useDangerConfirm()
  const types = parent.key === 'subtopic_id' ? TYPES.filter(([value]) => !['file', 'audio'].includes(value)) : TYPES
  const [items, setItems] = useState([])
  const [busy, setBusy] = useState(true)
  const [saving, setSaving] = useState(false)
  const [saveState, setSaveState] = useState('saved')
  const [openId, setOpenId] = useState(null)
  const [search, setSearch] = useState('')
  const [videoLink, setVideoLink] = useState('')
  const [attach, setAttach] = useState([])
  const itemsRef = useRef([])

  const load = useCallback(() => {
    setBusy(true)
    return catList('questions', { [parent.key]: parent.id }).then((data) => {
      const next = (data.items || []).map(toForm)
      setItems(next)
      setOpenId((current) => current && next.some((q) => q.id === current) ? current : next[0]?.id || null)
    }).finally(() => setBusy(false))
  }, [parent.id, parent.key])

  useEffect(() => { load() }, [load])
  useEffect(() => { itemsRef.current = items }, [items])
  useEffect(() => { const previous = document.body.style.overflow; document.body.style.overflow = 'hidden'; return () => { document.body.style.overflow = previous } }, [])

  const patch = (id, key, value) => {
    setSaveState('dirty')
    setItems((list) => list.map((q) => q.id === id ? { ...q, [key]: value, _dirty: true, _rev: q._rev + 1 } : q))
  }

  const persist = useCallback(async (entries) => {
    if (!entries.length || saving) return false
    setSaving(true); setSaveState('saving')
    try {
      const saved = await Promise.all(entries.map(({ q, position }) => catUpdate('questions', { id: q.id, ...toPayload(q, parent.key, parent.id, position) }).then(() => ({ id: q.id, rev: q._rev }))))
      const versions = new Map(saved.map((item) => [item.id, item.rev]))
      setItems((list) => list.map((q) => versions.get(q.id) === q._rev ? { ...q, _dirty: false } : q))
      const newerChanges = itemsRef.current.some((q) => q._dirty && (!versions.has(q.id) || versions.get(q.id) !== q._rev))
      setSaveState(newerChanges ? 'dirty' : 'saved')
      return true
    } catch (error) { setSaveState('error'); alert(error.message); return false } finally { setSaving(false) }
  }, [parent.id, parent.key, saving])

  const dirtyEntries = useCallback((list = itemsRef.current) => list.map((q, position) => ({ q, position })).filter(({ q }) => q._dirty), [])
  const active = items.find((q) => q.id === openId) || null
  const activeIndex = active ? items.findIndex((q) => q.id === active.id) : -1
  const filteredItems = useMemo(() => {
    const needle = search.trim().toLowerCase()
    if (!needle) return items
    return items.filter((q, index) => `${index + 1} ${q.text} ${TYPES.find(([value]) => value === q.type)?.[1] || ''}`.toLowerCase().includes(needle))
  }, [items, search])

  useEffect(() => { if (saving || !items.some((q) => q._dirty)) return undefined; const timer = setTimeout(() => persist(dirtyEntries(items)), 650); return () => clearTimeout(timer) }, [dirtyEntries, items, persist, saving])
  useEffect(() => { const warn = (event) => { if (itemsRef.current.some((q) => q._dirty) || saving) { event.preventDefault(); event.returnValue = '' } }; window.addEventListener('beforeunload', warn); return () => window.removeEventListener('beforeunload', warn) }, [saving])
  useEffect(() => { const shortcut = (event) => { if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') { event.preventDefault(); persist(dirtyEntries()) } }; window.addEventListener('keydown', shortcut); return () => window.removeEventListener('keydown', shortcut) }, [dirtyEntries, persist])
  useEffect(() => {
    let current = true
    if (!openId) { setAttach([]); return () => { current = false } }
    setAttach([]); setVideoLink('')
    catList('question_attachments', { question_id: openId }).then((data) => { if (current) setAttach(data.items || []) }).catch(() => { if (current) setAttach([]) })
    return () => { current = false }
  }, [openId])

  const addQuestion = async () => {
    setBusy(true)
    try {
      const draft = { type: 'single', text: 'Новый вопрос', points: 1, optional: false, hard: false, solution: '', hint: '', case_sensitive: false, grade_mode: 'full', file_limit: 1, options: ['', ''], correctSet: [], accepted: [''] }
      const data = await catCreate('questions', toPayload(draft, parent.key, parent.id, itemsRef.current.length))
      setItems((list) => [...list, toForm(data.item)]); setOpenId(data.item.id); setSearch('')
    } catch (error) { alert(error.message) } finally { setBusy(false) }
  }
  const duplicate = async (q) => {
    setBusy(true)
    try { const data = await catCreate('questions', toPayload({ ...q, text: `${q.text} — копия` }, parent.key, parent.id, itemsRef.current.length)); setItems((list) => [...list, toForm(data.item)]); setOpenId(data.item.id); setSearch('') }
    catch (error) { alert(error.message) } finally { setBusy(false) }
  }
  const move = (id, delta) => {
    setItems((list) => {
      const from = list.findIndex((q) => q.id === id); const to = from + delta
      if (from < 0 || to < 0 || to >= list.length) return list
      const next = [...list]; const [picked] = next.splice(from, 1); next.splice(to, 0, picked); setSaveState('dirty')
      return next.map((q) => ({ ...q, _dirty: true, _rev: q._rev + 1 }))
    })
  }
  const remove = async (q) => {
    if (!await confirmDelete({ title: 'Удалить вопрос?', target: (q.text || 'Вопрос без текста').slice(0, 140), consequence: 'Вопрос, варианты ответа и уже прикреплённые ответы учеников будут удалены.' })) return
    try { await catDelete('questions', q.id); setItems((list) => { const index = list.findIndex((item) => item.id === q.id); const next = list.filter((item) => item.id !== q.id); if (openId === q.id) setOpenId(next[Math.min(index, next.length - 1)]?.id || null); return next }) }
    catch (error) { alert(error.message) }
  }
  const requestClose = async () => { if (saving) return; if (itemsRef.current.some((q) => q._dirty)) { const ok = await persist(dirtyEntries()); if (!ok) return } onClose() }

  const uploadAttach = async (q, kind, file) => { try { const data = await questionAttachmentUpload(q.id, kind, file); setAttach((list) => [...list, data.item]) } catch (error) { alert(error.message) } }
  const delAttach = async (item) => {
    const labels = { image: 'Удалить картинку?', audio: 'Удалить аудио?', video: 'Удалить видео?', file: 'Удалить файл?' }
    if (!await confirmDelete({ title: labels[item.kind] || 'Удалить вложение?', target: item.name || 'Вложение', consequence: 'Вложение пропадёт из вопроса у учеников.' })) return
    try { await catDelete('question_attachments', item.id); setAttach((list) => list.filter((entry) => entry.id !== item.id)) } catch (error) { alert(error.message) }
  }
  const addVideo = async (q) => {
    const id = kinescopeVideoId(videoLink)
    if (!id) { alert('Вставь ссылку Kinescope, embed-код или id ролика'); return }
    try { const data = await catCreate('question_attachments', { question_id: q.id, kind: 'video', name: 'Видео к заданию', size: 'Kinescope', url: kinescopeEmbedUrl(id), position: attach.length }); setAttach((list) => [...list, data.item]); setVideoLink('') }
    catch (error) { alert(error.message) }
  }

  const setOpt = (q, index, value) => patch(q.id, 'options', q.options.map((option, current) => current === index ? value : option))
  const delOpt = async (q, index) => {
    if (!await confirmDelete({ title: 'Удалить вариант ответа?', target: q.options[index] || `Пустой вариант ${index + 1}`, consequence: 'Вариант исчезнет из вопроса.' })) return
    const nextCorrect = q.correctSet.filter((value) => value !== index).map((value) => value > index ? value - 1 : value)
    setItems((list) => list.map((item) => item.id === q.id ? { ...item, options: item.options.filter((_, current) => current !== index), correctSet: nextCorrect, _dirty: true, _rev: item._rev + 1 } : item)); setSaveState('dirty')
  }
  const toggleCorrect = (q, index) => patch(q.id, 'correctSet', q.correctSet.includes(index) ? q.correctSet.filter((value) => value !== index) : [...q.correctSet, index])
  const setAcc = (q, index, value) => patch(q.id, 'accepted', q.accepted.map((answer, current) => current === index ? value : answer))

  return (
    <div className="admin__modal admin__modal--workspace">
      <div className="admin__modal-box admin__modal-box--questions">
        <header className="hw-editor__head">
          <div className="hw-editor__title"><span>Редактор заданий</span><h3>{parent.title}</h3>{parent.level && parent.level !== 'basic' && <small>{parent.level === 'hard' ? 'Продвинутый уровень' : 'Средний уровень'}</small>}</div>
          <div className="admin__qsavebar"><span className={`admin__savestate is-${saveState}`}>{saveState === 'saving' ? 'Сохраняем…' : saveState === 'dirty' ? 'Есть изменения' : saveState === 'error' ? 'Ошибка сохранения' : 'Всё сохранено'}</span><button className="btn btn-light" onClick={() => persist(dirtyEntries())} disabled={saving || !items.some((q) => q._dirty)}><Save size={15} /> Сохранить всё <kbd>⌘S</kbd></button><button className="admin__iconbtn hw-editor__close" onClick={requestClose} disabled={saving} title="Закрыть редактор"><X size={19} /></button></div>
        </header>

        <div className="hw-editor__workspace">
          <aside className="hw-editor__navigator">
            <div className="hw-editor__nav-top"><b>{items.length} вопросов</b><button className="btn btn-orange btn-sm" onClick={addQuestion} disabled={busy}><Plus size={15} /> Новый</button></div>
            <label className="hw-editor__search"><Search size={15} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Найти вопрос" />{search && <button onClick={() => setSearch('')}><X size={13} /></button>}</label>
            <div className="hw-editor__question-list">
              {busy && items.length === 0 && <div className="admin__empty">Загружаем вопросы…</div>}
              {!busy && items.length === 0 && <div className="admin__empty">Пока нет вопросов.<br />Создай первый.</div>}
              {filteredItems.map((q) => { const index = items.findIndex((item) => item.id === q.id); const issue = questionIssue(q); return <button key={q.id} className={`hw-editor__question ${openId === q.id ? 'is-active' : ''}`} onClick={() => setOpenId(q.id)}><span className="hw-editor__question-number">{index + 1}</span><span className="hw-editor__question-copy"><b>{q.text || 'Без текста'}</b><small>{TYPES.find(([value]) => value === q.type)?.[1] || q.type}</small></span><span className={`hw-editor__question-state ${issue ? 'has-issue' : ''}`} title={issue || 'Готово'}>{q._dirty ? <i /> : issue ? <AlertCircle size={14} /> : <CheckCircle2 size={14} />}</span></button> })}
              {!busy && items.length > 0 && filteredItems.length === 0 && <div className="admin__empty">Ничего не найдено</div>}
            </div>
            <div className="hw-editor__nav-foot">Автосохранение включено</div>
          </aside>

          <main className="hw-editor__canvas">
            {!active && <div className="hw-editor__blank"><FileText size={34} /><b>Выбери вопрос слева</b><span>или создай новый</span></div>}
            {active && <div className="hw-editor__document">
              <div className="hw-editor__document-head"><div><span>Вопрос {activeIndex + 1} из {items.length}</span>{questionIssue(active) ? <small className="has-issue"><AlertCircle size={13} /> {questionIssue(active)}</small> : <small><CheckCircle2 size={13} /> Готов к публикации</small>}</div><div className="hw-editor__document-actions"><button title="Выше" disabled={activeIndex === 0} onClick={() => move(active.id, -1)}><ArrowUp size={16} /></button><button title="Ниже" disabled={activeIndex === items.length - 1} onClick={() => move(active.id, 1)}><ArrowDown size={16} /></button><button title="Создать копию" onClick={() => duplicate(active)}><Copy size={16} /></button><button className="is-danger" title="Удалить вопрос" onClick={() => remove(active)}><Trash2 size={16} /></button></div></div>
              <div className="hw-editor__settings"><label>Формат ответа<select className="admin__inp" value={active.type} onChange={(event) => patch(active.id, 'type', event.target.value)}>{types.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label><label>Баллы<input className="admin__inp admin__inp--n" type="number" min={0} value={active.points} onChange={(event) => patch(active.id, 'points', event.target.value)} /></label><label className="admin__chk"><input type="checkbox" checked={active.optional} onChange={(event) => patch(active.id, 'optional', event.target.checked)} /> Необязательный</label><label className="admin__chk"><input type="checkbox" checked={active.hard} onChange={(event) => patch(active.id, 'hard', event.target.checked)} /> Сложный</label></div>
              <RichTextarea label="Условие задания" value={active.text} onChange={(value) => patch(active.id, 'text', value)} placeholder="Напиши условие. Для формул используй математическую панель выше." rows={7} />
              <AttachmentEditor q={active} attach={attach} videoLink={videoLink} setVideoLink={setVideoLink} uploadAttach={uploadAttach} delAttach={delAttach} addVideo={addVideo} />

              {['single', 'multi'].includes(active.type) && <section className="hw-editor__answers"><div className="hw-editor__section-title"><b>Варианты ответа</b><span>{active.type === 'single' ? 'Отметь все допустимые правильные ответы' : 'Отметь полный набор правильных ответов'}</span></div>{active.options.map((option, index) => <div className="hw-editor__answer-row" key={index}><label className={`hw-editor__correct ${active.correctSet.includes(index) ? 'is-correct' : ''}`} title="Правильный ответ"><input type="checkbox" checked={active.correctSet.includes(index)} onChange={() => toggleCorrect(active, index)} /><span>{index + 1}</span></label><input className="admin__inp" value={option} onChange={(event) => setOpt(active, index, event.target.value)} placeholder={`Вариант ${index + 1} (формулы через $…$)`} /><button className="admin__iconbtn admin__iconbtn--danger" onClick={() => delOpt(active, index)}><Trash2 size={14} /></button></div>)}<button className="admin__addgoal" onClick={() => patch(active.id, 'options', [...active.options, ''])}><Plus size={13} /> Добавить вариант</button>{active.type === 'multi' && <label className="admin__field">Оценивание<select className="admin__inp" value={active.grade_mode} onChange={(event) => patch(active.id, 'grade_mode', event.target.value)}><option value="full">Всё или ничего</option><option value="partial">Частичные баллы (+1 верный, −1 неверный)</option></select></label>}</section>}
              {active.type === 'text' && <section className="hw-editor__answers"><div className="hw-editor__section-title"><b>Автоматическая проверка</b><span>Любой из вариантов будет засчитан</span></div>{active.accepted.map((answer, index) => <div className="hw-editor__answer-row" key={index}><span className="hw-editor__answer-index">{index + 1}</span><input className="admin__inp" value={answer} onChange={(event) => setAcc(active, index, event.target.value)} placeholder={`Допустимый ответ ${index + 1}`} /><button className="admin__iconbtn admin__iconbtn--danger" onClick={() => patch(active.id, 'accepted', active.accepted.filter((_, current) => current !== index))}><Trash2 size={14} /></button></div>)}<button className="admin__addgoal" onClick={() => patch(active.id, 'accepted', [...active.accepted, ''])}><Plus size={13} /> Ещё вариант</button><label className="admin__chk"><input type="checkbox" checked={active.case_sensitive} onChange={(event) => patch(active.id, 'case_sensitive', event.target.checked)} /> Учитывать регистр букв</label></section>}
              {active.type === 'file' && <div className="admin__file-config"><label className="admin__field">Максимальное количество файлов<input className="admin__inp admin__inp--n" type="number" min={1} max={10} value={active.file_limit} onChange={(event) => patch(active.id, 'file_limit', event.target.value)} /></label><p className="admin__hint">Ученик приложит от одного файла до указанного количества и текстовый комментарий. Ответ отправится на ручную проверку.</p></div>}
              {active.type === 'audio' && <div className="admin__file-config"><p className="admin__hint">Ученик сможет записать ответ с микрофона или загрузить готовый аудиофайл.</p></div>}
              <RichTextarea label="Решение / критерии (показываются после сдачи)" value={active.solution} onChange={(value) => patch(active.id, 'solution', value)} placeholder="Ход решения, эталон ответа или критерии для ручной проверки" rows={5} compact />
              <RichTextarea label="Подсказка (необязательно)" value={active.hint} onChange={(value) => patch(active.id, 'hint', value)} placeholder="Ученик увидит её только после нажатия «Подсказка»" rows={4} compact />
            </div>}
          </main>
          {active && <QuestionPreview q={active} attach={attach} />}
        </div>
      </div>
    </div>
  )
}
