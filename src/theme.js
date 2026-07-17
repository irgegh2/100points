// Тёмная/светлая тема. Атрибут data-theme на <html>; выбор хранится в localStorage.
const KEY = 'theme'

export function getTheme() {
  try { return localStorage.getItem(KEY) === 'dark' ? 'dark' : 'light' } catch { return 'light' }
}

export function applyTheme(t) {
  if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark')
  else document.documentElement.removeAttribute('data-theme')
}

export function toggleTheme() {
  const next = getTheme() === 'dark' ? 'light' : 'dark'
  try { localStorage.setItem(KEY, next) } catch { /* пусто */ }
  applyTheme(next)
  return next
}

// Применяем сохранённую тему до отрисовки приложения (без мигания).
export function initTheme() { applyTheme(getTheme()) }
