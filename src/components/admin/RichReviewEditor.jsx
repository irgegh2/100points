import { useRef, useState } from 'react'
import { Bold, Heading3, ImagePlus, Italic, List, Quote } from 'lucide-react'
import MarkdownContent from '../MarkdownContent.jsx'
import { adminReviewImageUpload } from '../../api.js'

export default function RichReviewEditor({ value, onChange }) {
  const areaRef = useRef(null)
  const imageRef = useRef(null)
  const [uploading, setUploading] = useState(false)
  const [error, setError] = useState('')

  const insert = (before, after = '', fallback = 'текст') => {
    const area = areaRef.current
    const start = area?.selectionStart ?? value.length; const end = area?.selectionEnd ?? value.length
    const selected = value.slice(start, end) || fallback
    const next = value.slice(0, start) + before + selected + after + value.slice(end)
    onChange(next)
    requestAnimationFrame(() => { area?.focus(); area?.setSelectionRange(start + before.length, start + before.length + selected.length) })
  }
  const prefixLines = (prefix) => {
    const area = areaRef.current; const start = area?.selectionStart ?? value.length; const end = area?.selectionEnd ?? value.length
    const selected = value.slice(start, end) || 'пункт'
    const replacement = selected.split('\n').map((line) => prefix + line).join('\n')
    onChange(value.slice(0, start) + replacement + value.slice(end))
  }
  const uploadImage = async (event) => {
    const file = event.target.files?.[0]; event.target.value = ''
    if (!file) return
    setUploading(true); setError('')
    try {
      const data = await adminReviewImageUpload(file)
      const area = areaRef.current; const pos = area?.selectionStart ?? value.length
      const markdown = `${pos > 0 && !value.endsWith('\n') ? '\n\n' : ''}![Пояснение](${data.url})\n\n`
      onChange(value.slice(0, pos) + markdown + value.slice(pos))
    } catch (e) { setError(e.message) } finally { setUploading(false) }
  }

  return <div className="rich-review">
    <div className="rich-review__bar">
      <button type="button" title="Жирный" onClick={() => insert('**', '**')}><Bold size={15} /></button>
      <button type="button" title="Курсив" onClick={() => insert('*', '*')}><Italic size={15} /></button>
      <button type="button" title="Заголовок" onClick={() => prefixLines('### ')}><Heading3 size={15} /></button>
      <button type="button" title="Список" onClick={() => prefixLines('- ')}><List size={15} /></button>
      <button type="button" title="Цитата" onClick={() => prefixLines('> ')}><Quote size={15} /></button>
      <button type="button" title="Вставить изображение" disabled={uploading} onClick={() => imageRef.current?.click()}><ImagePlus size={15} /> {uploading ? 'Загрузка…' : 'Фото'}</button>
      <span>Markdown</span>
      <input ref={imageRef} hidden type="file" accept="image/jpeg,image/png,image/webp,image/gif" onChange={uploadImage} />
    </div>
    <div className="rich-review__grid">
      <textarea ref={areaRef} className="admin__textarea" rows={12} value={value} onChange={(e) => onChange(e.target.value)} placeholder={'### Разбор ответа\n\n**Что получилось:** …\n\n- Что исправить\n- На что обратить внимание'} />
      <div className="rich-review__preview"><span>Предпросмотр</span><MarkdownContent source={value} empty="Здесь появится оформленный комментарий" /></div>
    </div>
    {error && <div className="rich-review__error">{error}</div>}
  </div>
}
