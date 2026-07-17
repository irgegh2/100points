import { useEffect, useState } from 'react'
import { Routes, Route, Navigate } from 'react-router-dom'
import HomePage from './pages/HomePage.jsx'
import LoginPage from './pages/LoginPage.jsx'
import CabinetPage from './pages/CabinetPage.jsx'
import AdminLoginPage from './pages/AdminLoginPage.jsx'
import AdminPage from './pages/AdminPage.jsx'
import LegalPage from './pages/LegalPage.jsx'
import { isLkHost, isAdminHost, isLocalHost, lkUrl, adminUrl } from './auth.js'
import { maintenanceGet } from './api.js'
import { brand } from './data/content.js'

// Полноценный (между поддоменами) редирект на другой хост.
function ExternalRedirect({ to }) {
  useEffect(() => { window.location.replace(to) }, [to])
  return null
}

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

export default function App() {
  const admin = isAdminHost()
  const [maint, setMaint] = useState(null)
  useEffect(() => { if (!admin) maintenanceGet().then(setMaint).catch(() => setMaint({ on: false })) }, [admin])

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

  // Тех.работы: для всех, кроме админ-поддомена. Ждём короткую проверку, потом — экран или сайт.
  if (maint === null) return null
  if (maint.on) return <Maintenance text={maint.text} />

  // lk.100-points.ru: «/» — личный кабинет, «/login» — вход ученика.
  if (isLkHost()) {
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
  if (isLocalHost()) {
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

  // Прод, основной домен 100-points.ru: «/» — главная.
  // Кабинет и вход доступны ТОЛЬКО на поддоменах lk./admin. — на основном домене
  // эти пути уводят на нужный поддомен (попасть на логин/кабинет без префикса нельзя).
  return (
    <Routes>
      <Route path="/" element={<HomePage />} />
      <Route path="/privacy" element={<LegalPage kind="privacy" />} />
      <Route path="/offer" element={<LegalPage kind="offer" />} />
      <Route path="/login" element={<ExternalRedirect to={lkUrl('/login')} />} />
      <Route path="/cabinet" element={<ExternalRedirect to={lkUrl('/')} />} />
      <Route path="/admin" element={<ExternalRedirect to={adminUrl('/')} />} />
      <Route path="/admin/login" element={<ExternalRedirect to={adminUrl('/login')} />} />
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
