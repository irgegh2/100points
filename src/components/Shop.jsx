import { useEffect, useState } from 'react'
import { ArrowLeft, Gem, ArrowRight, Check, Clock, Package, X, Timer, Heart } from 'lucide-react'
import { shopList, shopBuy, purchasesList } from '../api.js'

const CAT_LABELS = {
  theme: 'Оформление',
  privilege: 'Учебные привилегии',
  content: 'Контент и пробники',
  reward: 'Цифровые награды',
  other: 'Прочее',
}
const CAT_ORDER = ['theme', 'privilege', 'content', 'reward', 'other']
const CAT_NOTE = { reward: 'Выдаются вручную — после покупки свяжемся с тобой в ВК' }

const STATUS = {
  pending: { label: 'Принято — скоро выдадим', cls: 'pending', ico: Clock },
  done: { label: 'Выдано', cls: 'done', ico: Check },
  canceled: { label: 'Отменено, кристаллы возвращены', cls: 'canceled', ico: X },
}

// Реальные начисления (см. api/gamify.php). Держать синхронно с бэком.
const EARN = [
  { label: 'Зайти на платформу', pts: 5, vipPts: 10 },
  { label: 'Сдать домашку на 50%+', pts: 10 },
  { label: 'Пройти пробник на 50%+', pts: 20 },
  { label: 'Тест на 100%', pts: 25 },
  { label: 'Стрик 7 дней', pts: 100 },
  { label: 'Сохранённая жизнь (в конце недели)', pts: 25 },
]

const timeLeft = (until, now) => {
  if (!until) return 0
  const ts = new Date(String(until).replace(' ', 'T')).getTime()
  return Number.isFinite(ts) ? Math.max(0, ts - now) : 0
}
const formatTimer = (ms) => {
  const total = Math.ceil(ms / 1000)
  const days = Math.floor(total / 86400)
  const hours = Math.floor((total % 86400) / 3600)
  const mins = Math.floor((total % 3600) / 60)
  const secs = total % 60
  const clock = [hours, mins, secs].map((n) => String(n).padStart(2, '0')).join(':')
  return days > 0 ? `${days}д ${clock}` : clock
}

export default function Shop({ balance = 0, onBack, onBalance, onWalletUpdate, onThemeUnlocked, onCosmeticUnlocked, onEffectUnlocked }) {
  const [coins, setCoins] = useState(balance)
  const [lives, setLives] = useState(0)
  const [maxLives, setMaxLives] = useState(5)
  const [boosterUntil, setBoosterUntil] = useState('')
  const [vip, setVip] = useState(false)
  const [now, setNow] = useState(Date.now())
  const [items, setItems] = useState([])
  const [purchases, setPurchases] = useState([])
  const [busy, setBusy] = useState(0)
  const [toast, setToast] = useState(null)
  const [loading, setLoading] = useState(true)

  const reload = () => Promise.all([shopList(), purchasesList()]).then(([s, p]) => {
    setItems(s.items || []); setCoins(s.balance); setPurchases(p.purchases || [])
    setLives(s.lives ?? 0); setMaxLives(s.max_lives ?? 5); setBoosterUntil(s.booster_until || ''); setVip(s.vip === true)
    onBalance?.(s.balance)
    onWalletUpdate?.(s)
  }).catch(() => {}).finally(() => setLoading(false))
  useEffect(() => { reload() }, [])
  useEffect(() => {
    if (timeLeft(boosterUntil, Date.now()) <= 0) return undefined
    const timer = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(timer)
  }, [boosterUntil])

  const buy = async (it) => {
    if (it.owned || it.price > coins || busy) return
    setBusy(it.id)
    try {
      const d = await shopBuy(it.id)
      setCoins(d.balance); onBalance?.(d.balance)
      setLives(d.lives ?? lives); setMaxLives(d.max_lives ?? maxLives); setBoosterUntil(d.booster_until || boosterUntil); setVip(d.vip === true)
      onWalletUpdate?.(d)
      if (d.theme_unlocked) onThemeUnlocked?.(d.theme)
      if (d.cosmetic_unlocked) onCosmeticUnlocked?.(d.cosmetic)
      if (d.effect) onEffectUnlocked?.(d.effect, d)
      setToast(d.instant
        ? `«${it.name}» куплено и включено!`
        : it.category === 'reward'
        ? `«${it.name}» — заявка принята! Скоро свяжемся в ВК и выдадим.`
        : `«${it.name}» куплено! Заявка отправлена — скоро откроем.`)
      await reload()
    } catch (e) { setToast(e.message) }
    finally { setBusy(0); setTimeout(() => setToast(null), 4000) }
  }

  const byCat = CAT_ORDER.map((c) => ({ cat: c, list: items.filter((i) => i.category === c) })).filter((g) => g.list.length)
  const boosterLeft = timeLeft(boosterUntil, now)
  const rewardMultiplier = boosterLeft > 0 ? 2 : 1

  return (
    <div className="shop">
      <button className="shop__back" onClick={onBack}><ArrowLeft size={17} /> На главную</button>
      {toast && <div className="shop__toast">{toast}</div>}

      <div className="shop__layout">
        <div className="shop__col">
          {loading && <div className="hw__empty">Загрузка…</div>}

          {byCat.map(({ cat, list }) => (
            <section className="shop__sec" key={cat}>
              <div className="shop__sec-head">{CAT_LABELS[cat] || cat}{CAT_NOTE[cat] && <span>{CAT_NOTE[cat]}</span>}</div>
              <div className="shop__list">
                {list.map((it) => {
                  const poor = it.price > coins
                  const isLife = it.name === '+1 жизнь'
                  const isBooster = it.name === 'Бустер ×2 кристалла'
                  const fullLives = isLife && lives >= maxLives
                  const comingSoon = it.coming_soon === true
                  return (
                    <div className={`shop__row ${it.badge ? 'is-gold' : ''}`} key={it.id}>
                      <div className="shop__row-main">
                        <div className="shop__row-name">{it.name}{it.badge && <span className="shop__tag">{it.badge}</span>}{it.instant && <span className="shop__instant">Доступ сразу</span>}{it.once_only && <span className="shop__once">1 раз</span>}</div>
                        <div className="shop__row-desc">{it.description}</div>
                        {isLife && <div className="shop__life-state"><Heart size={13} fill="currentColor" /> Сейчас {lives}/{maxLives}</div>}
                        {isBooster && boosterLeft > 0 && <div className="shop__boost-timer"><Timer size={14} /> ×2 активно · {formatTimer(boosterLeft)}</div>}
                      </div>
                      <div className="shop__row-side">
                        <span className="shop__price"><Gem size={15} /> {it.price}</span>
                        <button className={`shop__act ${it.owned ? 'is-owned' : ''} ${(poor && !it.owned) || comingSoon ? 'is-poor' : ''}`}
                          onClick={() => buy(it)} disabled={it.owned || poor || fullLives || comingSoon || busy === it.id}>
                          {it.owned ? <><Check size={15} /> {it.instant ? 'Активно' : 'Есть'}</> : comingSoon ? 'Скоро' : busy === it.id ? '…' : fullLives ? 'Жизни полны' : poor ? 'Не хватает' : <>Купить <ArrowRight size={15} /></>}
                        </button>
                      </div>
                    </div>
                  )
                })}
              </div>
            </section>
          ))}
        </div>

        <aside className="shop__aside">
          <div className="shop__bal">
            <span className="shop__bal-ico"><Gem size={22} /></span>
            <div>
              <div className="shop__bal-num">{coins}</div>
              <div className="shop__bal-lbl">кристаллов на балансе</div>
            </div>
          </div>

          {/* Мои заявки */}
          {purchases.length > 0 && (
            <div className="shop__orders">
              <div className="shop__earn-head"><Package size={15} /> Мои покупки <span className="shop__orders-cnt">{purchases.length}</span></div>
              <div className="shop__orders-scroll">
                {purchases.map((p) => {
                  const st = STATUS[p.status] || STATUS.pending
                  return (
                    <div className="shop__order" key={p.id}>
                      <div className="shop__order-name">{p.item_name}</div>
                      <div className={`shop__order-status ${st.cls}`}><st.ico size={12} /> {st.label}</div>
                      {p.note && p.status === 'done' && <div className="shop__order-note">{p.note}</div>}
                    </div>
                  )
                })}
              </div>
            </div>
          )}

          <div className="shop__earn">
            <div className="shop__earn-head">Как заработать</div>
            {EARN.map((e) => (
              <div className="shop__earn-row" key={e.label}><span>{e.label}</span><b><Gem size={12} /> +{(vip && e.vipPts ? e.vipPts : e.pts) * rewardMultiplier}</b></div>
            ))}
          </div>
        </aside>
      </div>
    </div>
  )
}
