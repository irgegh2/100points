// Клиентские флаги авторизации (для роутинга). Реальная проверка — на сервере (сессия).
const KEY = 'lk_auth'
const AKEY = 'admin_auth'

export const isAuthed = () => localStorage.getItem(KEY) === '1'
export const setAuthed = (v) => { v ? localStorage.setItem(KEY, '1') : localStorage.removeItem(KEY) }

export const isAdminAuthed = () => localStorage.getItem(AKEY) === '1'
export const setAdminAuthed = (v) => { v ? localStorage.setItem(AKEY, '1') : localStorage.removeItem(AKEY) }

// Поддомены: lk.100-points.ru — кабинет, admin.100-points.ru — админка.
export const isLkHost = () => window.location.hostname.startsWith('lk.')
export const isAdminHost = () => window.location.hostname.startsWith('admin.')

// Локальная разработка (один origin) — на localhost/IP оставляем все роуты доступными.
export const isLocalHost = () => {
  const h = window.location.hostname
  return h === 'localhost' || h === '127.0.0.1' || /^[0-9.]+$/.test(h)
}

// Ссылка на нужный поддомен от текущего хоста.
// Прод: 100-points.ru → https://lk.100-points.ru/login (и admin. аналогично).
// Локально (localhost/127.0.0.1/IP) — отдаём относительный путь, чтобы dev работал на одном порту.
function subUrl(prefix, path = '/') {
  const h = window.location.hostname
  if (h === 'localhost' || h === '127.0.0.1' || /^[0-9.]+$/.test(h)) return path
  const base = h.replace(/^(www\.|lk\.|admin\.)/, '')
  return `${window.location.protocol}//${prefix}.${base}${path}`
}
export const lkUrl = (path = '/') => subUrl('lk', path)
export const adminUrl = (path = '/') => subUrl('admin', path)
