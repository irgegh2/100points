import { useEffect, useState } from 'react'
import { Routes, Route, Navigate } from 'react-router-dom'
import HomePage from './pages/HomePage.jsx'
import LoginPage from './pages/LoginPage.jsx'
import CabinetPage from './pages/CabinetPage.jsx'
import AdminLoginPage from './pages/AdminLoginPage.jsx'
import AdminPage from './pages/AdminPage.jsx'
import LegalPage from './pages/LegalPage.jsx'
import { isLkHost, isAdminHost, isLocalHost } from './auth.js'
import { maintenanceGet } from './api.js'
import { brand } from './data/content.js'

// Экран «технические работы» (Этап 16). Показывается всем, кроме админ-поддомена.
function Maintenance({ text }) {
  return (
    <div className="maint">
      <div className="maint__card">
        <img className="maint__logo" src="/img/logo.svg" alt={brand?.name || ''} />
        <div className="maint__emoji">🛠️</div>
        <h1>Технические работы</h1>
        <p>{text || 'Скоро вернёмся!'}</p>
      </div>
    </div>
  )
}

// Основной домен намеренно не содержит лендинга и не показывает ссылки на
// личный кабинет или админку. Поддомены продолжают работать по прямым адресам.
function PublicClosedPage() {
  useEffect(() => {
    const previous = document.title
    document.title = 'Идут технические работы'
    return () => { document.title = previous }
  }, [])

  return (
    <main className="public-closed">
      <h1>Идут технические работы</h1>
    </main>
  )
}

export default function App() {
  const admin = isAdminHost()
  const lk = isLkHost()
  const local = isLocalHost()
  const publicSite = !admin && !lk && !local
  const [maint, setMaint] = useState(null)
  useEffect(() => {
    if (admin || publicSite) return
    maintenanceGet().then(setMaint).catch(() => setMaint({ on: false }))
  }, [admin, publicSite])

  // admin.100-points.ru: «/» — админка, «/login» — вход админа. (Тех.работы админку НЕ закрывают.)
  if (admin) {
    return (
      <Routes>
        <Route path="/" element={<AdminPage />} />
        <Route path="/login" element={<AdminLoginPage />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    )
  }

  // 100-points.ru: на любом пути показываем только нейтральную заглушку.
  // Никаких ссылок или автоматических редиректов на закрытые поддомены.
  if (publicSite) return <PublicClosedPage />

  // Управляемые из админки техработы остаются отдельным режимом для ЛК и локальной разработки.
  if (maint === null) return null
  if (maint.on) return <Maintenance text={maint.text} />

  // lk.100-points.ru: «/» — личный кабинет, «/login» — вход ученика.
  if (lk) {
    return (
      <Routes>
        <Route path="/" element={<CabinetPage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/privacy" element={<LegalPage kind="privacy" />} />
        <Route path="/offer" element={<LegalPage kind="offer" />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    )
  }

  // Локальная разработка (один origin) — все страницы доступны по путям.
  if (local) {
    return (
      <Routes>
        <Route path="/" element={<HomePage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/cabinet" element={<CabinetPage />} />
        <Route path="/admin" element={<AdminPage />} />
        <Route path="/admin/login" element={<AdminLoginPage />} />
        <Route path="/privacy" element={<LegalPage kind="privacy" />} />
        <Route path="/offer" element={<LegalPage kind="offer" />} />
      </Routes>
    )
  }

  return <PublicClosedPage />
}
