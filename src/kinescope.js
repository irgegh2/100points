// Загрузчик официального Kinescope IFrame Player API — даёт player.seekTo() без
// перезагрузки плеера. Скрипт подключается один раз на страницу; если CDN недоступен,
// промис отклоняется и плеер урока откатывается на обычный iframe (перемотка через ?t=).
let promise = null

// Админ может вставить обычную ссылку, embed-ссылку или iframe целиком.
// В интерфейсе и API используем только безопасный id ролика Kinescope.
export function kinescopeVideoId(value) {
  const source = String(value || '').trim()
  if (!source) return ''
  const match = source.match(/(?:player\.)?kinescope\.io\/(?:embed\/)?([\w-]+)/i)
  if (match) return match[1]
  return /^[\w-]{8,}$/.test(source) ? source : ''
}

export function kinescopeEmbedUrl(value) {
  const id = kinescopeVideoId(value)
  return id ? `https://kinescope.io/embed/${id}` : ''
}

export function loadKinescopePlayerFactory() {
  if (promise) return promise
  promise = new Promise((resolve, reject) => {
    window.onKinescopeIframeAPIReady = (factory) => resolve(factory)
    const tag = document.createElement('script')
    tag.src = 'https://player.kinescope.io/latest/iframe.player.js'
    tag.async = true
    tag.onerror = () => { promise = null; reject(new Error('Kinescope API не загрузился')) }
    document.head.appendChild(tag)
  })
  return promise
}
