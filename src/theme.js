// Оформление кабинета. Атрибут data-theme на <html>; выбор хранится в localStorage.
const KEY = 'theme'
export const THEME_IDS = ['light', 'dark', 'neon', 'space', 'vip', 'boys', 'girls']

export function getTheme() {
  try {
    const saved = localStorage.getItem(KEY)
    return THEME_IDS.includes(saved) ? saved : 'light'
  } catch { return 'light' }
}

export function applyTheme(t) {
  if (t !== 'light' && THEME_IDS.includes(t)) document.documentElement.setAttribute('data-theme', t)
  else document.documentElement.removeAttribute('data-theme')
}

export function setTheme(t) {
  const next = THEME_IDS.includes(t) ? t : 'light'
  try { localStorage.setItem(KEY, next) } catch { /* пусто */ }
  applyTheme(next)
  try { window.dispatchEvent(new CustomEvent('themechange', { detail: next })) } catch { /* пусто */ }
  return next
}

// Применяем сохранённую тему до отрисовки приложения (без мигания).
export function initTheme() { applyTheme(getTheme()) }
