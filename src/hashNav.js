// Навигация через URL-хеш: '#courses/11' → ['courses', '11'].
// Раздел (и открытый внутри объект) хранится в хеше, поэтому перезагрузка
// страницы возвращает туда же, а не на главную. Пишем через replaceState,
// чтобы не засорять историю браузера записью на каждый клик по меню.
export const readHash = () => window.location.hash.replace(/^#/, '').split('/').filter(Boolean)

export const writeHash = (parts) => {
  const h = parts.filter(Boolean).join('/')
  if (window.location.hash.replace(/^#/, '') === h) return
  window.history.replaceState(null, '', h ? '#' + h : window.location.pathname + window.location.search)
}
