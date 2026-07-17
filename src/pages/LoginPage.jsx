import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { brand } from '../data/content.js'
import { setAuthed, isLkHost } from '../auth.js'
import { login as apiLogin, me } from '../api.js'
import PhoneInput from '../components/PhoneInput.jsx'

export default function LoginPage() {
  const navigate = useNavigate()
  const [checkingSession, setCheckingSession] = useState(true)
  const [step, setStep] = useState('login') // 'login' | 'password'
  const [method, setMethod] = useState('email') // 'email' | 'phone'
  const [login, setLogin] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  // Если серверная сессия ещё жива, форму входа не показываем и сразу открываем кабинет.
  // Проверяем именно API, а не только localStorage: его значение могло устареть.
  useEffect(() => {
    let alive = true
    me()
      .then(() => {
        if (!alive) return
        setAuthed(true)
        navigate(isLkHost() ? '/' : '/cabinet', { replace: true })
      })
      .catch(() => {
        if (!alive) return
        setAuthed(false)
        setCheckingSession(false)
      })
    return () => { alive = false }
  }, [navigate])

  const toPassword = (e) => {
    e.preventDefault()
    if (login.trim()) { setError(''); setStep('password') }
  }

  const submitPassword = async (e) => {
    e.preventDefault()
    setBusy(true); setError('')
    try {
      await apiLogin(login.trim(), password)
      setAuthed(true)
      navigate(isLkHost() ? '/' : '/cabinet')
    } catch (err) {
      setError(err.message || 'Не удалось войти')
    } finally {
      setBusy(false)
    }
  }

  if (checkingSession) return null

  return (
    <div className="login">
      <div className="login__bg" />
      <div className="login__card">
        <a className="login__logo" href="/"><img src="/img/logo.svg" alt={brand.name} /></a>

        {step === 'login' ? (
          <form onSubmit={toPassword}>
            <h1 className="login__title">Вход в личный кабинет</h1>
            <p className="login__sub">Войди по почте или номеру телефона</p>

            <div className="login__switch">
              <button type="button" className={method === 'email' ? 'active' : ''}
                onClick={() => { setMethod('email'); setLogin('') }}>Почта</button>
              <button type="button" className={method === 'phone' ? 'active' : ''}
                onClick={() => { setMethod('phone'); setLogin('') }}>Телефон</button>
            </div>

            {method === 'email' ? (
              <input
                className="login__input"
                type="email"
                placeholder="E-mail"
                value={login}
                onChange={(e) => setLogin(e.target.value)}
                required
              />
            ) : (
              <PhoneInput className="login__input" value={login} onChange={setLogin} required />
            )}
            <button className="btn btn-orange btn-lg btn-block" type="submit">Далее</button>
            <p className="login__note">Нажимая кнопку, ты соглашаешься с <a className="login__link" href="/privacy">политикой конфиденциальности</a></p>
          </form>
        ) : (
          <form onSubmit={submitPassword}>
            <h1 className="login__title">Введи пароль</h1>
            <p className="login__sub">
              Вход для <b>{login}</b>{' '}
              <button type="button" className="login__link" onClick={() => setStep('login')}>Изменить</button>
            </p>
            <input className="login__input" type="password" placeholder="Пароль" value={password}
              onChange={(e) => setPassword(e.target.value)} required autoFocus />
            {error && <p className="login__error">{error}</p>}
            <button className="btn btn-orange btn-lg btn-block" type="submit" disabled={busy}>
              {busy ? 'Входим…' : 'Войти'}
            </button>
            <p className="login__note">
              Забыли пароль? Доступ восстановит куратор — напишите в <a className="login__link" href="mailto:irgudemy@gmail.com">поддержку</a>.
            </p>
          </form>
        )}
      </div>
    </div>
  )
}
