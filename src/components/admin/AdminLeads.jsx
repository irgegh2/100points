import { useEffect, useState } from 'react'
import { Phone, Check, X, Trash2, CreditCard, LifeBuoy, UserPlus, Download } from 'lucide-react'
import { adminLeads, adminLeadUpdate, adminExportUrl } from '../../api.js'

const KINDS = {
  lead:         { label: 'Заявка с сайта',      icon: UserPlus },
  subscription: { label: 'Запрос тарифа',       icon: CreditCard },
  support:      { label: 'Вопрос (ИИ-помощь)',  icon: LifeBuoy },
}
const STATUSES = [['', 'Все'], ['new', 'Новые'], ['done', 'Обработанные'], ['rejected', 'Отклонённые']]
const SOURCES = { hero: 'Главный экран', consult: 'Консультация', finalcta: 'Вводный урок' }
const statusLabel = (s) => (s === 'new' ? 'Новая' : s === 'done' ? 'Обработана' : 'Отклонена')

export default function AdminLeads() {
  const [items, setItems] = useState([])
  const [status, setStatus] = useState('new')
  const [kind, setKind] = useState('')
  const [loading, setLoading] = useState(true)
  const [notes, setNotes] = useState({})

  const load = () => {
    setLoading(true)
    adminLeads({ status, kind }).then((d) => setItems(d.items || [])).catch(() => {}).finally(() => setLoading(false))
  }
  useEffect(() => { load() }, [status, kind])

  const setStatusFor = async (id, st) => { try { await adminLeadUpdate({ id, status: st }); load() } catch (e) { alert(e.message) } }
  const saveNote = async (id) => { try { await adminLeadUpdate({ id, note: notes[id] ?? '' }) } catch (e) { alert(e.message) } }
  const del = async (id) => { if (confirm('Удалить заявку?')) { try { await adminLeadUpdate({ id, delete: true }); load() } catch (e) { alert(e.message) } } }

  return (
    <div className="admin-wrap">
      <section className="admin__panel">
        <div className="admin__panel-head">
          <h2>Заявки</h2>
          <a className="btn btn-light btn-sm" href={adminExportUrl('leads', { status, kind })}><Download size={15} /> Excel (CSV)</a>
        </div>
        <p className="admin__hint">Заявки с лендинга (имя / класс / телефон), запросы тарифа из кабинета и вопросы из «Помощи по платформе», на которые ИИ не нашёл ответ.</p>
        <div className="admin__filters">
          <label className="admin__field">Статус
            <select className="admin__inp" value={status} onChange={(e) => setStatus(e.target.value)}>{STATUSES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
          </label>
          <label className="admin__field">Тип
            <select className="admin__inp" value={kind} onChange={(e) => setKind(e.target.value)}>
              <option value="">Все</option>
              <option value="lead">Заявки с сайта</option>
              <option value="subscription">Запросы тарифа</option>
              <option value="support">Вопросы (ИИ-помощь)</option>
            </select>
          </label>
        </div>

        {loading && <div className="admin__empty">Загрузка…</div>}
        {!loading && items.length === 0 && <div className="admin__empty">Заявок нет</div>}
        {!loading && items.map((l) => {
          const k = KINDS[l.kind] || KINDS.lead
          return (
            <div className={`admin__leadcard ${l.status}`} key={l.id}>
              <div className="admin__leadcard-head">
                <span className="admin__leadkind"><k.icon size={15} /> {k.label}</span>
                <span className="admin__leadtime">{(l.created_at || '').slice(0, 16).replace('T', ' ')}</span>
                <span className={`admin__leadstatus ${l.status}`}>{statusLabel(l.status)}</span>
              </div>
              <div className="admin__leadbody">
                <b>{l.name || l.user_name || 'Без имени'}</b>
                {l.phone && <a href={`tel:${l.phone}`} className="admin__leadphone"><Phone size={13} /> {l.phone}</a>}
                {l.grade && <span className="admin__pill-muted">{l.grade}</span>}
                {l.plan && <span className="admin__pill-muted">Тариф: {l.plan}</span>}
                {l.source && <span className="admin__pill-muted">{SOURCES[l.source] || l.source}</span>}
                {l.user_email && <span className="admin__pill-muted">{l.user_email}</span>}
              </div>
              {l.message && <div className="admin__leadmsg">«{l.message}»</div>}
              <div className="admin__leadactions">
                <input className="admin__inp" placeholder="Комментарий" value={notes[l.id] ?? l.note ?? ''} onChange={(e) => setNotes((n) => ({ ...n, [l.id]: e.target.value }))} onBlur={() => saveNote(l.id)} />
                {l.status !== 'done' && <button className="btn btn-blue btn-sm" onClick={() => setStatusFor(l.id, 'done')}><Check size={14} /> Обработана</button>}
                {l.status !== 'rejected' && <button className="btn btn-light btn-sm" onClick={() => setStatusFor(l.id, 'rejected')}><X size={14} /> Отклонить</button>}
                {l.status !== 'new' && <button className="btn btn-light btn-sm" onClick={() => setStatusFor(l.id, 'new')}>В новые</button>}
                <button className="admin__iconbtn admin__iconbtn--danger" title="Удалить" onClick={() => del(l.id)}><Trash2 size={15} /></button>
              </div>
            </div>
          )
        })}
      </section>
    </div>
  )
}
