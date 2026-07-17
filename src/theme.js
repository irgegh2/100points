// Тёмная/светлая тема. Атрибут data-theme на <html>; выбор хранится в localStorage.
const KEY = 'theme'

export function getTheme() {
  try { return localStorage.getItem(KEY) === 'dark' ? 'dark' : 'light' } catch { return 'light' }
}

export function applyTheme(t) {
  if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark')
  else document.documentElement.removeAttribute('data-theme')
}

export function setTheme(t) {
  const next = t === 'dark' ? 'dark' : 'light'
  try { localStorage.setItem(KEY, next) } catch { /* пусто */ }
  applyTheme(next)
  try { window.dispatchEvent(new CustomEvent('themechange', { detail: next })) } catch { /* пусто */ }
  return next
}

export function toggleTheme() {
  const next = getTheme() === 'dark' ? 'light' : 'dark'
  return setTheme(next)
}

// Применяем сохранённую тему до отрисовки приложения (без мигания).
export function initTheme() { applyTheme(getTheme()) }
