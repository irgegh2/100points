import { useEffect, useState } from 'react'
import { Wrench, AlertTriangle } from 'lucide-react'
import { adminSettingsGet, adminSettingsSet } from '../../api.js'

export default function AdminSettings() {
  const [maintenance, setMaintenance] = useState(false)
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState(null)

  useEffect(() => { adminSettingsGet().then((d) => { setMaintenance(d.maintenance); setText(d.maintenance_text || '') }).catch(() => {}) }, [])

  const toggle = async (on) => {
    setBusy(true); setMsg(null)
    try { const d = await adminSettingsSet({ maintenance: on }); setMaintenance(d.maintenance); setMsg({ type: 'ok', text: on ? 'Сайт закрыт на тех.работы' : 'Сайт снова открыт' }) }
    catch (e) { setMsg({ type: 'err', text: e.message }) } finally { setBusy(false) }
  }
  const saveText = async () => {
    setBusy(true); setMsg(null)
    try { await adminSettingsSet({ maintenance_text: text }); setMsg({ type: 'ok', text: 'Текст сохранён' }) }
    catch (e) { setMsg({ type: 'err', text: e.message }) } finally { setBusy(false) }
  }

  return (
    <section className="admin__panel admin__panel--wide">
      <h2><Wrench size={18} /> Технические работы</h2>
      <p className="admin__hint">Когда включено — кабинет учеников и главный сайт показывают экран «технические работы». Админка и вход в неё продолжают работать (отсюда же выключишь).</p>

      <div className={`admin__maint ${maintenance ? 'on' : ''}`}>
        <div className="admin__maint-state">
          <div className="admin__maint-dot" />
          <div>
            <b>{maintenance ? 'Сайт закрыт на тех.работы' : 'Сайт работает'}</b>
            <div className="admin__hint" style={{ margin: 0 }}>{maintenance ? 'Ученики видят заглушку' : 'Всё доступно ученикам'}</div>
          </div>
        </div>
        <button className={`btn btn-lg ${maintenance ? 'btn-light' : 'btn-orange'}`} disabled={busy} onClick={() => toggle(!maintenance)}>
          {maintenance ? 'Открыть сайт' : 'Закрыть на тех.работы'}
        </button>
      </div>

      <label className="admin__field" style={{ marginTop: 18 }}>Текст для учеников
        <textarea className="admin__textarea" rows={3} value={text} onChange={(e) => setText(e.target.value)} placeholder="Идут технические работы. Скоро вернёмся!" maxLength={400} />
      </label>
      <button className="btn btn-light" disabled={busy} onClick={saveText}>Сохранить текст</button>

      {maintenance && <div className="admin__msg admin__msg--err" style={{ marginTop: 14 }}><AlertTriangle size={15} /> Сейчас сайт закрыт для учеников. Не забудь открыть обратно.</div>}
      {msg && <div className={`admin__msg admin__msg--${msg.type}`} style={{ marginTop: 10 }}>{msg.text}</div>}
    </section>
  )
}
