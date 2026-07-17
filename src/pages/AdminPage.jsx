import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Users, Shapes, BookOpen, PencilLine, ClipboardCheck, FolderOpen, Dumbbell,
  Trophy, ShoppingCart, CalendarDays, Headset, Sparkles, CreditCard, Library,
  LifeBuoy, Bell, Wrench, LogOut, Menu, X, Inbox,
} from 'lucide-react'
import { brand } from '../data/content.js'
import { isAdminAuthed, isAdminHost, setAdminAuthed } from '../auth.js'
import { adminLogout, adminMe } from '../api.js'
import AdminUsers from '../components/admin/AdminUsers.jsx'
import AdminSubjects from '../components/admin/AdminSubjects.jsx'
import AdminCourses from '../components/admin/AdminCourses.jsx'
import AdminPlans from '../components/admin/AdminPlans.jsx'
import AdminReview from '../components/admin/AdminReview.jsx'
import AdminMocks from '../components/admin/AdminMocks.jsx'
import AdminBank from '../components/admin/AdminBank.jsx'
import AdminTrainer from '../components/admin/AdminTrainer.jsx'
import AdminSupport from '../components/admin/AdminSupport.jsx'
import AdminShop from '../components/admin/AdminShop.jsx'
import AdminSchedule from '../components/admin/AdminSchedule.jsx'
import AdminMentor from '../components/admin/AdminMentor.jsx'
import AdminNotify from '../components/admin/AdminNotify.jsx'
import AdminAchievements from '../components/admin/AdminAchievements.jsx'
import AdminAiChats from '../components/admin/AdminAiChats.jsx'
import AdminSettings from '../components/admin/AdminSettings.jsx'
import AdminLibrary from '../components/admin/AdminLibrary.jsx'
import AdminLeads from '../components/admin/AdminLeads.jsx'
import AdminStub from '../components/admin/AdminStub.jsx'
import ThemeToggle from '../components/ThemeToggle.jsx'

// Рабочие разделы админки (сверху) — то, что реально управляется сейчас.
const REAL = [
  { key: 'users', icon: Users, label: 'Ученики' },
  { key: 'leads', icon: Inbox, label: 'Заявки' },
  { key: 'subjects', icon: Shapes, label: 'Предметы' },
  { key: 'courses', icon: BookOpen, label: 'Курсы' },
  { key: 'schedule', icon: CalendarDays, label: 'Расписание' },
  { key: 'mock', icon: ClipboardCheck, label: 'Пробники' },
  { key: 'bank', icon: FolderOpen, label: 'Банк заданий' },
  { key: 'library', icon: Library, label: 'Библиотека' },
  { key: 'trainer', icon: Dumbbell, label: 'ИИ-тренажёр' },
  { key: 'achievements', icon: Trophy, label: 'Достижения' },
  { key: 'support', icon: LifeBuoy, label: 'Техподдержка' },
  { key: 'mentor', icon: Headset, label: 'Онлайн-наставник' },
  { key: 'aichat', icon: Sparkles, label: 'ИИ-чат' },
  { key: 'shop', icon: ShoppingCart, label: 'Магазин' },
  { key: 'review', icon: PencilLine, label: 'Проверка работ' },
  { key: 'notify', icon: Bell, label: 'Рассылка' },
  { key: 'plans', icon: CreditCard, label: 'Подписки' },
  { key: 'settings', icon: Wrench, label: 'Настройки' },
]
// Заглушки до соответствующего этапа роадмапа (сейчас все разделы готовы).
const STUBS = []
const ALL = [...REAL, ...STUBS]

export default function AdminPage() {
  const navigate = useNavigate()
  const loginPath = isAdminHost() ? '/login' : '/admin/login'
  const [checking, setChecking] = useState(true)
  const [active, setActive] = useState('users')
  const [menuOpen, setMenuOpen] = useState(false)

  // Проверяем серверную сессию админа.
  useEffect(() => {
    let alive = true
    adminMe()
      .then(() => { if (alive) setChecking(false) })
      .catch(() => { setAdminAuthed(false); navigate(loginPath, { replace: true }) })
    return () => { alive = false }
  }, [navigate])

  const logout = async () => {
    try { await adminLogout() } catch { /* ignore */ }
    setAdminAuthed(false)
    navigate(loginPath)
  }
  const go = (key) => { setActive(key); setMenuOpen(false) }

  if (checking && !isAdminAuthed()) return null

  const current = ALL.find((n) => n.key === active) || REAL[0]
  const stub = STUBS.find((n) => n.key === active)

  return (
    <div className="lk">
      {menuOpen && <div className="lk__overlay" onClick={() => setMenuOpen(false)} />}
      <aside className={`lk__side ${menuOpen ? 'open' : ''}`}>
        <div className="lk__side-top">
          <a className="lk__logo" href="/"><img src="/img/logo.svg" alt={brand.name} /></a>
          <button className="lk__side-close" onClick={() => setMenuOpen(false)}><X size={20} /></button>
        </div>
        <nav className="lk__nav">
          {REAL.map((n) => (
            <button key={n.key} className={`lk__navitem ${n.key === active ? 'active' : ''}`} onClick={() => go(n.key)}>
              <n.icon size={19} /> <span>{n.label}</span>
            </button>
          ))}
          {STUBS.length > 0 && <div className="admin__navsep">Появится позже</div>}
          {STUBS.map((n) => (
            <button key={n.key} className={`lk__navitem lk__navitem--stub ${n.key === active ? 'active' : ''}`} onClick={() => go(n.key)}>
              <n.icon size={19} /> <span>{n.label}</span>
              <span className="admin__stage">этап {n.stage}</span>
            </button>
          ))}
        </nav>
        <button className="lk__logout" onClick={logout}><LogOut size={18} /> <span>Выйти</span></button>
      </aside>

      <main className="lk__main">
        <header className="lk__top">
          <button className="lk__burger" onClick={() => setMenuOpen(true)} aria-label="Меню"><Menu size={22} /></button>
          <h1>{current.label}</h1>
          <div className="lk__top-right"><ThemeToggle /><span className="admin__badge">Админ-панель</span></div>
        </header>

        <div className="admin-wrap">
          {active === 'users' && <AdminUsers />}
          {active === 'leads' && <AdminLeads />}
          {active === 'subjects' && <AdminSubjects />}
          {active === 'courses' && <AdminCourses />}
          {active === 'mock' && <AdminMocks />}
          {active === 'bank' && <AdminBank />}
          {active === 'trainer' && <AdminTrainer />}
          {active === 'achievements' && <AdminAchievements />}
          {active === 'aichat' && <AdminAiChats />}
          {active === 'settings' && <AdminSettings />}
          {active === 'library' && <AdminLibrary />}
          {active === 'support' && <AdminSupport />}
          {active === 'shop' && <AdminShop />}
          {active === 'schedule' && <AdminSchedule />}
          {active === 'mentor' && <AdminMentor />}
          {active === 'notify' && <AdminNotify />}
          {active === 'review' && <AdminReview />}
          {active === 'plans' && <AdminPlans />}
          {stub && <AdminStub label={stub.label} stage={stub.stage} />}
        </div>
      </main>
    </div>
  )
}
