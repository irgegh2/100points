import { useEffect, useState } from 'react'
import { Sun, Moon, LockKeyhole } from 'lucide-react'
import { getTheme, toggleTheme } from '../theme.js'

// Кнопка переключения тёмной/светлой темы (в шапке кабинета и админки).
export default function ThemeToggle({ allowed = true, onLocked }) {
  const [theme, setTheme] = useState(getTheme())
  useEffect(() => {
    const sync = (event) => setTheme(event.detail || getTheme())
    window.addEventListener('themechange', sync)
    return () => window.removeEventListener('themechange', sync)
  }, [])
  const dark = theme === 'dark'
  const label = !allowed ? 'Купить тёмную тему' : dark ? 'Светлая тема' : 'Тёмная тема'
  const click = () => {
    if (!allowed) { onLocked?.(); return }
    setTheme(toggleTheme())
  }
  return (
    <button className={`lk__bell theme-toggle ${allowed ? '' : 'is-locked'}`} aria-label={label} title={label} onClick={click}>
      {dark ? <Sun size={20} /> : <Moon size={20} />}
      {!allowed && <LockKeyhole className="theme-toggle__lock" size={10} />}
    </button>
  )
}
