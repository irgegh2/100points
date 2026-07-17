import { useEffect, useState } from 'react'
import { Plus, Trash2, Save } from 'lucide-react'
import { catList, catCreate, catUpdate, catDelete } from '../../api.js'
import { useDangerConfirm } from './DangerConfirm.jsx'

// features в БД хранятся JSON-строкой массива; в форме — по строке на фичу.
const parseFeatures = (s) => { try { const a = JSON.parse(s || '[]'); return Array.isArray(a) ? a : [] } catch { return [] } }
const EMPTY = { name: '', tagline: '', price: '', featuresText: '', badge: '' }

export default function AdminPlans() {
  const confirmDelete = useDangerConfirm()
  const [items, setItems] = useState([])
  const [form, setForm] = useState(EMPTY)
  const [msg, setMsg] = useState(null)
  const [busy, setBusy] = useState(false)

  const load = () => catList('plans').then((d) => setItems((d.items || []).map((p) => ({ ...p, featuresText: parseFeatures(p.features).join('\n') }))))
  useEffect(() => { load() }, [])

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }))
  const toFeaturesJSON = (text) => JSON.stringify((text || '').split('\n').map((s) => s.trim()).filter(Boolean))

  const create = async (e) => {
    e.preventDefault()
    if (!form.name.trim()) return setMsg({ type: 'err', text: 'Введите название' })
    setBusy(true); setMsg(null)
    try {
      const d = await catCreate('plans', { name: form.name, tagline: form.tagline, price: form.price, features: toFeaturesJSON(form.featuresText), badge: form.badge, position: items.length })
      setItems((list) => [...list, { ...d.item, featuresText: parseFeatures(d.item.features).join('\n') }]); setForm(EMPTY); setMsg({ type: 'ok', text: 'Тариф добавлен' })
    } catch (err) { setMsg({ type: 'err', text: err.message }) }
    finally { setBusy(false) }
  }

  const saveRow = async (p) => {
    try { await catUpdate('plans', { id: p.id, name: p.name, tagline: p.tagline, price: p.price, features: toFeaturesJSON(p.featuresText), badge: p.badge }) }
    catch (err) { alert(err.message) }
  }
  const removeRow = async (p) => {
    if (!await confirmDelete({ title: 'Удалить тариф?', target: `Тариф «${p.name}»`, consequence: 'Тариф исчезнет из раздела подписок. Связанные подписки учеников также могут быть удалены.' })) return
    try { await catDelete('plans', p.id); setItems((list) => list.filter((x) => x.id !== p.id)) } catch (err) { alert(err.message) }
  }
  const patch = (id, k, v) => setItems((list) => list.map((x) => x.id === id ? { ...x, [k]: v } : x))

  return (
    <div className="admin__cols">
      <section className="admin__panel">
        <h2><Plus size={18} /> Новый тариф</h2>
        <form className="admin__form" onSubmit={create}>
          <label>Название <input value={form.name} onChange={set('name')} placeholder="Базовый" /></label>
          <label>Слоган <input value={form.tagline} onChange={set('tagline')} placeholder="Старт подготовки" /></label>
          <div className="admin__row2">
            <label>Цена ₽/мес <input type="number" value={form.price} onChange={set('price')} placeholder="9999" /></label>
            <label>Бейдж <select value={form.badge} onChange={set('badge')}><option value="">нет</option><option value="top">Топ</option></select></label>
          </div>
          <label>Фичи <span>(по одной в строке)</span>
            <textarea className="admin__textarea" rows={5} value={form.featuresText} onChange={set('featuresText')} placeholder={'Доступ ко всем урокам\nТесты с автопроверкой'} />
          </label>
          {msg && <div className={`admin__msg admin__msg--${msg.type}`}>{msg.text}</div>}
          <button className="btn btn-orange btn-lg" disabled={busy}>{busy ? 'Добавляем…' : 'Добавить тариф'}</button>
        </form>
      </section>

      <section className="admin__panel">
        <h2>Тарифы <span className="admin__count">{items.length}</span></h2>
        <div className="admin__list">
          {items.length === 0 && <div className="admin__empty">Пока нет тарифов</div>}
          {items.map((p) => (
            <div className="admin__plancard" key={p.id}>
              <div className="admin__row2">
                <label>Название <input className="admin__inp" value={p.name} onChange={(e) => patch(p.id, 'name', e.target.value)} /></label>
                <label>Цена ₽/мес <input className="admin__inp" type="number" value={p.price ?? ''} onChange={(e) => patch(p.id, 'price', e.target.value)} /></label>
              </div>
              <div className="admin__row2">
                <label>Слоган <input className="admin__inp" value={p.tagline || ''} onChange={(e) => patch(p.id, 'tagline', e.target.value)} /></label>
                <label>Бейдж <select className="admin__inp" value={p.badge || ''} onChange={(e) => patch(p.id, 'badge', e.target.value)}><option value="">нет</option><option value="top">Топ</option></select></label>
              </div>
              <label>Фичи <textarea className="admin__textarea" rows={4} value={p.featuresText} onChange={(e) => patch(p.id, 'featuresText', e.target.value)} /></label>
              <div className="admin__edit-btns">
                <button className="btn btn-orange btn-sm" onClick={() => saveRow(p)}><Save size={14} /> Сохранить</button>
                <button className="btn admin__delbtn btn-sm" onClick={() => removeRow(p)}><Trash2 size={14} /> Удалить</button>
              </div>
            </div>
          ))}
        </div>
      </section>
    </div>
  )
}
