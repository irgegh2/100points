import { useEffect, useState } from 'react'
import { Plus, Trash2, Save, Check, X, Clock, Phone, Mail } from 'lucide-react'
import { catList, catCreate, catUpdate, catDelete, adminPurchases, adminPurchaseUpdate } from '../../api.js'

const CATS = [['theme', 'Оформление'], ['privilege', 'Привилегия'], ['content', 'Контент'], ['reward', 'Цифровая награда'], ['other', 'Прочее']]
const EMPTY = { name: '', description: '', category: 'privilege', price: 100, once_only: 0, badge: '', active: 1 }
const num = (v) => v === undefined ? 1 : Number(v)

export default function AdminShop() {
  const [tab, setTab] = useState('orders')   // orders | items
  const [items, setItems] = useState([])
  const [form, setForm] = useState(EMPTY)
  const [orders, setOrders] = useState([])
  const [pending, setPending] = useState(0)
  const [filter, setFilter] = useState('pending')
  const [notes, setNotes] = useState({})
  const [busy, setBusy] = useState(false)

  const loadItems = () => catList('shop_items').then((d) => setItems(d.items || []))
  const loadOrders = () => adminPurchases(filter === 'all' ? '' : filter).then((d) => { setOrders(d.purchases || []); setPending(d.pending) })
  useEffect(() => { loadItems() }, [])
  useEffect(() => { loadOrders() }, [filter])

  // товары
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }))
  const create = async (e) => {
    e.preventDefault()
    if (!form.name.trim()) return
    setBusy(true)
    try { await catCreate('shop_items', { ...form, position: items.length }); setForm(EMPTY); loadItems() }
    catch (err) { alert(err.message) } finally { setBusy(false) }
  }
  const patch = (id, k, v) => setItems((a) => a.map((x) => x.id === id ? { ...x, [k]: v } : x))
  const saveItem = (it) => catUpdate('shop_items', { id: it.id, name: it.name, description: it.description, category: it.category, price: it.price, once_only: num(it.once_only) ? 1 : 0, active: num(it.active) ? 1 : 0, badge: it.badge }).then(loadItems).catch((e) => alert(e.message))
  const delItem = (it) => confirm(`Удалить товар «${it.name}»?`) && catDelete('shop_items', it.id).then(loadItems)

  // заявки
  const act = async (o, status) => {
    try { await adminPurchaseUpdate(o.id, status, notes[o.id] || ''); loadOrders() }
    catch (e) { alert(e.message) }
  }

  return (
    <div className="admin-wrap admin__editor">
      <section className="admin__panel">
        <div className="admin__modes">
          <label className={`bank__mode ${tab === 'orders' ? 'active' : ''}`}><input type="radio" checked={tab === 'orders'} onChange={() => setTab('orders')} /> Заявки {pending > 0 && <span className="admin__count">{pending}</span>}</label>
          <label className={`bank__mode ${tab === 'items' ? 'active' : ''}`}><input type="radio" checked={tab === 'items'} onChange={() => setTab('items')} /> Товары</label>
        </div>
      </section>

      {tab === 'orders' ? (
        <section className="admin__panel">
          <div className="admin__panel-head">
            <h2>Заявки на выдачу</h2>
            <select className="admin__inp" value={filter} onChange={(e) => setFilter(e.target.value)} style={{ minWidth: 160 }}>
              <option value="pending">Ожидают выдачи</option>
              <option value="done">Выдано</option>
              <option value="canceled">Отменено</option>
              <option value="all">Все</option>
            </select>
          </div>
          <p className="admin__hint">После покупки ученик ждёт выдачу. Свяжись с ним (ВК/телефон/почта), выдай приз/доступ — и нажми «Выдано». «Отменить» вернёт кристаллы.</p>
          <div className="admin__list">
            {orders.length === 0 && <div className="admin__empty">Заявок нет</div>}
            {orders.map((o) => (
              <div className="admin__order" key={o.id}>
                <div className="admin__order-top">
                  <div>
                    <b>{o.item_name}</b> <span className="shop__price"><span className="admin__gem">◆</span> {o.price}</span>
                    <div className="admin__user-contacts">{o.user_name || 'ученик'} · {o.user_email || '—'}{o.user_phone ? ' · ' + o.user_phone : ''}</div>
                    <div className="admin__order-date">{(o.created_at || '').slice(0, 16)}</div>
                  </div>
                  <span className={`shop__order-status ${o.status}`}>
                    {o.status === 'pending' && <><Clock size={12} /> ожидает</>}
                    {o.status === 'done' && <><Check size={12} /> выдано</>}
                    {o.status === 'canceled' && <><X size={12} /> отменено</>}
                  </span>
                </div>
                {o.status !== 'done' && (
                  <div className="admin__order-actions">
                    <input className="admin__inp" placeholder="Комментарий (как выдал / код)" value={notes[o.id] || o.note || ''} onChange={(e) => setNotes((n) => ({ ...n, [o.id]: e.target.value }))} />
                    <button className="btn btn-orange" onClick={() => act(o, 'done')}><Check size={15} /> Выдано</button>
                    {o.status !== 'canceled' && <button className="btn btn-light" onClick={() => act(o, 'canceled')}>Отменить</button>}
                  </div>
                )}
                {o.status === 'done' && o.note && <div className="admin__order-note">Комментарий: {o.note}</div>}
              </div>
            ))}
          </div>
        </section>
      ) : (
        <div className="admin__cols">
          <section className="admin__panel">
            <h2><Plus size={18} /> Новый товар</h2>
            <form className="admin__form" onSubmit={create}>
              <label>Название <input value={form.name} onChange={set('name')} placeholder="Например, +1 жизнь" /></label>
              <label>Описание <input value={form.description} onChange={set('description')} /></label>
              <div className="admin__row2">
                <label>Категория <select value={form.category} onChange={set('category')}>{CATS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></label>
                <label>Цена (кристаллы) <input type="number" value={form.price} onChange={set('price')} /></label>
              </div>
              <div className="admin__row2">
                <label className="admin__chk"><input type="checkbox" checked={!!num(form.once_only)} onChange={(e) => setForm((f) => ({ ...f, once_only: e.target.checked ? 1 : 0 }))} /> Покупается 1 раз</label>
                <label>Метка <input value={form.badge} onChange={set('badge')} placeholder="TOP (необяз.)" /></label>
              </div>
              <button className="btn btn-orange btn-lg" disabled={busy}>{busy ? 'Добавляем…' : 'Добавить товар'}</button>
            </form>
          </section>
          <section className="admin__panel">
            <h2>Товары <span className="admin__count">{items.length}</span></h2>
            <div className="admin__list">
              {items.map((it) => (
                <div className="admin__qcard" key={it.id}>
                  <div className="admin__qcard-head">
                    <input className="admin__inp" style={{ flex: 1 }} value={it.name} onChange={(e) => patch(it.id, 'name', e.target.value)} />
                    <input className="admin__inp admin__inp--n" type="number" value={it.price} onChange={(e) => patch(it.id, 'price', e.target.value)} />
                    <button className="admin__iconbtn" onClick={() => saveItem(it)}><Save size={15} /></button>
                    <button className="admin__iconbtn admin__iconbtn--danger" onClick={() => delItem(it)}><Trash2 size={15} /></button>
                  </div>
                  <input className="admin__inp" value={it.description || ''} onChange={(e) => patch(it.id, 'description', e.target.value)} placeholder="описание" />
                  <div className="admin__qcard-head">
                    <select className="admin__inp" value={it.category} onChange={(e) => patch(it.id, 'category', e.target.value)}>{CATS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
                    <label className="admin__chk"><input type="checkbox" checked={!!num(it.once_only)} onChange={(e) => patch(it.id, 'once_only', e.target.checked ? 1 : 0)} /> 1 раз</label>
                    <label className="admin__chk"><input type="checkbox" checked={!!num(it.active)} onChange={(e) => patch(it.id, 'active', e.target.checked ? 1 : 0)} /> в продаже</label>
                  </div>
                </div>
              ))}
            </div>
          </section>
        </div>
      )}
    </div>
  )
}
