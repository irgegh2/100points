import { useState } from 'react'
import { Sun, Moon } from 'lucide-react'
import { getTheme, toggleTheme } from '../theme.js'

// Кнопка переключения тёмной/светлой темы (в шапке кабинета и админки).
export default function ThemeToggle() {
  const [theme, setTheme] = useState(getTheme())
  const dark = theme === 'dark'
  const label = dark ? 'Светлая тема' : 'Тёмная тема'
  return (
    <button className="lk__bell theme-toggle" aria-label={label} title={label} onClick={() => setTheme(toggleTheme())}>
      {dark ? <Sun size={20} /> : <Moon size={20} />}
    </button>
  )
}
