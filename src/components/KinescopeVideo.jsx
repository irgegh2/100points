import { kinescopeEmbedUrl } from '../kinescope.js'

export default function KinescopeVideo({ url, title = 'Видео к заданию' }) {
  const src = kinescopeEmbedUrl(url)
  if (!src) return null

  return (
    <div className="question-video">
      <iframe
        src={src}
        title={title}
        loading="lazy"
        frameBorder="0"
        allow="autoplay; fullscreen; picture-in-picture; encrypted-media; gyroscope; accelerometer; clipboard-write; screen-wake-lock"
        allowFullScreen
      />
    </div>
  )
}
