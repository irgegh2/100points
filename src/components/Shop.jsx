import { useEffect, useState } from 'react'
import { ArrowLeft, Gem, ArrowRight, Check, Clock, Package, X } from 'lucide-react'
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
  { label: 'Зайти на платформу', pts: 5 },
  { label: 'Сдать домашку на 50%+', pts: 10 },
  { label: 'Пройти пробник на 50%+', pts: 20 },
  { label: 'Тест на 100%', pts: 25 },
  { label: 'Стрик 7 дней', pts: 100 },
  { label: 'Сохранённая жизнь (в конце недели)', pts: 25 },
]

export default function Shop({ balance = 0, onBack, onBalance, onThemeUnlocked, onCosmeticUnlocked }) {
  const [coins, setCoins] = useState(balance)
  const [items, setItems] = useState([])
  const [purchases, setPurchases] = useState([])
  const [busy, setBusy] = useState(0)
  const [toast, setToast] = useState(null)
  const [loading, setLoading] = useState(true)

  const reload = () => Promise.all([shopList(), purchasesList()]).then(([s, p]) => {
    setItems(s.items || []); setCoins(s.balance); setPurchases(p.purchases || [])
    onBalance?.(s.balance)
  }).catch(() => {}).finally(() => setLoading(false))
  useEffect(() => { reload() }, [])

  const buy = async (it) => {
    if (it.owned || it.price > coins || busy) return
    setBusy(it.id)
    try {
      const d = await shopBuy(it.id)
      setCoins(d.balance); onBalance?.(d.balance)
      if (d.theme_unlocked) onThemeUnlocked?.(d.theme)
      if (d.cosmetic_unlocked) onCosmeticUnlocked?.(d.cosmetic)
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
                  return (
                    <div className={`shop__row ${it.badge ? 'is-gold' : ''}`} key={it.id}>
                      <div className="shop__row-main">
                        <div className="shop__row-name">{it.name}{it.badge && <span className="shop__tag">{it.badge}</span>}{it.instant && <span className="shop__instant">Доступ сразу</span>}{it.once_only && <span className="shop__once">1 раз</span>}</div>
                        <div className="shop__row-desc">{it.description}</div>
                      </div>
                      <div className="shop__row-side">
                        <span className="shop__price"><Gem size={15} /> {it.price}</span>
                        <button className={`shop__act ${it.owned ? 'is-owned' : ''} ${poor && !it.owned ? 'is-poor' : ''}`}
                          onClick={() => buy(it)} disabled={it.owned || poor || busy === it.id}>
                          {it.owned ? <><Check size={15} /> {it.instant ? 'Активно' : 'Есть'}</> : busy === it.id ? '…' : poor ? 'Не хватает' : <>Купить <ArrowRight size={15} /></>}
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
              <div className="shop__earn-row" key={e.label}><span>{e.label}</span><b><Gem size={12} /> +{e.pts}</b></div>
            ))}
          </div>
        </aside>
      </div>
    </div>
  )
}
