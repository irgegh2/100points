import { useEffect, useState } from 'react'
import { Plus, Trash2, Save } from 'lucide-react'
import { catList, catCreate, catUpdate, catDelete } from '../../api.js'
import { useDangerConfirm } from './DangerConfirm.jsx'

// Иконки предметов совпадают с фронтом курсов (ICONS в Courses.jsx).
const ICON_OPTS = ['pi', 'feather', 'landmark', 'social', 'english', 'leaf', 'book', 'cap']
const EMPTY = { name: '', slug: '', color: '#3f9bff', icon: 'pi', show_features: 1 }
// показывать ли предмет в разделах-фичах (цели/тренажёр/банк/библиотека)
const isShown = (v) => v === undefined || Number(v) === 1

export default function AdminSubjects() {
  const confirmDelete = useDangerConfirm()
  const [items, setItems] = useState([])
  const [form, setForm] = useState(EMPTY)
  const [msg, setMsg] = useState(null)
  const [busy, setBusy] = useState(false)

  const load = () => catList('subjects').then((d) => setItems(d.items || []))
  useEffect(() => { load() }, [])

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }))

  const create = async (e) => {
    e.preventDefault()
    if (!form.name.trim()) return setMsg({ type: 'err', text: 'Введите название' })
    setBusy(true); setMsg(null)
    try {
      const d = await catCreate('subjects', { ...form, position: items.length })
      setItems((list) => [...list, d.item]); setForm(EMPTY)
      setMsg({ type: 'ok', text: 'Предмет добавлен' })
    } catch (err) { setMsg({ type: 'err', text: err.message }) }
    finally { setBusy(false) }
  }

  const saveRow = async (it) => {
    try { await catUpdate('subjects', it) }
    catch (err) { alert(err.message) }
  }
  const removeRow = async (it) => {
    if (!await confirmDelete({ title: 'Удалить предмет?', target: `Предмет «${it.name}»`, consequence: 'Будут удалены все курсы этого предмета и весь вложенный учебный контент.' })) return
    try { await catDelete('subjects', it.id); setItems((list) => list.filter((x) => x.id !== it.id)) }
    catch (err) { alert(err.message) }
  }
  const patch = (id, k, v) => setItems((list) => list.map((x) => x.id === id ? { ...x, [k]: v } : x))

  return (
    <div className="admin__cols">
      <section className="admin__panel">
        <h2><Plus size={18} /> Новый предмет</h2>
        <form className="admin__form" onSubmit={create}>
          <label>Название <input value={form.name} onChange={set('name')} placeholder="Например, Физика" /></label>
          <label>Slug <span>(латиницей, для ссылок)</span><input value={form.slug} onChange={set('slug')} placeholder="phys" /></label>
          <div className="admin__row2">
            <label>Цвет <input type="color" value={form.color} onChange={set('color')} className="admin__color" /></label>
            <label>Иконка
              <select value={form.icon} onChange={set('icon')}>{ICON_OPTS.map((i) => <option key={i} value={i}>{i}</option>)}</select>
            </label>
          </div>
          <label className="admin__checkrow">
            <input type="checkbox" checked={isShown(form.show_features)} onChange={(e) => setForm((f) => ({ ...f, show_features: e.target.checked ? 1 : 0 }))} />
            <span>Показывать в разделах: цели в профиле, ИИ-тренажёр, банк заданий, библиотека</span>
          </label>
          {msg && <div className={`admin__msg admin__msg--${msg.type}`}>{msg.text}</div>}
          <button className="btn btn-orange btn-lg" disabled={busy}>{busy ? 'Добавляем…' : 'Добавить предмет'}</button>
        </form>
      </section>

      <section className="admin__panel">
        <h2>Предметы <span className="admin__count">{items.length}</span></h2>
        <div className="admin__list">
          {items.length === 0 && <div className="admin__empty">Пока нет предметов</div>}
          {items.map((it) => (
            <div className="admin__rowedit" key={it.id}>
              <span className="admin__swatch" style={{ background: it.color }} />
              <input className="admin__inp" value={it.name} onChange={(e) => patch(it.id, 'name', e.target.value)} onBlur={() => saveRow(it)} />
              <input className="admin__inp admin__inp--sm" value={it.slug || ''} onChange={(e) => patch(it.id, 'slug', e.target.value)} onBlur={() => saveRow(it)} placeholder="slug" />
              <select className="admin__inp admin__inp--sm" value={it.icon || 'pi'} onChange={(e) => { patch(it.id, 'icon', e.target.value); saveRow({ ...it, icon: e.target.value }) }}>
                {ICON_OPTS.map((i) => <option key={i} value={i}>{i}</option>)}
              </select>
              <input type="color" className="admin__color" value={it.color || '#3f9bff'} onChange={(e) => patch(it.id, 'color', e.target.value)} onBlur={() => saveRow(it)} />
              <label className="admin__chk" title="Показывать предмет в разделах: цели в профиле, ИИ-тренажёр, банк заданий, библиотека">
                <input type="checkbox" checked={isShown(it.show_features)} onChange={(e) => { const v = e.target.checked ? 1 : 0; patch(it.id, 'show_features', v); saveRow({ ...it, show_features: v }) }} /> разделы
              </label>
              <button className="admin__iconbtn" title="Сохранить" onClick={() => saveRow(it)}><Save size={16} /></button>
              <button className="admin__iconbtn admin__iconbtn--danger" title="Удалить" onClick={() => removeRow(it)}><Trash2 size={16} /></button>
            </div>
          ))}
        </div>
      </section>
    </div>
  )
}
