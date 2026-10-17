import { useEffect, useState } from 'react'
import { Check, ChevronDown, Crown, Dumbbell, Flower2, LockKeyhole, Moon, Orbit, Sparkles, Sun } from 'lucide-react'
import { getTheme, setTheme } from '../theme.js'

const THEMES = [
  { id: 'light', label: 'Светлая', note: 'Классическая', icon: Sun, swatch: 'theme-swatch--light' },
  { id: 'dark', label: 'Тёмная', note: 'Графитовая', icon: Moon, swatch: 'theme-swatch--dark' },
  { id: 'neon', label: 'Неоновая', note: 'Циан и ультрафиолет', icon: Sparkles, swatch: 'theme-swatch--neon' },
  { id: 'space', label: 'Космос', note: 'Звёздная ночь', icon: Orbit, swatch: 'theme-swatch--space' },
  { id: 'vip', label: 'VIP', note: 'Чёрное золото', icon: Crown, swatch: 'theme-swatch--vip' },
  // Сюрприз из магазина: цвета нарочно перепутаны местами.
  { id: 'boys', label: 'Мужская', note: 'Сила — в нежности', icon: Dumbbell, swatch: 'theme-swatch--boys' },
  { id: 'girls', label: 'Женская', note: 'Стальной характер', icon: Flower2, swatch: 'theme-swatch--girls' },
]

// Темы-сюрпризы: до покупки их вообще не видно в списке — ни цветов, ни подписей.
const SURPRISE = ['boys', 'girls']

// Выпадающий выбор оформления. У ученика доступны только купленные темы;
// в админке access не передаётся, поэтому доступны все — для проверки дизайна.
export default function ThemeToggle({ access, onLocked }) {
  const [theme, setCurrent] = useState(getTheme())
  const [open, setOpen] = useState(false)
  const unrestricted = !Array.isArray(access)
  const allowed = unrestricted ? THEMES.map((item) => item.id) : access
  const current = THEMES.find((item) => item.id === theme) || THEMES[0]
  const CurrentIcon = current.icon

  useEffect(() => {
    const sync = (event) => setCurrent(event.detail || getTheme())
    window.addEventListener('themechange', sync)
    return () => window.removeEventListener('themechange', sync)
  }, [])

  const choose = (item) => {
    if (!allowed.includes(item.id)) {
      setOpen(false)
      onLocked?.(item.id)
      return
    }
    setTheme(item.id)
    setOpen(false)
  }

  return (
    <div className="theme-picker">
      {open && <button className="theme-picker__backdrop" aria-label="Закрыть выбор темы" onClick={() => setOpen(false)} />}
      <button className="lk__bell theme-toggle" aria-label={`Тема: ${current.label}`} title="Выбрать тему"
        aria-haspopup="menu" aria-expanded={open} onClick={() => setOpen((value) => !value)}>
        <CurrentIcon size={19} />
        <ChevronDown className={`theme-toggle__chev ${open ? 'open' : ''}`} size={11} />
      </button>

      {open && (
        <div className="theme-menu" role="menu">
          <div className="theme-menu__head">Оформление</div>
          {THEMES.filter((item) => !SURPRISE.includes(item.id) || allowed.includes(item.id)).map((item) => {
            const Icon = item.icon
            const isAllowed = allowed.includes(item.id)
            const active = item.id === theme
            return (
              <button className={`theme-menu__item ${active ? 'active' : ''} ${isAllowed ? '' : 'locked'}`}
                key={item.id} role="menuitem" onClick={() => choose(item)}>
                <span className={`theme-menu__swatch ${item.swatch}`}><Icon size={15} /></span>
                <span className="theme-menu__copy"><b>{item.label}</b><small>{isAllowed ? item.note : 'Купить в магазине'}</small></span>
                {active ? <Check className="theme-menu__state active" size={17} />
                  : !isAllowed ? <LockKeyhole className="theme-menu__state" size={14} /> : null}
              </button>
            )
          })}
        </div>
      )}
    </div>
  )
}
