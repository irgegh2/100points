import { useEffect, useState } from 'react'
import { AlertCircle, MessageCircle, Send, ShieldCheck } from 'lucide-react'
import { vkContactStatus, vkContactSubmit } from '../api.js'
import { useLiveRefresh } from '../liveUpdates.js'

const EMPTY = { status: 'missing', url: '', rejection_reason: '' }

export default function VkContactGate({ navigationKey, navigationTick, disabled = false }) {
  const [contact, setContact] = useState(EMPTY)
  const [loaded, setLoaded] = useState(false)
  const [url, setUrl] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const load = async () => {
    if (disabled) return
    try {
      const data = await vkContactStatus()
      setContact(data)
      setUrl(data.url || '')
      setLoaded(true)
    } catch { /* следующая проверка живых данных повторит запрос */ }
  }

  useEffect(() => { load() }, [disabled])
  useLiveRefresh(load)

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true); setError('')
    try {
      const data = await vkContactSubmit(url)
      setContact(data)
      setUrl(data.url || url)
    } catch (err) { setError(err.message) }
    finally { setBusy(false) }
  }

  // Ссылка обязательна: закрыть экран или отложить ввод нельзя. После успешной
  // отправки (pending/approved) кабинет сразу разблокируется; отклонённую ссылку
  // запрашиваем повторно при следующем живом обновлении.
  if (disabled || !loaded || !['missing', 'rejected'].includes(contact.status)) return null

  return (
    <div className="vk-gate" role="dialog" aria-modal="true" aria-labelledby="vk-gate-title">
      <div className="vk-gate__backdrop" />
      <section className="vk-gate__card">
        <div className="vk-gate__art" aria-hidden="true">
          <span className="vk-gate__art-main"><MessageCircle size={29} /></span>
          <span className="vk-gate__art-check"><ShieldCheck size={17} /></span>
        </div>
        <span className="vk-gate__eyebrow">Связь на сентябрь</span>

        <h2 id="vk-gate-title">Твой персональный менеджер уже готовится</h2>
        <p className="vk-gate__lead">Оставь ссылку на свой профиль VK, чтобы менеджер мог связаться с тобой по учёбе.</p>
        {contact.status === 'rejected' && (
          <div className="vk-gate__notice vk-gate__notice--danger">
            <AlertCircle size={18} />
            <div><b>Ссылка не прошла проверку</b><span>{contact.rejection_reason || 'Проверь ссылку и отправь её ещё раз.'}</span></div>
          </div>
        )}
        <form onSubmit={submit}>
          <label htmlFor="vk-profile-url">Ссылка на профиль VK</label>
          <div className="vk-gate__inputrow">
            <input id="vk-profile-url" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://vk.com/id123456" autoComplete="url" autoFocus />
            <button disabled={busy}><Send size={17} /> {busy ? 'Отправляем…' : 'Передать'}</button>
          </div>
          {error && <div className="vk-gate__error">{error}</div>}
        </form>
        <div className="vk-gate__privacy"><ShieldCheck size={15} /> Ссылка видна только администрации и нужна для связи с тобой.</div>
      </section>
    </div>
  )
}
