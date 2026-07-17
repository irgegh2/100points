import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Home, BookOpen, CalendarDays, Trophy, Headset, Sparkles, Dumbbell, Library,
  ClipboardCheck, PencilLine, LogOut,
  Bell, Gem, ChevronRight, Check, Plus, Video,
  Flag, Flame, Sunrise, FolderOpen, Heart, CreditCard, Menu, X, Crown, Snowflake,
} from 'lucide-react'
import { brand } from '../data/content.js'
import { setAuthed } from '../auth.js'
import { me, logout as apiLogout, profileGet, walletGet, themeAccess, achievementsList, dailyGet, todayGet, notifList, notifRead } from '../api.js'
import { getTheme, setTheme } from '../theme.js'
import Shop from '../components/Shop.jsx'
import Achievements from '../components/Achievements.jsx'
import Courses from '../components/Courses.jsx'
import Homework from '../components/Homework.jsx'
import Mock from '../components/Mock.jsx'
import LibraryView from '../components/Library.jsx'
import BankView from '../components/Bank.jsx'
import Mentors from '../components/Mentors.jsx'
import AiChat from '../components/AiChat.jsx'
import Trainer from '../components/Trainer.jsx'
import Profile from '../components/Profile.jsx'
import Schedule from '../components/Schedule.jsx'
import Subscription from '../components/Subscription.jsx'
import Tour from '../components/Tour.jsx'
import ThemeToggle from '../components/ThemeToggle.jsx'

// Меню кабинета. key — какой раздел открыть справа.
const NAV = [
  { key: 'home', icon: Home, label: 'Главная' },
  { key: 'courses', icon: BookOpen, label: 'Курсы' },
  { key: 'schedule', icon: CalendarDays, label: 'Расписание' },
  { key: 'achievements', icon: Trophy, label: 'Достижения' },
  { key: 'mentor', icon: Headset, label: 'Онлайн-наставник' },
  { key: 'aichat', icon: Sparkles, label: 'ИИ-чат' },
  { key: 'trainer', icon: Dumbbell, label: 'ИИ-тренажёр' },
  { key: 'library', icon: Library, label: 'Библиотека' },
  { key: 'bank', icon: FolderOpen, label: 'Банк заданий' },
  { key: 'mock', icon: ClipboardCheck, label: 'Пробники' },
  { key: 'homework', icon: PencilLine, label: 'Домашние задания' },
  { key: 'sub', icon: CreditCard, label: 'Подписка' },
]

// Иконка/стиль для пункта «Обучение сегодня» по типу события.
const TODAY_KIND = {
  webinar: { type: 'Вебинар', icon: Video, cls: 'webinar' },
  practice: { type: 'Практика', icon: BookOpen, cls: 'theory' },
  theory: { type: 'Теория', icon: BookOpen, cls: 'theory' },
  homework: { type: 'Домашка', icon: PencilLine, cls: 'homework' },
  proctored: { type: 'Пробник с наставником', icon: ClipboardCheck, cls: 'webinar' },
}
const DAYS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс']
// Иконки достижений (строка из БД → компонент).
const ACH_ICONS = { flag: Flag, pencil: PencilLine, target: Trophy, clipboard: ClipboardCheck, flame: Flame, sunrise: Sunrise, gem: Gem }

// Иконка/цвет уведомления по типу.
const NOTIF_ICON = {
  review: { icon: Check, color: '#34c759' },
  achievement: { icon: Trophy, color: '#ff7a00' },
  shop: { icon: Gem, color: '#0ea5a5' },
  mentor: { icon: Headset, color: '#2f6bff' },
}
const notifIcon = (type) => NOTIF_ICON[type] || ((type || '').startsWith('event') ? { icon: Video, color: '#7c4dff' } : { icon: Bell, color: '#8a93a3' })
const notifAgo = (ts) => {
  if (!ts) return ''
  const diff = (Date.now() - new Date(ts.replace(' ', 'T') + 'Z').getTime()) / 1000
  if (diff < 90) return 'только что'
  if (diff < 3600) return Math.floor(diff / 60) + ' мин назад'
  if (diff < 86400) return Math.floor(diff / 3600) + ' ч назад'
  return Math.floor(diff / 86400) + ' дн назад'
}

function HomeDash({ onNavigate, crystals }) {
  const [ach, setAch] = useState([])
  // Пусто до загрузки — чтобы не мигало старыми цифрами «2/5», пока БД отвечает.
  const [daily, setDaily] = useState({ tasks: [], days: null, week_done: 0 })
  const [today, setToday] = useState([])

  useEffect(() => {
    achievementsList().then((d) => setAch(d.achievements || [])).catch(() => {})
    dailyGet().then((d) => setDaily(d)).catch(() => {})
    todayGet().then((d) => setToday(d.items || [])).catch(() => {})
  }, [])

  const doneTasks = daily.tasks.filter((t) => t.done).length
  const preview = ach.slice(0, 5)

  return (
    <div className="dash">
      <div className="dash__main">
        {/* Баннер */}
        <button className="dash__banner" onClick={() => onNavigate('aichat')}>
          <span className="dash__banner-ico"><MessageCircleIcon /></span>
          <b>Поговорить с ИИ-помощником</b>
          <span className="dash__banner-go">Открыть <ChevronRight size={16} /></span>
        </button>

        {/* Достижения */}
        <section className="dcard">
          <div className="dcard__head"><h3><Trophy size={18} /> Достижения</h3><button className="dash__link" onClick={() => onNavigate('achievements')}>Смотреть все <ChevronRight size={14} /></button></div>
          <div className="dash__badges">
            {(preview.length ? preview : [{}, {}, {}, {}, {}]).map((a, i) => {
              const Ico = ACH_ICONS[a.icon] || Trophy
              return <div className={`dash__badge ${a.unlocked ? '' : 'locked'}`} key={i} title={a.title || ''}><Ico size={24} /></div>
            })}
          </div>
        </section>

        {/* Обучение сегодня */}
        <section className="dcard">
          <div className="dcard__head"><h3><CalendarDays size={18} /> Обучение сегодня</h3>{today.length > 0 && <span className="dash__counter">{today.length}</span>}</div>
          {today.length === 0 ? (
            <div className="dtoday__empty">На сегодня эфиров и дедлайнов нет — спокойно занимайся по программе 👌</div>
          ) : (
            <div className="dtoday">
              {today.map((t, i) => {
                const k = TODAY_KIND[t.kind] || TODAY_KIND.webinar
                return (
                  <div className="dtoday__item" key={i}>
                    <span className={`dtoday__ico ${k.cls}`}><k.icon size={18} /></span>
                    <div className="dtoday__info">
                      <div className={`dtoday__type ${k.cls}`}>{k.type} · {t.subject}</div>
                      <div className="dtoday__name">{t.title}</div>
                    </div>
                    <div className="dtoday__right">
                      <span className={`dtoday__meta ${t.urgent ? 'urgent' : ''}`}>{t.meta}</span>
                      {t.kind === 'homework' || t.kind === 'proctored'
                        ? <button className="btn btn-orange" onClick={() => onNavigate(t.kind === 'proctored' ? 'mock' : 'homework')}>{t.action}</button>
                        : <a className="btn btn-orange" href={t.link || '#'} target="_blank" rel="noreferrer">{t.action}</a>}
                    </div>
                  </div>
                )
              })}
            </div>
          )}
        </section>
      </div>

      <div className="dash__side">
        {/* Монеты */}
        <section className="dcoins">
          <div className="dcoins__top"><Gem size={22} /><b>{crystals}</b><span>кристаллов</span></div>
          <p>Получай их за выполнение заданий и трать в магазине</p>
          <button className="btn btn-light" onClick={() => onNavigate('shop')}>В магазин</button>
        </section>

        {/* Задания дня */}
        <section className="dcard">
          <div className="dcard__head"><h3>Задания дня</h3><span className="dash__counter">{doneTasks}/{daily.tasks.length}</span></div>
          <div className="dtasks">
            {daily.tasks.map((t, i) => (
              <div className={`dtask ${t.done ? 'done' : ''}`} key={i}>
                <span className="dtask__check">{t.done ? <Check size={14} /> : null}</span>
                <span className="dtask__label">{t.label}</span>
                <span className="dtask__pts"><Plus size={11} />{t.pts}</span>
              </div>
            ))}
          </div>
        </section>

        {/* Недельный бонус */}
        <section className="dcard">
          <div className="dcard__head"><h3>Недельный бонус</h3><span className="dash__counter">{daily.week_done}/5</span></div>
          <div className="dweek">
            {DAYS.map((d, i) => {
              const done = daily.days ? daily.days[i]?.done : false
              const frozen = daily.days ? daily.days[i]?.frozen : false
              return <div className={`dweek__day ${done ? 'done' : ''} ${frozen ? 'frozen' : ''}`} title={frozen ? 'Серия сохранена заморозкой' : undefined} key={d}>{done ? <Check size={14} /> : frozen ? <Snowflake size={14} /> : d}</div>
            })}
          </div>
          <p className="dweek__hint">Занимайся 5 дней в неделю, чтобы открыть бонус</p>
        </section>
      </div>
    </div>
  )
}

// маленькая иконка для баннера (чат)
function MessageCircleIcon() {
  return <Sparkles size={18} />
}

export default function CabinetPage() {
  const navigate = useNavigate()
  const [user, setUser] = useState(null)
  const [avatar, setAvatar] = useState('')
  const [wallet, setWallet] = useState({ crystals: 0, lives: 5, max_lives: 5, vip: false })
  const [themeAccessList, setThemeAccessList] = useState(['light'])
  const [hasAvatarFrame, setHasAvatarFrame] = useState(false)
  const [nicknameColorAccess, setNicknameColorAccess] = useState(false)
  const [nicknameColor, setNicknameColor] = useState('')
  const [checking, setChecking] = useState(true)
  const [active, setActive] = useState('home')
  const [notifOpen, setNotifOpen] = useState(false)
  const [notifs, setNotifs] = useState([])
  const [notifUnread, setNotifUnread] = useState(0)
  const [info, setInfo] = useState(null)   // 'lives' | 'coins' | null — поповер-объяснение
  const [menuOpen, setMenuOpen] = useState(false)

  const loadNotifs = () => notifList().then((d) => { setNotifs(d.items || []); setNotifUnread(d.unread || 0) }).catch(() => {})
  useEffect(() => {
    loadNotifs()
    const t = setInterval(loadNotifs, 25000)
    return () => clearInterval(t)
  }, [])
  const openNotif = async (n) => {
    setNotifOpen(false)
    if (!n.read) { try { await notifRead(n.id) } catch { /* пусто */ } }
    if (n.link) setActive(n.link)
    loadNotifs()
  }
  const readAll = async () => { try { await notifRead('all') } catch { /* пусто */ } loadNotifs() }

  const go = useCallback((key) => { setActive(key); setMenuOpen(false) }, [])

  // Проверяем серверную сессию: кто вошёл.
  useEffect(() => {
    let alive = true
    me()
      .then(async (d) => {
        if (!alive) return
        setUser(d.user)
        const access = await themeAccess().catch(() => ({ themes: ['light'] }))
        if (!alive) return
        const themes = Array.isArray(access.themes) ? access.themes : ['light']
        setThemeAccessList(themes)
        setHasAvatarFrame(access.avatar_frame === true)
        setNicknameColorAccess(access.nickname_color_access === true)
        setNicknameColor(access.nickname_color || '')
        if (!themes.includes(getTheme())) setTheme('light')
        setChecking(false)
        // аватар грузим отдельно (в /me его нет) — не блокируя кабинет
        profileGet().then((p) => { if (alive) setAvatar(p.profile?.avatar || '') }).catch(() => {})
        // баланс (кристаллы/жизни) — синхронизирует недельный сброс и серию
        walletGet().then((wl) => { if (alive) setWallet(wl) }).catch(() => {})
      })
      .catch(() => { setAuthed(false); navigate('/login', { replace: true }) })
    return () => { alive = false }
  }, [navigate])

  const logout = async () => {
    try { await apiLogout() } catch { /* ignore */ }
    setAuthed(false)
    navigate('/login')
  }

  if (checking) return null

  const current = active === 'shop'
    ? { label: 'Магазин' }
    : active === 'profile'
      ? { label: 'Профиль' }
      : (NAV.find((n) => n.key === active) || NAV[0])

  return (
    <div className="lk">
      {menuOpen && <div className="lk__overlay" onClick={() => setMenuOpen(false)} />}
      <aside className={`lk__side ${menuOpen ? 'open' : ''}`}>
        <div className="lk__side-top">
          <a className="lk__logo" href="/"><img src="/img/logo.svg" alt={brand.name} /></a>
          <button className="lk__side-close" onClick={() => setMenuOpen(false)}><X size={20} /></button>
        </div>
        <nav className="lk__nav">
          {NAV.map((n) => (
            <button
              key={n.key}
              data-tour={n.key}
              className={`lk__navitem ${n.key === active ? 'active' : ''}`}
              onClick={() => go(n.key)}
            >
              <n.icon size={19} /> <span>{n.label}</span>
            </button>
          ))}
        </nav>
        <button className="lk__logout" onClick={logout}><LogOut size={18} /> <span>Выйти</span></button>
      </aside>

      <main className="lk__main">
        <header className="lk__top">
          <button className="lk__burger" onClick={() => setMenuOpen(true)} aria-label="Меню"><Menu size={22} /></button>
          <h1>{current.label}</h1>
          <div className="lk__top-right">
            <div className="lk__chipwrap">
              <span className="lk__hp" role="button" tabIndex={0} onClick={() => setInfo(info === 'lives' ? null : 'lives')}><Heart size={17} fill="#e0518a" stroke="#e0518a" /> {wallet.lives}</span>
              {info === 'lives' && (<>
                <div className="lk__notif-backdrop" onClick={() => setInfo(null)} />
                <div className="lk__info">
                  <div className="lk__info-head"><Heart size={18} fill="#e0518a" stroke="#e0518a" /> Жизни <b>{wallet.lives} / {wallet.max_lives}</b></div>
                  <ul className="lk__info-list">
                    <li><b>Что это.</b> Запас на неделю — {wallet.max_lives} жизней. Каждую неделю снова {wallet.max_lives}.</li>
                    <li><b>Как теряются.</b> −1 жизнь за каждую домашку, не сданную до дедлайна. Пробники жизни не отнимают.</li>
                    <li><b>Зачем беречь.</b> В конце недели каждая сохранённая жизнь превращается в <b>25 кристаллов</b> 💎. Чем больше сберёг — тем больше награда.</li>
                    <li><b>Серия.</b> Заходи каждый день — сейчас серия {wallet.streak ?? 0} дн.</li>
                  </ul>
                </div>
              </>)}
            </div>
            <div className="lk__chipwrap">
              <span className="lk__coins" data-tour="wallet" role="button" tabIndex={0} onClick={() => setInfo(info === 'coins' ? null : 'coins')}><Gem size={17} /> {wallet.crystals}</span>
              {info === 'coins' && (<>
                <div className="lk__notif-backdrop" onClick={() => setInfo(null)} />
                <div className="lk__info">
                  <div className="lk__info-head"><Gem size={18} /> Кристаллы <b>{wallet.crystals}</b></div>
                  <ul className="lk__info-list">
                    <li><b>Как получить.</b> Вход каждый день +5, сдача ДЗ +10 и пробник +20 (если набрал ≥50%), тест на 100% +15, достижения и сохранённые жизни.</li>
                    <li><b>Куда тратить.</b> В магазине — на товары и награды.</li>
                  </ul>
                  <button className="btn btn-orange" style={{ width: '100%' }} onClick={() => { setInfo(null); setActive('shop') }}>В магазин</button>
                </div>
              </>)}
            </div>
            <ThemeToggle access={themeAccessList} onLocked={() => go('shop')} />
            <div className="lk__bellwrap">
              <button className="lk__bell" aria-label="Уведомления" onClick={() => setNotifOpen((o) => !o)}>
                <Bell size={20} />{notifUnread > 0 && <span className="lk__bell-dot" />}
              </button>
              {notifOpen && (
                <>
                  <div className="lk__notif-backdrop" onClick={() => setNotifOpen(false)} />
                  <div className="lk__notif">
                    <div className="lk__notif-head">Уведомления{notifUnread > 0 && <button className="lk__notif-readall" onClick={readAll}>Прочитать все</button>}</div>
                    <div className="lk__notif-list">
                      {notifs.length === 0 && <div className="lk__notif-empty">Пока нет уведомлений</div>}
                      {notifs.map((n) => {
                        const ic = notifIcon(n.type)
                        return (
                          <button className={`lk__notif-row ${n.read ? '' : 'unread'}`} key={n.id} onClick={() => openNotif(n)}>
                            <span className="lk__notif-ico" style={{ background: `${ic.color}1a`, color: ic.color }}><ic.icon size={16} /></span>
                            <div><div className="lk__notif-text">{n.text}</div><div className="lk__notif-time">{notifAgo(n.created_at)}</div></div>
                          </button>
                        )
                      })}
                    </div>
                  </div>
                </>
              )}
            </div>
            <button className={`lk__user ${active === 'profile' ? 'active' : ''}`} onClick={() => setActive('profile')}>
              <span className={`lk__ava ${hasAvatarFrame ? 'avatar--framed' : ''}`} style={avatar ? { backgroundImage: `url(${avatar})`, backgroundSize: 'cover', backgroundPosition: 'center' } : undefined} />
              <span className="lk__username" style={nicknameColorAccess ? { color: nicknameColor || '#7b5cff' } : undefined}>{user?.name || 'Ученик'}</span>
              {wallet.vip && <span className="lk__vip-badge"><Crown size={11} /> VIP</span>}
            </button>
          </div>
        </header>

        {active === 'profile' ? (
          <Profile user={user} avatarFrame={hasAvatarFrame} nicknameColorAccess={nicknameColorAccess} initialNicknameColor={nicknameColor}
            onBack={() => setActive('home')} onSaved={(u) => { setUser(u); if (u.avatar !== undefined) setAvatar(u.avatar); if (u.nickname_color !== undefined) setNicknameColor(u.nickname_color) }} />
        ) : active === 'home' ? (
          <HomeDash onNavigate={setActive} crystals={wallet.crystals} />
        ) : active === 'shop' ? (
          <Shop balance={wallet.crystals} onBack={() => setActive('home')} onBalance={(c) => setWallet((w) => ({ ...w, crystals: c }))}
            onWalletUpdate={(data) => setWallet((w) => ({ ...w, crystals: data.balance ?? w.crystals, lives: data.lives ?? w.lives, max_lives: data.max_lives ?? w.max_lives, vip: data.vip ?? w.vip, streak_freezes: data.streak_freezes ?? w.streak_freezes }))}
            onThemeUnlocked={(theme) => {
              setThemeAccessList((list) => list.includes(theme) ? list : [...list, theme])
              setTheme(theme)
            }}
            onCosmeticUnlocked={(cosmetic) => {
              if (cosmetic === 'avatar_frame') setHasAvatarFrame(true)
              if (cosmetic === 'nickname_color') {
                setNicknameColorAccess(true)
                setNicknameColor('#7b5cff')
              }
            }}
            onEffectUnlocked={(effect) => {
              if (effect === 'vip') {
                setThemeAccessList((list) => list.includes('vip') ? list : [...list, 'vip'])
                setTheme('vip')
              }
            }} />
        ) : active === 'achievements' ? (
          <Achievements />
        ) : active === 'courses' ? (
          <Courses />
        ) : active === 'homework' ? (
          <Homework />
        ) : active === 'mock' ? (
          <Mock />
        ) : active === 'library' ? (
          <LibraryView />
        ) : active === 'bank' ? (
          <BankView />
        ) : active === 'schedule' ? (
          <Schedule />
        ) : active === 'sub' ? (
          <Subscription />
        ) : active === 'mentor' ? (
          <Mentors />
        ) : active === 'aichat' ? (
          <AiChat />
        ) : active === 'trainer' ? (
          <Trainer />
        ) : (
          <section className="lk__placeholder">
            <current.icon size={40} strokeWidth={1.6} />
            <h2>{current.label}</h2>
            <p>Раздел в разработке — скоро здесь появится контент.</p>
          </section>
        )}
      </main>

      <Tour onNavigate={go} onReward={(balance) => setWallet((w) => ({ ...w, crystals: balance }))} />
    </div>
  )
}
