import { useCallback, useEffect, useRef, useState } from 'react'
import { X, Plus, Trash2, Save, ChevronRight } from 'lucide-react'
import { catList, catCreate, catUpdate, catDelete } from '../../api.js'

const TYPES = [['single', 'Один из вариантов'], ['multi', 'Несколько вариантов'], ['text', 'Короткий ответ (строка)'], ['textarea', 'Развёрнутый (ручная проверка)'], ['file', 'Развёрнутый + файл(ы)']]

// Преобразование строки БД → форма редактирования.
function toForm(q) {
  const options = q.options ? safeJSON(q.options, []) : []
  let correctSet = [], accepted = []
  const c = q.correct ? safeJSON(q.correct, null) : null
  if (q.type === 'single') correctSet = Array.isArray(c) ? c : (c != null ? [c] : [])
  else if (q.type === 'multi') correctSet = Array.isArray(c) ? c : []
  else if (q.type === 'text') accepted = Array.isArray(c) ? c : (c != null ? [String(c)] : [])
  return {
    id: q.id, type: q.type, text: q.text || '', points: q.points ?? 1,
    optional: !!q.optional, hard: !!q.hard, solution: q.solution || '',
    case_sensitive: !!q.case_sensitive, grade_mode: q.grade_mode || 'full',
    file_limit: Math.max(1, Math.min(10, Number(q.file_limit) || 1)),
    options: options.length ? options : ['', ''], correctSet, accepted: accepted.length ? accepted : [''],
    _dirty: false, _rev: 0,
  }
}
function safeJSON(s, d) { try { const v = JSON.parse(s); return v ?? d } catch { return d } }

// Форма → payload для catCreate/catUpdate (options/correct как JSON-строки).
// parentKey — 'homework_id' (ДЗ) или 'mock_id' (пробник).
function toPayload(f, parentKey, parentId, position) {
  const p = {
    [parentKey]: parentId, type: f.type, text: f.text, points: f.points,
    optional: f.optional ? 1 : 0, hard: f.hard ? 1 : 0, solution: f.solution,
    case_sensitive: f.case_sensitive ? 1 : 0, grade_mode: f.grade_mode, position,
    file_limit: Math.max(1, Math.min(10, Number(f.file_limit) || 1)),
  }
  if (f.type === 'single' || f.type === 'multi') {
    p.options = JSON.stringify(f.options.map((o) => o.trim()).filter(Boolean))
    p.correct = JSON.stringify(f.correctSet)
  } else if (f.type === 'text') {
    p.options = null
    p.correct = JSON.stringify(f.accepted.map((a) => a.trim()).filter(Boolean))
  } else {
    p.options = null; p.correct = null
  }
  return p
}

// parent: { key: 'homework_id'|'mock_id', id, title, level? }
export default function AdminQuestionsModal({ parent, onClose }) {
  const types = parent.key === 'subtopic_id' ? TYPES.filter(([value]) => value !== 'file') : TYPES
  const [items, setItems] = useState([])
  const [busy, setBusy] = useState(false)
  const [saving, setSaving] = useState(false)
  const [saveState, setSaveState] = useState('saved')
  const [openId, setOpenId] = useState(null)
  const itemsRef = useRef([])

  const load = () => catList('questions', { [parent.key]: parent.id }).then((d) => setItems((d.items || []).map(toForm)))
  useEffect(() => { load() }, [])
  useEffect(() => { itemsRef.current = items }, [items])

  const patch = (id, k, v) => {
    setSaveState('dirty')
    setItems((list) => list.map((q) => q.id === id ? { ...q, [k]: v, _dirty: true, _rev: q._rev + 1 } : q))
  }

  const persist = useCallback(async (entries) => {
    if (!entries.length || saving) return false
    setSaving(true); setSaveState('saving')
    try {
      const saved = await Promise.all(entries.map(({ q, position }) =>
        catUpdate('questions', { id: q.id, ...toPayload(q, parent.key, parent.id, position) }).then(() => ({ id: q.id, rev: q._rev }))
      ))
      const versions = new Map(saved.map((x) => [x.id, x.rev]))
      setItems((list) => list.map((q) => versions.get(q.id) === q._rev ? { ...q, _dirty: false } : q))
      const newerChanges = itemsRef.current.some((q) => q._dirty && (!versions.has(q.id) || versions.get(q.id) !== q._rev))
      setSaveState(newerChanges ? 'dirty' : 'saved')
      return true
    } catch (e) {
      setSaveState('error'); alert(e.message); return false
    } finally { setSaving(false) }
  }, [parent.id, parent.key, saving])

  const dirtyEntries = useCallback((list = itemsRef.current) => list
    .map((q, position) => ({ q, position }))
    .filter(({ q }) => q._dirty), [])

  useEffect(() => {
    if (saving || !items.some((q) => q._dirty)) return undefined
    const timer = setTimeout(() => persist(dirtyEntries(items)), 650)
    return () => clearTimeout(timer)
  }, [dirtyEntries, items, persist, saving])

  useEffect(() => {
    const warn = (e) => { if (itemsRef.current.some((q) => q._dirty) || saving) { e.preventDefault(); e.returnValue = '' } }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [saving])

  const addQuestion = async () => {
    setBusy(true)
    try {
      const d = await catCreate('questions', toPayload({ type: 'single', text: 'Новый вопрос', points: 1, optional: false, hard: false, solution: '', case_sensitive: false, grade_mode: 'full', file_limit: 1, options: ['', ''], correctSet: [], accepted: [''] }, parent.key, parent.id, itemsRef.current.length))
      setItems((list) => [...list, toForm(d.item)]); setOpenId(d.item.id)
    } catch (e) { alert(e.message) } finally { setBusy(false) }
  }
  const save = (f) => persist([{ q: f, position: itemsRef.current.findIndex((q) => q.id === f.id) }])
  const saveAll = () => persist(dirtyEntries())
  const remove = async (f) => { if (confirm('Удалить вопрос?')) { await catDelete('questions', f.id); setItems((list) => list.filter((q) => q.id !== f.id)); if (openId === f.id) setOpenId(null) } }

  // редакторы вариантов
  const setOpt = (q, i, v) => patch(q.id, 'options', q.options.map((o, j) => j === i ? v : o))
  const addOpt = (q) => patch(q.id, 'options', [...q.options, ''])
  const delOpt = (q, i) => patch(q.id, 'options', q.options.filter((_, j) => j !== i))
  const toggleCorrect = (q, i) => {
    if (q.type === 'single') patch(q.id, 'correctSet', q.correctSet.includes(i) ? q.correctSet.filter((x) => x !== i) : [...q.correctSet, i])
    else patch(q.id, 'correctSet', q.correctSet.includes(i) ? q.correctSet.filter((x) => x !== i) : [...q.correctSet, i])
  }
  const setAcc = (q, i, v) => patch(q.id, 'accepted', q.accepted.map((a, j) => j === i ? v : a))
  const addAcc = (q) => patch(q.id, 'accepted', [...q.accepted, ''])
  const delAcc = (q, i) => patch(q.id, 'accepted', q.accepted.filter((_, j) => j !== i))

  return (
    <div className="admin__modal" onClick={onClose}>
      <div className="admin__modal-box admin__modal-box--wide" onClick={(e) => e.stopPropagation()}>
        <div className="admin__modal-head">
          <h3>Вопросы · {parent.title} {parent.level === 'hard' && <span className="admin__rolebadge">повышенный</span>}</h3>
          <div className="admin__qsavebar">
            <span className={`admin__savestate is-${saveState}`}>{saveState === 'saving' ? 'Сохраняем…' : saveState === 'dirty' ? 'Есть изменения' : saveState === 'error' ? 'Ошибка сохранения' : 'Всё сохранено'}</span>
            <button className="btn btn-light" onClick={saveAll} disabled={saving || !items.some((q) => q._dirty)}><Save size={15} /> Сохранить всё</button>
            <button className="admin__iconbtn" onClick={onClose}><X size={18} /></button>
          </div>
        </div>

        {items.length === 0 && <div className="admin__empty">Пока нет вопросов</div>}
        {items.map((q, idx) => (
          <div className={`admin__qcard admin__qcard--fold ${openId === q.id ? 'is-open' : ''}`} key={q.id}>
            <button className="admin__qsummary" onClick={() => setOpenId(openId === q.id ? null : q.id)} aria-expanded={openId === q.id}>
              <span className="admin__qsummary-caret"><ChevronRight size={16} /></span>
              <span className="admin__qnum">№{idx + 1}</span>
              <span className="admin__qsummary-text">{q.text || 'Без текста'}</span>
              <span className="admin__qsummary-type">{TYPES.find(([v]) => v === q.type)?.[1] || q.type}</span>
              {q._dirty && <span className="admin__qsummary-dirty">не сохранён</span>}
            </button>
            {openId === q.id && <div className="admin__qcard-body">
            <div className="admin__qcard-head">
              <select className="admin__inp admin__inp--sm" style={{ width: 'auto' }} value={q.type} onChange={(e) => patch(q.id, 'type', e.target.value)}>
                {types.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
              <label className="admin__chk"><input type="checkbox" checked={q.optional} onChange={(e) => patch(q.id, 'optional', e.target.checked)} /> необяз.</label>
              <label className="admin__chk"><input type="checkbox" checked={q.hard} onChange={(e) => patch(q.id, 'hard', e.target.checked)} /> сложный</label>
              <label className="admin__chk">баллы <input className="admin__inp admin__inp--n" type="number" min={0} value={q.points} onChange={(e) => patch(q.id, 'points', e.target.value)} /></label>
              <button className={`admin__qsave-one ${q._dirty ? 'is-dirty' : ''}`} title="Сохранить вопрос" onClick={() => save(q)} disabled={saving || !q._dirty}><Save size={15} /> Сохранить</button>
              <button className="admin__iconbtn admin__iconbtn--danger" title="Удалить" onClick={() => remove(q)}><Trash2 size={15} /></button>
            </div>

            <textarea className="admin__textarea" rows={2} value={q.text} onChange={(e) => patch(q.id, 'text', e.target.value)} placeholder="Текст вопроса" />

            {(q.type === 'single' || q.type === 'multi') && (
              <div className="admin__opts">
                <div className="admin__opts-hint">{q.type === 'single' ? 'Отметь верные (можно несколько — засчитается любой)' : 'Отметь все верные'}</div>
                {q.options.map((o, i) => (
                  <div className="admin__opt" key={i}>
                    <label className="admin__opt-correct"><input type="checkbox" checked={q.correctSet.includes(i)} onChange={() => toggleCorrect(q, i)} /></label>
                    <input className="admin__inp" value={o} onChange={(e) => setOpt(q, i, e.target.value)} placeholder={`Вариант ${i + 1}`} />
                    <button className="admin__iconbtn admin__iconbtn--danger" onClick={() => delOpt(q, i)}><Trash2 size={14} /></button>
                  </div>
                ))}
                <button className="admin__addgoal" onClick={() => addOpt(q)}><Plus size={13} /> вариант</button>
                {q.type === 'multi' && (
                  <label className="admin__field">Режим оценки
                    <select className="admin__inp" value={q.grade_mode} onChange={(e) => patch(q.id, 'grade_mode', e.target.value)}>
                      <option value="full">Полные баллы (всё или ничего)</option>
                      <option value="partial">Часть баллов (+1 верный, −1 неверный)</option>
                    </select>
                  </label>
                )}
              </div>
            )}

            {q.type === 'text' && (
              <div className="admin__opts">
                <div className="admin__opts-hint">Допустимые ответы (любой засчитается)</div>
                {q.accepted.map((a, i) => (
                  <div className="admin__opt" key={i}>
                    <input className="admin__inp" value={a} onChange={(e) => setAcc(q, i, e.target.value)} placeholder={`Вариант ${i + 1}`} />
                    <button className="admin__iconbtn admin__iconbtn--danger" onClick={() => delAcc(q, i)}><Trash2 size={14} /></button>
                  </div>
                ))}
                <button className="admin__addgoal" onClick={() => addAcc(q)}><Plus size={13} /> вариант</button>
                <label className="admin__chk"><input type="checkbox" checked={q.case_sensitive} onChange={(e) => patch(q.id, 'case_sensitive', e.target.checked)} /> учитывать регистр</label>
              </div>
            )}

            {q.type === 'file' && (
              <div className="admin__file-config">
                <label className="admin__field">Сколько файлов обязан приложить ученик
                  <input className="admin__inp admin__inp--n" type="number" min={1} max={10} value={q.file_limit} onChange={(e) => patch(q.id, 'file_limit', e.target.value)} />
                </label>
                <p className="admin__hint">Ученик загрузит ровно это количество файлов и напишет развёрнутую подпись для проверяющего. Ответ попадёт в «Проверку работ».</p>
              </div>
            )}

            {!['textarea', 'file'].includes(q.type) && (
              <label className="admin__field">Решение (показывается после сдачи)
                <textarea className="admin__textarea" rows={2} value={q.solution} onChange={(e) => patch(q.id, 'solution', e.target.value)} placeholder="Почему именно так" />
              </label>
            )}
            {q.type === 'textarea' && <p className="admin__hint">Развёрнутый ответ проверяется вручную в разделе «Проверка работ».</p>}
            </div>}
          </div>
        ))}

        <button className="btn btn-orange btn-lg" onClick={addQuestion} disabled={busy}><Plus size={16} /> Добавить вопрос</button>
      </div>
    </div>
  )
}
