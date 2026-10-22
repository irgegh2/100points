import { useEffect, useRef, useState } from 'react'
import { Download, LoaderCircle } from 'lucide-react'
import { platformVideoPrepare } from '../api.js'

export default function PlatformVideoDownload({ lessonId }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [ready, setReady] = useState(null)
  const alive = useRef(true)
  useEffect(() => { alive.current = true; return () => { alive.current = false } }, [])

  const download = async () => {
    if (busy) return
    setBusy(true); setError(''); setReady(null)
    try {
      const result = await platformVideoPrepare(lessonId)
      if (!alive.current) return
      setReady(result)
      // Обычный запрос скачивания: не загружаем многочасовое видео в память браузера.
      window.location.assign(result.download_url)
    } catch (failure) {
      if (alive.current) setError(failure.message || 'Не удалось подготовить скачивание')
    } finally {
      if (alive.current) setBusy(false)
    }
  }

  return <div className="lesson-download">
    <button type="button" className="btn btn-light" disabled={busy} onClick={download}>
      {busy ? <LoaderCircle size={17} className="lesson-download__spinner" /> : <Download size={17} />}
      {busy ? 'Получаем запись…' : 'Скачать видео'}
    </button>
    <div className="lesson-download__message" aria-live="polite">
      {error ? <span className="lesson-download__error" role="alert">{error}</span>
        : busy ? <span>Подготовка может занять около минуты.</span>
        : ready ? <span>MP4{ready.height ? ` · до ${ready.height}p` : ''}. Если загрузка не началась, <a href={ready.download_url}>скачать ещё раз</a>.</span>
        : <span>Сохрани запись, чтобы посмотреть без интернета.</span>}
    </div>
  </div>
}
