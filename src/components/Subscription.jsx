import { useEffect, useState } from 'react'
import { Check, Crown } from 'lucide-react'
import { subscriptionGet } from '../api.js'
import { useLiveRefresh } from '../liveUpdates.js'

const fmt = (n) => (n ?? 0).toLocaleString('ru-RU')
// YYYY-MM-DD → ДД.ММ.ГГГГ (по-русски). Если формат иной — отдаём как есть.
const fmtDate = (s) => {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '')
  return m ? `${m[3]}.${m[2]}.${m[1]}` : s
}

export default function Subscription() {
  const [plans, setPlans] = useState([])
  const [current, setCurrent] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const request = (p) => {
    setNotice(`Онлайн-оплата тарифа «${p.name}» временно недоступна. Попробуйте позже.`)
  }

  const reload = () => {
    let alive = true
    subscriptionGet()
      .then((d) => { if (alive) { setPlans(d.plans || []); setCurrent(d.current || null); setLoading(false) } })
      .catch((e) => { if (alive) { setError(e.message); setLoading(false) } })
    return () => { alive = false }
  }
  useEffect(() => reload(), [])
  useLiveRefresh(reload)

  if (loading) return <div className="subs"><div className="courses-lk__empty">Загружаем тарифы…</div></div>
  if (error) return <div className="subs"><div className="courses-lk__empty">Не удалось загрузить: {error}</div></div>

  const curPlan = current ? plans.find((p) => p.id === current.plan_id) : null
  const curIndex = curPlan ? plans.findIndex((p) => p.id === curPlan.id) : -1

  return (
    <div className="subs">
      <div className="subs__head">
        <div>
          {curPlan ? (
            <>
              <h2>Твой тариф — «{curPlan.name}»</h2>
              <p>
                Подписка {curIndex + 1} из {plans.length}.{' '}
                {current.ends_at && <>Активна до <b>{fmtDate(current.ends_at)}</b>. </>}
                Открой больше возможностей — переходи на тариф выше.
              </p>
            </>
          ) : (
            <>
              <h2>Подписка не оформлена</h2>
              <p>Сейчас доступ к урокам закрыт. Выбери тариф ниже — доступ откроет администратор.</p>
            </>
          )}
        </div>
        {curPlan && <div className="subs__progress">{curIndex + 1}<small>/{plans.length}</small></div>}
      </div>

      {notice && <div className="subs__notice">{notice}</div>}

      <div className="subs__grid">
        {plans.map((p) => {
          const isCurrent = curPlan && p.id === curPlan.id
          const isTop = p.badge === 'top'
          return (
            <div className={`splan ${isCurrent ? 'current' : ''} ${isTop ? 'top' : ''}`} key={p.id}>
              {isCurrent && <span className="splan__badge">Ваш тариф</span>}
              {isTop && !isCurrent && <span className="splan__badge top"><Crown size={12} /> Топ</span>}
              <div className="splan__name">{p.name}</div>
              <div className="splan__tagline">{p.tagline}</div>
              <div className="splan__price">{fmt(p.price)} <small>₽/мес</small></div>
              <ul className="splan__features">
                {p.features.map((f) => <li key={f}><Check size={15} /> {f}</li>)}
              </ul>
              {isCurrent
                ? <button className="btn btn-light splan__btn" disabled>{current.ends_at ? `Активна до ${fmtDate(current.ends_at)}` : 'Активна'}</button>
                : <button className={`btn ${isTop ? 'btn-orange' : 'btn-blue'} splan__btn`} onClick={() => request(p)}>Оплатить «{p.name}»</button>}
            </div>
          )
        })}
      </div>

      <p className="subs__note">Доступ к тарифу активирует администратор платформы.</p>
    </div>
  )
}
