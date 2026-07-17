import { useEffect, useState } from 'react'
import { Plus, Trash2, Save, FolderOpen, ChevronRight } from 'lucide-react'
import { catList, catCreate, catUpdate, catDelete } from '../../api.js'

// База знаний техподдержки: категории → вопросы-ответы. Используется ИИ-чатом «Помощь по платформе».
export default function AdminSupport() {
  const [cats, setCats] = useState([])
  const [activeCat, setActiveCat] = useState(null)
  const [qa, setQa] = useState([])
  const [newCat, setNewCat] = useState('')
  const [qForm, setQForm] = useState({ question: '', answer: '' })
  const [msg, setMsg] = useState(null)
  const [busy, setBusy] = useState(false)

  const loadCats = () => catList('help_categories').then((d) => setCats(d.items || []))
  const loadQa = (cid) => cid && catList('help_qa', { category_id: cid }).then((d) => setQa(d.items || []))
  useEffect(() => { loadCats() }, [])
  useEffect(() => { if (activeCat) loadQa(activeCat) }, [activeCat])

  const addCat = async (e) => {
    e.preventDefault()
    if (!newCat.trim()) return
    setBusy(true)
    try { const d = await catCreate('help_categories', { name: newCat, position: cats.length }); setCats((list) => [...list, d.item]); setNewCat(''); setActiveCat(d.item.id) }
    catch (err) { alert(err.message) } finally { setBusy(false) }
  }
  const renameCat = (c, name) => setCats((a) => a.map((x) => x.id === c.id ? { ...x, name } : x))
  const saveCat = (c) => catUpdate('help_categories', { id: c.id, name: c.name }).catch((e) => alert(e.message))
  const delCat = (c) => confirm(`Удалить категорию «${c.name}» со всеми вопросами?`) && catDelete('help_categories', c.id).then(() => { setCats((list) => list.filter((x) => x.id !== c.id)); if (activeCat === c.id) { setActiveCat(null); setQa([]) } })

  const addQa = async (e) => {
    e.preventDefault()
    if (!qForm.question.trim() || !qForm.answer.trim()) return setMsg({ type: 'err', text: 'Заполни вопрос и ответ' })
    setBusy(true); setMsg(null)
    try { const d = await catCreate('help_qa', { category_id: activeCat, question: qForm.question, answer: qForm.answer, position: qa.length }); setQa((list) => [...list, d.item]); setQForm({ question: '', answer: '' }); setMsg({ type: 'ok', text: 'Вопрос добавлен' }) }
    catch (err) { setMsg({ type: 'err', text: err.message }) } finally { setBusy(false) }
  }
  const patchQa = (id, k, v) => setQa((a) => a.map((x) => x.id === id ? { ...x, [k]: v } : x))
  const saveQa = (q) => catUpdate('help_qa', { id: q.id, question: q.question, answer: q.answer }).catch((e) => alert(e.message))
  const delQa = (q) => confirm('Удалить вопрос?') && catDelete('help_qa', q.id).then(() => setQa((list) => list.filter((x) => x.id !== q.id)))

  const cat = cats.find((c) => c.id === activeCat)

  return (
    <div className="admin__cols">
      <section className="admin__panel">
        <h2><FolderOpen size={18} /> Категории</h2>
        <p className="admin__hint">ИИ-чат «Помощь по платформе» использует эти категории и вопросы, чтобы отвечать ученикам.</p>
        <form className="admin__form" onSubmit={addCat} style={{ flexDirection: 'row', gap: 8 }}>
          <input value={newCat} onChange={(e) => setNewCat(e.target.value)} placeholder="Новая категория (напр. Домашние задания)" style={{ flex: 1 }} />
          <button className="btn btn-orange" disabled={busy}><Plus size={16} /></button>
        </form>
        <div className="admin__list">
          {cats.length === 0 && <div className="admin__empty">Нет категорий</div>}
          {cats.map((c) => (
            <div className={`admin__navrow ${activeCat === c.id ? 'active' : ''}`} key={c.id}>
              <button className="admin__navrow-main" onClick={() => setActiveCat(c.id)}>
                <input className="admin__inp" value={c.name} onClick={(e) => e.stopPropagation()} onChange={(e) => renameCat(c, e.target.value)} onBlur={() => saveCat(c)} />
              </button>
              <button className="admin__iconbtn admin__iconbtn--danger" onClick={() => delCat(c)}><Trash2 size={15} /></button>
              <ChevronRight size={16} className="admin__navrow-arr" onClick={() => setActiveCat(c.id)} />
            </div>
          ))}
        </div>
      </section>

      <section className="admin__panel">
        {!cat ? (
          <div className="admin__empty">← Выбери категорию, чтобы добавить вопросы</div>
        ) : (
          <>
            <h2>«{cat.name}» <span className="admin__count">{qa.length}</span></h2>
            <form className="admin__form" onSubmit={addQa}>
              <label>Вопрос ученика <input value={qForm.question} onChange={(e) => setQForm((f) => ({ ...f, question: e.target.value }))} placeholder="Например: Как долго проверяют домашку?" /></label>
              <label>Ответ <textarea className="admin__textarea" rows={3} value={qForm.answer} onChange={(e) => setQForm((f) => ({ ...f, answer: e.target.value }))} placeholder="Ответ, на который будет опираться ИИ" /></label>
              {msg && <div className={`admin__msg admin__msg--${msg.type}`}>{msg.text}</div>}
              <button className="btn btn-orange btn-lg" disabled={busy}>{busy ? 'Добавляем…' : 'Добавить вопрос'}</button>
            </form>
            <div className="admin__list">
              {qa.length === 0 && <div className="admin__empty">В этой категории пока нет вопросов</div>}
              {qa.map((q) => (
                <div className="admin__qcard" key={q.id}>
                  <div className="admin__qcard-head">
                    <input className="admin__inp" style={{ flex: 1 }} value={q.question} onChange={(e) => patchQa(q.id, 'question', e.target.value)} />
                    <button className="admin__iconbtn" onClick={() => saveQa(q)}><Save size={15} /></button>
                    <button className="admin__iconbtn admin__iconbtn--danger" onClick={() => delQa(q)}><Trash2 size={15} /></button>
                  </div>
                  <textarea className="admin__textarea" rows={3} value={q.answer || ''} onChange={(e) => patchQa(q.id, 'answer', e.target.value)} />
                </div>
              ))}
            </div>
          </>
        )}
      </section>
    </div>
  )
}
