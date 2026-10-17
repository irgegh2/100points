import { useEffect, useRef } from 'react'

export const LIVE_CONTENT_EVENT = 'stobalnik:live-content-update'

export function emitLiveContentUpdate(detail) {
  window.dispatchEvent(new CustomEvent(LIVE_CONTENT_EVENT, { detail }))
}

// callback может меняться на каждом рендере: обработчик события остаётся один,
// а ref всегда вызывает его актуальную версию с текущим состоянием компонента.
export function useLiveRefresh(callback) {
  const callbackRef = useRef(callback)
  useEffect(() => { callbackRef.current = callback }, [callback])
  useEffect(() => {
    const refresh = (event) => callbackRef.current?.(event.detail)
    window.addEventListener(LIVE_CONTENT_EVENT, refresh)
    return () => window.removeEventListener(LIVE_CONTENT_EVENT, refresh)
  }, [])
}
