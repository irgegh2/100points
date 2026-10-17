import { useEffect, useRef, useState } from 'react'
import { ArrowLeft, Gem, ArrowRight, Check, Clock, Package, X, Timer, Heart, CalendarClock, Snowflake, HardDrive } from 'lucide-react'
import { shopList, shopBuy, purchasesList, deadlineExtensionOptions } from '../api.js'
import { pluralRu } from '../ruPlural.js'
import { useLiveRefresh } from '../liveUpdates.js'

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
const formatDeadline = (value) => {
  if (!value) return '—'
  const [date, time = ''] = String(value).split(/[ T]/)
  const [y, m, d] = date.split('-')
  return `${d}.${m}.${y}${time ? ` · ${time.slice(0, 5)}` : ''}`
}
const formatBytes = (bytes) => {
  const value = Number(bytes || 0)
  if (value >= 1073741824) return `${(value / 1073741824).toLocaleString('ru-RU', { maximumFractionDigits: 1 })} ГБ`
  return `${Math.round(value / 1048576)} МБ`
}

export default function Shop({ balance = 0, onBack, onBalance, onWalletUpdate, onThemeUnlocked, onCosmeticUnlocked, onEffectUnlocked }) {
  const [coins, setCoins] = useState(balance)
  const [lives, setLives] = useState(0)
  const [maxLives, setMaxLives] = useState(5)
  const [boosterUntil, setBoosterUntil] = useState('')
  const [vip, setVip] = useState(false)
  const [streakFreezes, setStreakFreezes] = useState(0)
  const [driveBonusBytes, setDriveBonusBytes] = useState(0)
  const [now, setNow] = useState(Date.now())
  const [items, setItems] = useState([])
  const [purchases, setPurchases] = useState([])
  const [busy, setBusy] = useState(0)
  const [toast, setToast] = useState(null)
  const [highlightedPurchase, setHighlightedPurchase] = useState(0)
  const [loading, setLoading] = useState(true)
  const [deadlinePicker, setDeadlinePicker] = useState(null)
  // Поиск и сортировка в выборе ДЗ для продления.
  const [pickerCtl, setPickerCtl] = useState({ q: '', sort: 'deadline' })
  const ordersRef = useRef(null)

  const reload = () => Promise.all([shopList(), purchasesList()]).then(([s, p]) => {
    setItems(s.items || []); setCoins(s.balance); setPurchases(p.purchases || [])
    setLives(s.lives ?? 0); setMaxLives(s.max_lives ?? 5); setBoosterUntil(s.booster_until || ''); setVip(s.vip === true); setStreakFreezes(s.streak_freezes ?? 0)
    setDriveBonusBytes(s.drive_bonus_bytes ?? 0)
    onBalance?.(s.balance)
    onWalletUpdate?.(s)
  }).catch(() => {}).finally(() => setLoading(false))
  useEffect(() => { reload() }, [])
  useLiveRefresh(() => { if (!busy) reload() })
  useEffect(() => {
    if (timeLeft(boosterUntil, Date.now()) <= 0) return undefined
    const timer = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(timer)
  }, [boosterUntil])

  const openDeadlinePicker = async (it) => {
    if (busy || it.price > coins || Number(it.eligible_count || 0) < 1) return
    setPickerCtl({ q: '', sort: 'deadline' })
    setDeadlinePicker({ item: it, loading: true, homeworks: [], error: '' })
    try {
      const d = await deadlineExtensionOptions()
      setDeadlinePicker({ item: it, loading: false, homeworks: d.homeworks || [], error: '' })
    } catch (e) {
      setDeadlinePicker({ item: it, loading: false, homeworks: [], error: e.message })
    }
  }

  const buy = async (it, homework = null) => {
    if (it.name === 'Продление дедлайна +24ч' && !homework) { await openDeadlinePicker(it); return }
    if (it.owned || it.price > coins || busy) return
    setBusy(it.id)
    try {
      const d = await shopBuy(it.id, homework?.id)
      setCoins(d.balance); onBalance?.(d.balance)
      setLives(d.lives ?? lives); setMaxLives(d.max_lives ?? maxLives); setBoosterUntil(d.booster_until || boosterUntil); setVip(d.vip === true)
      setStreakFreezes(d.streak_freezes ?? streakFreezes)
      if (d.drive_bonus_bytes !== undefined) setDriveBonusBytes(d.drive_bonus_bytes)
      onWalletUpdate?.(d)
      if (d.theme_unlocked) onThemeUnlocked?.(d.theme)
      if (d.cosmetic_unlocked) onCosmeticUnlocked?.(d.cosmetic)
      if (d.effect) onEffectUnlocked?.(d.effect, d)
      setToast(d.extended_homework
        ? `Дедлайн «${d.extended_homework.title}» продлён до ${formatDeadline(d.extended_homework.deadline)}.`
        : d.effect === 'streak_freeze'
          ? 'Заморозка добавлена. Она автоматически спасёт серию при одном пропущенном дне.'
        : d.instant
        ? `«${it.name}» куплено и включено!`
        : `«${it.name}» куплено. Заявка появилась в «Моих покупках» — ожидай выдачу.`)
      if (d.extended_homework) setDeadlinePicker(null)
      if (!d.instant) setHighlightedPurchase(d.purchase_id)
      await reload()
      if (!d.instant) {
        setTimeout(() => ordersRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 80)
        setTimeout(() => setHighlightedPurchase(0), 5000)
      }
    } catch (e) { setToast(e.message) }
    finally { setBusy(0); setTimeout(() => setToast(null), 4000) }
  }

  const byCat = CAT_ORDER.map((c) => ({ cat: c, list: items.filter((i) => i.category === c) })).filter((g) => g.list.length)
  const boosterLeft = timeLeft(boosterUntil, now)
  const rewardMultiplier = boosterLeft > 0 ? 2 : 1

  return (
    <div className="shop">
      <button className="shop__back" onClick={onBack}><ArrowLeft size={17} /> На главную</button>
      {toast && <div className="shop__toast" role="status" aria-live="polite"><Check size={17} />{toast}</div>}

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
                  const isFreeze = it.name === 'Заморозка стрика'
                  const isDeadline = it.name === 'Продление дедлайна +24ч'
                  const isDrive = it.name === 'Стобалльный диск +1 ГБ'
                  const fullLives = isLife && lives >= maxLives
                  const comingSoon = it.coming_soon === true
                  const noEligibleHomework = isDeadline && Number(it.eligible_count || 0) < 1
                  return (
                    <div className={`shop__row ${it.badge ? 'is-gold' : ''}`} key={it.id}>
                      <div className="shop__row-main">
                        <div className="shop__row-name">{it.name}{it.badge && <span className="shop__tag">{it.badge}</span>}{it.instant && <span className="shop__instant">Доступ сразу</span>}{it.once_only && <span className="shop__once">1 раз</span>}</div>
                        <div className="shop__row-desc">{it.description}</div>
                        {isLife && <div className="shop__life-state"><Heart size={13} fill="currentColor" /> Сейчас {lives}/{maxLives}</div>}
                        {isBooster && boosterLeft > 0 && <div className="shop__boost-timer"><Timer size={14} /> ×2 активно · {formatTimer(boosterLeft)}</div>}
                        {isFreeze && <div className="shop__freeze-state"><Snowflake size={14} /> В запасе: {streakFreezes}</div>}
                        {isDeadline && <div className="shop__deadline-state"><CalendarClock size={14} /> {noEligibleHomework ? 'Нет активных ДЗ для продления' : `Можно продлить: ${it.eligible_count}`}</div>}
                        {isDrive && <div className="shop__drive-state"><HardDrive size={14} /> Куплено дополнительно: {formatBytes(driveBonusBytes)} · можно повторять</div>}
                      </div>
                      <div className="shop__row-side">
                        <span className="shop__price"><Gem size={15} /> {it.price}</span>
                        <button className={`shop__act ${it.owned ? 'is-owned' : ''} ${(poor && !it.owned) || comingSoon || noEligibleHomework ? 'is-poor' : ''}`}
                          onClick={() => buy(it)} disabled={it.owned || poor || fullLives || comingSoon || noEligibleHomework || busy === it.id}>
                          {it.owned ? <><Check size={15} /> {it.instant ? 'Активно' : 'Есть'}</> : comingSoon ? 'Скоро' : busy === it.id ? '…' : fullLives ? 'Жизни полны' : noEligibleHomework ? 'Нет активных ДЗ' : poor ? 'Не хватает' : isDeadline ? <>Выбрать ДЗ <ArrowRight size={15} /></> : <>Купить <ArrowRight size={15} /></>}
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
              <div className="shop__bal-lbl">{pluralRu(coins, 'кристалл', 'кристалла', 'кристаллов')} на балансе</div>
            </div>
          </div>

          {/* Мои заявки */}
          {purchases.length > 0 && (
            <div className="shop__orders" ref={ordersRef}>
              <div className="shop__earn-head"><Package size={15} /> Мои покупки <span className="shop__orders-cnt">{purchases.length}</span></div>
              <div className="shop__orders-scroll">
                {purchases.map((p) => {
                  const st = STATUS[p.status] || STATUS.pending
                  return (
                    <div className={`shop__order ${Number(p.id) === Number(highlightedPurchase) ? 'is-new' : ''}`} key={p.id}>
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

      {deadlinePicker && (
        <div className="shop-picker" role="presentation" onMouseDown={(e) => { if (e.target === e.currentTarget && !busy) setDeadlinePicker(null) }}>
          <section className="shop-picker__box" role="dialog" aria-modal="true" aria-labelledby="deadline-picker-title">
            <button className="shop-picker__close" aria-label="Закрыть" onClick={() => setDeadlinePicker(null)} disabled={!!busy}><X size={20} /></button>
            <div className="shop-picker__icon"><CalendarClock size={24} /></div>
            <h2 id="deadline-picker-title">Какое ДЗ продлить?</h2>
            <p className="shop-picker__lead">Показываем только твои активные, ещё не сданные задания. После покупки выбранный дедлайн сразу сдвинется на 24 часа.</p>
            {deadlinePicker.loading && <div className="shop-picker__empty">Загружаем твои задания…</div>}
            {deadlinePicker.error && <div className="shop-picker__empty is-error">{deadlinePicker.error}</div>}
            {!deadlinePicker.loading && !deadlinePicker.error && deadlinePicker.homeworks.length === 0 && <div className="shop-picker__empty">Подходящих домашних заданий больше нет.</div>}
            {deadlinePicker.homeworks.length > 1 && (
              <div className="shop-picker__ctl">
                <input className="shop-picker__search" type="search" placeholder="Поиск: название, предмет, курс"
                       value={pickerCtl.q} onChange={(e) => setPickerCtl((c) => ({ ...c, q: e.target.value }))} />
                <select className="shop-picker__sort" value={pickerCtl.sort}
                        onChange={(e) => setPickerCtl((c) => ({ ...c, sort: e.target.value }))}>
                  <option value="deadline">По дедлайну</option>
                  <option value="added">По дате добавления</option>
                  <option value="subject">По предмету</option>
                  <option value="title">По названию</option>
                </select>
              </div>
            )}
            {(() => {
              const q = pickerCtl.q.trim().toLowerCase()
              const filtered = deadlinePicker.homeworks.filter((hw) =>
                !q || `${hw.title} ${hw.subject} ${hw.course || ''}`.toLowerCase().includes(q))
              const by = {
                deadline: (a, b) => String(a.deadline).localeCompare(String(b.deadline)),
                added: (a, b) => b.id - a.id, // новые ДЗ сверху
                subject: (a, b) => String(a.subject).localeCompare(String(b.subject), 'ru') || String(a.deadline).localeCompare(String(b.deadline)),
                title: (a, b) => String(a.title).localeCompare(String(b.title), 'ru'),
              }
              const list = [...filtered].sort(by[pickerCtl.sort] || by.deadline)
              if (!deadlinePicker.loading && deadlinePicker.homeworks.length > 0 && list.length === 0)
                return <div className="shop-picker__empty">Ничего не нашлось по запросу.</div>
              return (
            <div className="shop-picker__list">
              {list.map((hw) => (
                <article className="shop-picker__hw" key={hw.id}>
                  <div className="shop-picker__subject">{hw.subject}</div>
                  <h3>{hw.title}</h3>
                  <div className="shop-picker__dates">
                    <span><small>Сейчас</small>{formatDeadline(hw.deadline)}</span>
                    <ArrowRight size={16} />
                    <span className="is-new"><small>После покупки</small>{formatDeadline(hw.new_deadline)}</span>
                  </div>
                  <button className="shop-picker__buy" onClick={() => buy(deadlinePicker.item, hw)} disabled={!!busy || deadlinePicker.item.price > coins}>
                    {busy ? 'Продлеваем…' : <><Gem size={15} /> Продлить за {deadlinePicker.item.price}</>}
                  </button>
                </article>
              ))}
            </div>
              )
            })()}
          </section>
        </div>
      )}
    </div>
  )
}
