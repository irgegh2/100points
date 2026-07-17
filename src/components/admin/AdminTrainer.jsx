import { useEffect, useState } from 'react'
import { Plus, Trash2, Save } from 'lucide-react'
import { catList, catCreate, catUpdate, catDelete } from '../../api.js'
import AdminFold from './AdminFold.jsx'
import { useDangerConfirm } from './DangerConfirm.jsx'

const MODELS = ['deepseek/deepseek-v4-flash', 'deepseek/deepseek-chat-v3.1', 'qwen/qwen3-max', 'google/gemini-3.5-flash', 'openai/gpt-5-mini', 'anthropic/claude-opus-4.8-fast']

export default function AdminTrainer() {
  const confirmDelete = useDangerConfirm()
  const [subjects, setSubjects] = useState([])
  const [subjectId, setSubjectId] = useState('')
  const [tab, setTab] = useState('tasks')     // tasks | qa
  const [tasks, setTasks] = useState([])
  const [qa, setQa] = useState([])
  const [tForm, setTForm] = useState({ level: 1, text: '', answer: '', solution: '', model: MODELS[0] })
  const [qForm, setQForm] = useState({ level: 1, name: '', examples: '', model: MODELS[0] })
  const [msg, setMsg] = useState(null)
  const [busy, setBusy] = useState(false)

  const loadSubjects = () => catList('subjects').then((d) => { setSubjects(d.items || []); if (!subjectId && d.items?.length) setSubjectId(d.items[0].id) })
  const load = (sid) => {
    if (!sid) return
    catList('trainer_tasks', { subject_id: sid }).then((d) => setTasks(d.items || []))
    catList('trainer_qa_topics', { subject_id: sid }).then((d) => setQa(d.items || []))
  }
  useEffect(() => { loadSubjects() }, [])
  useEffect(() => { load(subjectId) }, [subjectId])

  // задачи (режим «Решение задач»)
  const tset = (k) => (e) => setTForm((f) => ({ ...f, [k]: e.target.value }))
  const createTask = async (e) => {
    e.preventDefault()
    if (!tForm.text.trim() || !tForm.answer.trim()) return setMsg({ type: 'err', text: 'Заполни текст и эталонный ответ' })
    setBusy(true); setMsg(null)
    try { const d = await catCreate('trainer_tasks', { ...tForm, subject_id: subjectId, position: tasks.length }); setTasks((list) => [...list, d.item]); setTForm((f) => ({ ...f, text: '', answer: '', solution: '' })); setMsg({ type: 'ok', text: 'Задача добавлена' }) }
    catch (err) { setMsg({ type: 'err', text: err.message }) } finally { setBusy(false) }
  }
  const saveTask = (t) => catUpdate('trainer_tasks', { id: t.id, level: t.level, text: t.text, answer: t.answer, solution: t.solution, model: t.model }).catch((e) => alert(e.message))
  const delTask = async (t) => {
    if (!await confirmDelete({ title: 'Удалить задачу?', target: (t.text || 'Задача без текста').slice(0, 120), consequence: 'Задача пропадёт из ИИ-тренажёра и больше не будет выдаваться ученикам.' })) return
    try { await catDelete('trainer_tasks', t.id); setTasks((list) => list.filter((x) => x.id !== t.id)) } catch (e) { alert(e.message) }
  }
  const patchTask = (id, k, v) => setTasks((a) => a.map((x) => x.id === id ? { ...x, [k]: v } : x))

  // темы (режим «Вопрос-ответ»)
  const qset = (k) => (e) => setQForm((f) => ({ ...f, [k]: e.target.value }))
  const createQa = async (e) => {
    e.preventDefault()
    if (!qForm.name.trim()) return setMsg({ type: 'err', text: 'Введи название темы' })
    setBusy(true); setMsg(null)
    try { const d = await catCreate('trainer_qa_topics', { ...qForm, subject_id: subjectId, position: qa.length }); setQa((list) => [...list, d.item]); setQForm((f) => ({ ...f, name: '', examples: '' })); setMsg({ type: 'ok', text: 'Тема добавлена' }) }
    catch (err) { setMsg({ type: 'err', text: err.message }) } finally { setBusy(false) }
  }
  const saveQa = (t) => catUpdate('trainer_qa_topics', { id: t.id, level: t.level, name: t.name, examples: t.examples, model: t.model }).catch((e) => alert(e.message))
  const delQa = async (t) => {
    if (!await confirmDelete({ title: 'Удалить тему тренажёра?', target: `Тема «${t.name}»`, consequence: 'Тема и её настройки генерации больше не будут доступны ученикам.' })) return
    try { await catDelete('trainer_qa_topics', t.id); setQa((list) => list.filter((x) => x.id !== t.id)) } catch (e) { alert(e.message) }
  }
  const patchQa = (id, k, v) => setQa((a) => a.map((x) => x.id === id ? { ...x, [k]: v } : x))

  return (
    <div className="admin-wrap admin__editor">
      <datalist id="ai-models">{MODELS.map((m) => <option key={m} value={m} />)}</datalist>
      <section className="admin__panel">
        <div className="admin__panel-head">
          <div className="admin__modes">
            <label className={`bank__mode ${tab === 'tasks' ? 'active' : ''}`}><input type="radio" checked={tab === 'tasks'} onChange={() => setTab('tasks')} /> Задачи (Решение задач)</label>
            <label className={`bank__mode ${tab === 'qa' ? 'active' : ''}`}><input type="radio" checked={tab === 'qa'} onChange={() => setTab('qa')} /> Темы (Вопрос-ответ)</label>
          </div>
          <label className="admin__field" style={{ minWidth: 200 }}>Предмет
            <select className="admin__inp" value={subjectId} onChange={(e) => setSubjectId(Number(e.target.value))}>{subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
          </label>
        </div>
      </section>

      {tab === 'tasks' ? (
        <div className="admin__cols">
          <section className="admin__panel">
            <h2><Plus size={18} /> Новая задача</h2>
            <p className="admin__hint">ИИ сверяет ответ ученика с эталоном и объясняет ошибку. Модель: простым — дешёвую, сложным — мощную.</p>
            <form className="admin__form" onSubmit={createTask}>
              <div className="admin__row2">
                <label>Уровень (1–5) <input type="number" min={1} max={5} value={tForm.level} onChange={tset('level')} /></label>
                <label>Модель ИИ <input list="ai-models" value={tForm.model} onChange={tset('model')} /></label>
              </div>
              <label>Текст задачи <textarea className="admin__textarea" rows={3} value={tForm.text} onChange={tset('text')} /></label>
              <label>Эталонный ответ <input value={tForm.answer} onChange={tset('answer')} placeholder="Правильный ответ (для ИИ)" /></label>
              <label>Решение <span>(необязательно)</span><textarea className="admin__textarea" rows={2} value={tForm.solution} onChange={tset('solution')} /></label>
              {msg && <div className={`admin__msg admin__msg--${msg.type}`}>{msg.text}</div>}
              <button className="btn btn-orange btn-lg" disabled={busy}>{busy ? 'Добавляем…' : 'Добавить задачу'}</button>
            </form>
          </section>
          <section className="admin__panel">
            <h2>Задачи <span className="admin__count">{tasks.length}</span></h2>
            <div className="admin__list">
              {tasks.length === 0 && <div className="admin__empty">Нет задач</div>}
              {tasks.map((t) => (
                <AdminFold key={t.id} title={(t.text || 'Без текста').slice(0, 100)} meta={`Уровень ${t.level || 1}`}>
                  <div className="admin__qcard-head">
                    <label className="admin__chk">ур. <input className="admin__inp admin__inp--n" type="number" min={1} max={5} value={t.level ?? 1} onChange={(e) => patchTask(t.id, 'level', e.target.value)} /></label>
                    <input className="admin__inp" value={t.model || ''} onChange={(e) => patchTask(t.id, 'model', e.target.value)} list="ai-models" />
                    <button className="admin__iconbtn" onClick={() => saveTask(t)}><Save size={15} /></button>
                    <button className="admin__iconbtn admin__iconbtn--danger" onClick={() => delTask(t)}><Trash2 size={15} /></button>
                  </div>
                  <label className="admin__field">Текст задачи <textarea className="admin__textarea" rows={5} value={t.text} onChange={(e) => patchTask(t.id, 'text', e.target.value)} /></label>
                  <label className="admin__field">Эталонный ответ <input className="admin__inp" value={t.answer || ''} onChange={(e) => patchTask(t.id, 'answer', e.target.value)} /></label>
                  <label className="admin__field">Решение <span>(необязательно)</span><textarea className="admin__textarea" rows={3} value={t.solution || ''} onChange={(e) => patchTask(t.id, 'solution', e.target.value)} /></label>
                </AdminFold>
              ))}
            </div>
          </section>
        </div>
      ) : (
        <div className="admin__cols">
          <section className="admin__panel">
            <h2><Plus size={18} /> Новая тема для ИИ</h2>
            <p className="admin__hint">ИИ берёт случайную тему и ГЕНЕРИРУЕТ по ней вопрос с вариантами. В «примерах» опиши, что и как спрашивать.</p>
            <form className="admin__form" onSubmit={createQa}>
              <div className="admin__row2">
                <label>Уровень (1–5) <input type="number" min={1} max={5} value={qForm.level} onChange={qset('level')} /></label>
                <label>Модель ИИ <input list="ai-models" value={qForm.model} onChange={qset('model')} /></label>
              </div>
              <label>Название темы <input value={qForm.name} onChange={qset('name')} placeholder="Например, Проценты и доли" /></label>
              <label>Примеры / что спрашивать <textarea className="admin__textarea" rows={3} value={qForm.examples} onChange={qset('examples')} placeholder="Задачи на проценты от числа, скидки. Числовой ответ." /></label>
              {msg && <div className={`admin__msg admin__msg--${msg.type}`}>{msg.text}</div>}
              <button className="btn btn-orange btn-lg" disabled={busy}>{busy ? 'Добавляем…' : 'Добавить тему'}</button>
            </form>
          </section>
          <section className="admin__panel">
            <h2>Темы <span className="admin__count">{qa.length}</span></h2>
            <div className="admin__list">
              {qa.length === 0 && <div className="admin__empty">Нет тем</div>}
              {qa.map((t) => (
                <AdminFold key={t.id} title={t.name || 'Без названия'} meta={`Уровень ${t.level || 1}`}>
                  <div className="admin__qcard-head">
                    <label className="admin__chk">ур. <input className="admin__inp admin__inp--n" type="number" min={1} max={5} value={t.level ?? 1} onChange={(e) => patchQa(t.id, 'level', e.target.value)} /></label>
                    <input className="admin__inp" value={t.name} onChange={(e) => patchQa(t.id, 'name', e.target.value)} />
                    <button className="admin__iconbtn" onClick={() => saveQa(t)}><Save size={15} /></button>
                    <button className="admin__iconbtn admin__iconbtn--danger" onClick={() => delQa(t)}><Trash2 size={15} /></button>
                  </div>
                  <label className="admin__field">Примеры / что спрашивать <textarea className="admin__textarea" rows={4} value={t.examples || ''} onChange={(e) => patchQa(t.id, 'examples', e.target.value)} placeholder="Что спрашивать" /></label>
                  <input className="admin__inp" value={t.model || ''} onChange={(e) => patchQa(t.id, 'model', e.target.value)} list="ai-models" placeholder="модель" />
                </AdminFold>
              ))}
            </div>
          </section>
        </div>
      )}
    </div>
  )
}
