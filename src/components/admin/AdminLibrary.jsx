import { useEffect, useRef, useState } from 'react'
import { Plus, Trash2, Save, Upload, Link2 } from 'lucide-react'
import { catList, catCreate, catUpdate, catDelete, libraryUpload } from '../../api.js'

const CATS = ['Конспекты', 'Шпаргалки', 'Кодификаторы', 'Демоверсии', 'Пробники прошлых лет', 'Карточки', 'Материалы']

export default function AdminLibrary() {
  const [subjects, setSubjects] = useState([])
  const [items, setItems] = useState([])
  const [form, setForm] = useState({ subject_id: '', category: 'Конспекты', title: '', url: '' })
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState(null)
  const fileRef = useRef(null)

  const loadSubjects = () => catList('subjects').then((d) => setSubjects(d.items || []))
  const load = () => catList('library_items').then((d) => setItems(d.items || []))
  useEffect(() => { loadSubjects(); load() }, [])

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }))
  const subjName = (sid) => sid ? (subjects.find((s) => String(s.id) === String(sid))?.name || '') : 'Общий (всем)'

  // добавить ссылкой
  const addLink = async (e) => {
    e.preventDefault()
    if (!form.title.trim() || !form.url.trim()) return setMsg({ type: 'err', text: 'Нужны название и ссылка' })
    setBusy(true); setMsg(null)
    try { await catCreate('library_items', { subject_id: form.subject_id || '', category: form.category, title: form.title, url: form.url, size: 'Ссылка', kind: 'link', position: items.length }); setForm((f) => ({ ...f, title: '', url: '' })); load(); setMsg({ type: 'ok', text: 'Материал добавлен' }) }
    catch (err) { setMsg({ type: 'err', text: err.message }) } finally { setBusy(false) }
  }
  // добавить файлом
  const onFile = async (e) => {
    const file = e.target.files?.[0]; if (!file) return
    setBusy(true); setMsg(null)
    try { await libraryUpload(file, { subject_id: form.subject_id || undefined, category: form.category, title: form.title || undefined }); setForm((f) => ({ ...f, title: '' })); load(); setMsg({ type: 'ok', text: 'Файл загружен' }) }
    catch (err) { setMsg({ type: 'err', text: err.message }) } finally { setBusy(false); if (fileRef.current) fileRef.current.value = '' }
  }

  const patch = (id, k, v) => setItems((a) => a.map((x) => x.id === id ? { ...x, [k]: v } : x))
  const save = (it) => catUpdate('library_items', { id: it.id, subject_id: it.subject_id || '', category: it.category, title: it.title, url: it.url }).then(load).catch((e) => alert(e.message))
  const del = (it) => confirm(`Удалить «${it.title}»?`) && catDelete('library_items', it.id).then(load)

  return (
    <div className="admin__cols">
      <section className="admin__panel">
        <h2><Plus size={18} /> Новый материал</h2>
        <p className="admin__hint">Загрузи файл (PDF/Word/…) или добавь ссылку. «Общий» материал виден всем, по предмету — только тем, у кого есть доступ к нему.</p>
        <form className="admin__form" onSubmit={addLink}>
          <div className="admin__row2">
            <label>Предмет <select value={form.subject_id} onChange={set('subject_id')}><option value="">Общий (всем)</option>{subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></label>
            <label>Категория <select value={form.category} onChange={set('category')}>{CATS.map((c) => <option key={c} value={c}>{c}</option>)}</select></label>
          </div>
          <label>Название <input value={form.title} onChange={set('title')} placeholder="Например: Конспект по производным" /></label>

          <div className="admin__lib-add">
            <button type="button" className="btn btn-light" disabled={busy} onClick={() => fileRef.current?.click()}><Upload size={15} /> Загрузить файл</button>
            <input ref={fileRef} type="file" hidden onChange={onFile} accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.txt,.jpg,.jpeg,.png,.webp" />
            <span className="admin__hint" style={{ margin: 0 }}>или</span>
          </div>
          <div className="admin__row2">
            <label style={{ flex: 1 }}>Ссылка <input value={form.url} onChange={set('url')} placeholder="https://…" /></label>
            <button className="btn btn-orange" style={{ alignSelf: 'end' }} disabled={busy}><Link2 size={15} /> По ссылке</button>
          </div>
          {msg && <div className={`admin__msg admin__msg--${msg.type}`}>{msg.text}</div>}
        </form>
      </section>

      <section className="admin__panel">
        <h2>Материалы <span className="admin__count">{items.length}</span></h2>
        <div className="admin__list">
          {items.length === 0 && <div className="admin__empty">Материалов нет</div>}
          {items.map((it) => (
            <div className="admin__qcard" key={it.id}>
              <div className="admin__qcard-head">
                <input className="admin__inp" style={{ flex: 1 }} value={it.title} onChange={(e) => patch(it.id, 'title', e.target.value)} />
                <button className="admin__iconbtn" onClick={() => save(it)}><Save size={15} /></button>
                <button className="admin__iconbtn admin__iconbtn--danger" onClick={() => del(it)}><Trash2 size={15} /></button>
              </div>
              <div className="admin__qcard-head">
                <select className="admin__inp admin__inp--sm" value={it.subject_id || ''} onChange={(e) => patch(it.id, 'subject_id', e.target.value)}><option value="">Общий</option>{subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
                <select className="admin__inp admin__inp--sm" value={it.category || 'Материалы'} onChange={(e) => patch(it.id, 'category', e.target.value)}>{CATS.map((c) => <option key={c} value={c}>{c}</option>)}</select>
                <span className="admin__hint" style={{ margin: 0, fontSize: 12 }}>{it.size}</span>
              </div>
              <input className="admin__inp" value={it.url || ''} onChange={(e) => patch(it.id, 'url', e.target.value)} placeholder="ссылка / путь к файлу" />
            </div>
          ))}
        </div>
      </section>
    </div>
  )
}
