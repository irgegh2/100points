import { Paperclip } from 'lucide-react'

const IMG = /\.(jpe?g|png|webp|gif)$/i

// Рендер вложения в сообщении чата: картинка превью или ссылка на файл (PDF).
export default function ChatAttachment({ url }) {
  if (!url) return null
  if (IMG.test(url)) {
    return (
      <a className="chat__attimg" href={url} target="_blank" rel="noreferrer">
        <img src={url} alt="вложение" />
      </a>
    )
  }
  return (
    <a className="chat__attfile" href={url} target="_blank" rel="noreferrer">
      <Paperclip size={14} /> Открыть файл
    </a>
  )
}
