import { useEffect, useRef, useState } from 'react'
import { FileText, Paperclip, Trash2, UploadCloud } from 'lucide-react'
import { answerFileDelete, answerFilePending, answerFileUpload } from '../api.js'
import { countRu } from '../ruPlural.js'

const sizeText = (bytes = 0) => bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} МБ` : `${Math.max(1, Math.round(bytes / 1024))} КБ`

export default function FileAnswer({ questionId, limit = 1, value, onChange, assignmentId = null }) {
  const inputRef = useRef(null)
  const recoveredRef = useRef(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const answer = value && typeof value === 'object' ? value : { note: '', files: [] }
  const files = Array.isArray(answer.files) ? answer.files : []
  const exact = Math.max(1, Math.min(10, Number(limit) || 1))

  useEffect(() => {
    if (recoveredRef.current || files.length) return
    recoveredRef.current = true
    answerFilePending(questionId).then((data) => {
      if (data.files?.length) onChange({ ...answer, files: data.files.slice(0, exact) })
    }).catch(() => {})
  }, [questionId])

  const upload = async (event) => {
    const selected = [...(event.target.files || [])]
    event.target.value = ''
    if (!selected.length) return
    const remaining = exact - files.length
    if (selected.length > remaining) return setError(`Можно добавить ещё ${countRu(remaining, 'файл', 'файла', 'файлов')}`)
    setBusy(true); setError('')
    let next = files
    try {
      for (const file of selected) {
        const data = await answerFileUpload(questionId, file, assignmentId)
        next = [...next, data.file]
        onChange({ ...answer, files: next })
      }
    } catch (e) { setError(e.message) }
    finally { setBusy(false) }
  }

  const remove = async (file) => {
    setError('')
    try {
      await answerFileDelete(file.id)
      onChange({ ...answer, files: files.filter((item) => item.id !== file.id) })
    } catch (e) { setError(e.message) }
  }

  return (
    <div className="file-answer">
      <div className="file-answer__intro">
        <div><Paperclip size={17} /><b>Приложи ровно {countRu(exact, 'файл', 'файла', 'файлов')}</b></div>
        <span>{files.length}/{exact} · каждый до 500 МБ</span>
      </div>
      {files.length > 0 && <div className="file-answer__list">{files.map((file) => (
        <div className="file-answer__file" key={file.id}>
          <FileText size={19} /><span><b>{file.name}</b><small>{sizeText(file.size)}</small></span>
          <button type="button" onClick={() => remove(file)} aria-label={`Удалить ${file.name}`}><Trash2 size={16} /></button>
        </div>
      ))}</div>}
      {files.length < exact && (
        <button type="button" className="file-answer__upload" disabled={busy} onClick={() => inputRef.current?.click()}>
          <UploadCloud size={21} /><span><b>{busy ? 'Загружаем…' : 'Выбрать файл'}</b><small>Фото, PDF, Word, Excel или TXT</small></span>
        </button>
      )}
      <input ref={inputRef} type="file" hidden multiple onChange={upload} accept="image/jpeg,image/png,image/webp,image/heic,.pdf,.doc,.docx,.xls,.xlsx,.txt" />
      <label className="file-answer__note">
        <span>Развёрнутая подпись для проверяющего</span>
        <textarea className="test__textarea" rows={6} value={answer.note || ''} onChange={(e) => onChange({ ...answer, note: e.target.value })} placeholder="Опиши решение, поясни файлы и укажи, на что проверяющему обратить внимание…" />
      </label>
      {error && <div className="file-answer__error">{error}</div>}
      <p className="test__hint">Ответ считается заполненным, когда загружены все файлы и написана подпись. Проверка — только вручную.</p>
    </div>
  )
}
