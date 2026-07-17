import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { brand } from '../data/content.js'
import { setAdminAuthed, isAdminHost } from '../auth.js'
import { adminLogin } from '../api.js'

export default function AdminLoginPage() {
  const navigate = useNavigate()
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true); setError('')
    try {
      await adminLogin(username.trim(), password)
      setAdminAuthed(true)
      navigate(isAdminHost() ? '/' : '/admin')
    } catch (err) {
      setError(err.message || 'Не удалось войти')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="login login--admin">
      <div className="login__card">
        <a className="login__logo" href="/"><img src="/img/logo.svg" alt={brand.name} /></a>
        <form onSubmit={submit}>
          <h1 className="login__title">Админ-панель</h1>
          <p className="login__sub">Вход для администратора</p>
          <input className="login__input" type="text" placeholder="Логин" value={username}
            onChange={(e) => setUsername(e.target.value)} required autoFocus />
          <input className="login__input" type="password" placeholder="Пароль" value={password}
            onChange={(e) => setPassword(e.target.value)} required />
          {error && <p className="login__error">{error}</p>}
          <button className="btn btn-orange btn-lg btn-block" type="submit" disabled={busy}>
            {busy ? 'Входим…' : 'Войти'}
          </button>
        </form>
      </div>
    </div>
  )
}
