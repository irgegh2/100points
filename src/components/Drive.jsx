import { useEffect, useMemo, useRef, useState } from 'react'
import {
  ArrowRight, Check, ChevronRight, CloudUpload, File, FileArchive, FileImage,
  FileText, Folder, FolderPlus, Gauge, HardDrive, MoreHorizontal, Pencil, Search, Download,
  ShoppingBag, Trash2, Upload, X,
} from 'lucide-react'
import { driveCreateFolder, driveDelete, driveList, driveRename, driveUpload } from '../api.js'
import { pluralRu } from '../ruPlural.js'
import { useLiveRefresh } from '../liveUpdates.js'

const formatBytes = (bytes) => {
  const value = Math.max(0, Number(bytes || 0))
  if (value >= 1073741824) return `${(value / 1073741824).toLocaleString('ru-RU', { maximumFractionDigits: 2 })} ГБ`
  if (value >= 1048576) return `${(value / 1048576).toLocaleString('ru-RU', { maximumFractionDigits: 1 })} МБ`
  if (value >= 1024) return `${Math.round(value / 1024)} КБ`
  return `${value} Б`
}

const formatDate = (value) => {
  if (!value) return ''
  const parsed = new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z'))
  return Number.isNaN(parsed.getTime()) ? '' : parsed.toLocaleDateString('ru-RU', { day: '2-digit', month: 'short', year: 'numeric' })
}

const fileIcon = (item) => {
  const mime = item.mime || ''
  if (mime.startsWith('image/')) return FileImage
  if (mime.includes('zip') || mime.includes('rar') || mime.includes('7z') || mime.includes('archive')) return FileArchive
  if (mime.includes('pdf') || mime.startsWith('text/') || mime.includes('word')) return FileText
  return File
}

function DriveDialog({ dialog, busy, onClose, onSubmit }) {
  const [value, setValue] = useState(dialog?.value || '')
  useEffect(() => setValue(dialog?.value || ''), [dialog])
  if (!dialog) return null
  const destructive = dialog.type === 'delete'
  const title = dialog.type === 'folder' ? 'Новая папка' : dialog.type === 'rename' ? 'Переименовать' : `Удалить «${dialog.item?.name}»?`
  return (
    <div className="drive-modal" onMouseDown={(event) => { if (event.target === event.currentTarget && !busy) onClose() }}>
      <form className="drive-modal__box" onSubmit={(event) => { event.preventDefault(); onSubmit(destructive ? '' : value) }}>
        <button className="drive-modal__close" type="button" onClick={onClose} disabled={busy} aria-label="Закрыть"><X size={18} /></button>
        <span className={`drive-modal__icon ${destructive ? 'danger' : ''}`}>{destructive ? <Trash2 size={22} /> : dialog.type === 'folder' ? <FolderPlus size={22} /> : <Pencil size={22} />}</span>
        <h2>{title}</h2>
        {destructive ? (
          <p>{dialog.item?.kind === 'folder' ? 'Папка и все файлы внутри будут удалены без возможности восстановления.' : 'Файл будет удалён без возможности восстановления.'}</p>
        ) : (
          <input autoFocus value={value} onChange={(event) => setValue(event.target.value)} maxLength={dialog.type === 'folder' ? 120 : 240} placeholder="Название" />
        )}
        <div className="drive-modal__actions">
          <button type="button" className="drive-btn is-ghost" onClick={onClose} disabled={busy}>Отмена</button>
          <button type="submit" className={`drive-btn ${destructive ? 'is-danger' : 'is-primary'}`} disabled={busy || (!destructive && !value.trim())}>{busy ? 'Подожди…' : destructive ? 'Удалить' : 'Сохранить'}</button>
        </div>
      </form>
    </div>
  )
}

export default function Drive({ onShop }) {
  const [folderId, setFolderId] = useState(0)
  const [items, setItems] = useState([])
  const [breadcrumbs, setBreadcrumbs] = useState([{ id: 0, name: 'Мой диск' }])
  const [quota, setQuota] = useState(null)
  const [query, setQuery] = useState('')
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [toast, setToast] = useState('')
  const [menuId, setMenuId] = useState(0)
  const [dialog, setDialog] = useState(null)
  const [busy, setBusy] = useState(false)
  const [uploading, setUploading] = useState(null)
  const pickerRef = useRef(null)
  const searchRef = useRef(null)

  const reload = async (nextFolder = folderId, nextSearch = search) => {
    setLoading(true); setError('')
    try {
      const data = await driveList({ parentId: nextFolder, q: nextSearch })
      setItems(data.items || []); setBreadcrumbs(data.breadcrumbs || [{ id: 0, name: 'Мой диск' }]); setQuota(data.quota || null)
    } catch (e) { setError(e.message) }
    finally { setLoading(false) }
  }

  useEffect(() => { reload(folderId, search) }, [folderId, search])
  useLiveRefresh(() => { if (!busy && !uploading && !dialog) reload() })
  useEffect(() => {
    const timer = setTimeout(() => setSearch(query.trim()), 260)
    return () => clearTimeout(timer)
  }, [query])
  useEffect(() => {
    const close = () => setMenuId(0)
    const shortcut = (event) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault(); searchRef.current?.focus()
      }
    }
    window.addEventListener('click', close)
    window.addEventListener('keydown', shortcut)
    return () => { window.removeEventListener('click', close); window.removeEventListener('keydown', shortcut) }
  }, [])

  const showToast = (message) => {
    setToast(message)
    setTimeout(() => setToast(''), 3600)
  }

  const openFolder = (id) => {
    setQuery(''); setSearch(''); setFolderId(id); setMenuId(0)
  }

  const uploadFiles = async (files) => {
    const list = Array.from(files || [])
    if (!list.length || uploading) return
    let completed = 0
    const failed = []
    try {
      for (let index = 0; index < list.length; index++) {
        const file = list[index]
        setUploading({ name: file.name, percent: 0, current: index + 1, total: list.length })
        try {
          await driveUpload(file, folderId, (percent) => setUploading({ name: file.name, percent, current: index + 1, total: list.length }))
          completed++
        } catch (e) {
          failed.push(`${file.name}: ${e.message}`)
        }
      }
      if (completed) showToast(`${completed} ${pluralRu(completed, 'файл загружен', 'файла загружены', 'файлов загружено')}`)
      await reload()
      if (failed.length) setError(`Не загрузились: ${failed.join(' · ')}`)
    } finally {
      setUploading(null)
      if (pickerRef.current) pickerRef.current.value = ''
    }
  }

  const submitDialog = async (value) => {
    if (!dialog || busy) return
    setBusy(true); setError('')
    try {
      if (dialog.type === 'folder') {
        await driveCreateFolder(value.trim(), folderId)
        showToast('Папка создана')
      } else if (dialog.type === 'rename') {
        await driveRename(dialog.item.id, value.trim())
        showToast('Название сохранено')
      } else {
        await driveDelete(dialog.item.id)
        showToast(dialog.item.kind === 'folder' ? 'Папка удалена' : 'Файл удалён')
      }
      setDialog(null); await reload()
    } catch (e) { setError(e.message) }
    finally { setBusy(false) }
  }

  const usedPercent = quota?.total_bytes ? Math.min(100, (quota.used_bytes / quota.total_bytes) * 100) : 0
  const usedPercentLabel = usedPercent.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  const sorted = useMemo(() => [...items].sort((a, b) => (a.kind === b.kind ? a.name.localeCompare(b.name, 'ru') : a.kind === 'folder' ? -1 : 1)), [items])
  const downloadUrl = (item) => item.url ? `${item.url}${item.url.includes('?') ? '&' : '?'}download=1` : '#'

  return (
    <div className="drive-page">
      {toast && <div className="drive-toast"><Check size={17} /> {toast}</div>}
      <section className="drive-hero" data-drive-tour="intro">
        <div className="drive-hero__copy">
          <span className="drive-hero__eyebrow"><HardDrive size={15} /> Стобалльный диск</span>
          <h2>Файлы для учёбы<br /><em>всегда под рукой</em></h2>
          <p>Конспекты, проекты, фото и документы хранятся в твоём приватном облаке.</p>
        </div>
        <div className="drive-meter" data-drive-tour="quota">
          <div className="drive-meter__top"><span>Занято</span><b>{formatBytes(quota?.used_bytes)} <small>из {formatBytes(quota?.total_bytes)}</small></b></div>
          <div className="drive-meter__bar"><i style={{ width: `${usedPercent}%` }} /></div>
          <div className="drive-meter__bottom"><span>{usedPercentLabel}% использовано</span><button onClick={onShop}><ShoppingBag size={14} /> Купить +1 ГБ</button></div>
        </div>
      </section>

      <section className="drive-growth">
        <div className="drive-growth__icon"><Gauge size={21} /></div>
        <div className="drive-growth__copy"><b>Место растёт вместе с прогрессом</b><span>100 МБ сразу и +25 МБ за каждую сданную домашку — без верхнего лимита.</span></div>
        <div className="drive-growth__stat"><b>{quota?.homework_count ?? 0}</b><span>{pluralRu(quota?.homework_count ?? 0, 'домашка', 'домашки', 'домашек')} сдано</span></div>
        <div className="drive-growth__track"><i style={{ width: '100%' }} /></div>
      </section>

      <section className="drive-browser">
        <div className="drive-toolbar">
          <div className="drive-actions" data-drive-tour="actions">
            <button className="drive-btn is-primary" onClick={() => pickerRef.current?.click()} disabled={!!uploading}><Upload size={17} /> Загрузить</button>
            <button className="drive-btn is-ghost" onClick={() => setDialog({ type: 'folder', value: '' })}><FolderPlus size={17} /> Новая папка</button>
            <input ref={pickerRef} type="file" multiple hidden onChange={(event) => uploadFiles(event.target.files)} />
          </div>
          <label className="drive-search"><Search size={17} /><input ref={searchRef} value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Поиск на диске" /><kbd>⌘ K</kbd></label>
        </div>

        <div className="drive-crumbs">
          {search ? <><Search size={15} /><b>Результаты поиска</b><button onClick={() => setQuery('')}>Сбросить</button></> : breadcrumbs.map((crumb, index) => (
            <span key={crumb.id}>{index > 0 && <ChevronRight size={15} />}<button className={index === breadcrumbs.length - 1 ? 'active' : ''} onClick={() => openFolder(crumb.id)}>{crumb.name}</button></span>
          ))}
        </div>

        {uploading && (
          <div className="drive-uploading"><span className="drive-uploading__icon"><CloudUpload size={20} /></span><div><b>{uploading.name}</b><span>Файл {uploading.current} из {uploading.total}</span></div><div className="drive-uploading__bar"><i style={{ width: `${uploading.percent}%` }} /></div><strong>{uploading.percent}%</strong></div>
        )}
        {error && <div className="drive-error"><span>{error}</span><button onClick={() => setError('')}><X size={16} /></button></div>}

        <div className="drive-list-head"><span>Название</span><span>Размер</span><span>Изменён</span><span /></div>
        <div className="drive-list" data-drive-tour="browser">
          {loading && <div className="drive-empty">Загружаем содержимое…</div>}
          {!loading && !error && sorted.length === 0 && (
            <div className="drive-empty is-blank"><span><CloudUpload size={28} /></span><b>{search ? 'Ничего не найдено' : 'Здесь пока пусто'}</b><p>{search ? 'Попробуй изменить запрос.' : 'Загрузи первый файл или создай папку.'}</p>{!search && <button onClick={() => pickerRef.current?.click()}>Выбрать файл <ArrowRight size={15} /></button>}</div>
          )}
          {!loading && sorted.map((item) => {
            const Icon = item.kind === 'folder' ? Folder : fileIcon(item)
            const content = <><span className={`drive-item__icon is-${item.kind}`}><Icon size={20} fill={item.kind === 'folder' ? 'currentColor' : 'none'} /></span><span className="drive-item__name"><b>{item.name}</b><small>{item.kind === 'folder' ? 'Папка' : (item.mime || 'Файл')}</small></span></>
            return (
              <div className="drive-item" key={item.id}>
                {item.kind === 'folder' ? <button className="drive-item__open" onClick={() => openFolder(item.id)}>{content}</button> : <a className="drive-item__open" href={item.url} target="_blank" rel="noreferrer">{content}</a>}
                <span className="drive-item__size">{formatBytes(item.size_bytes)}</span>
                <span className="drive-item__date">{formatDate(item.updated_at || item.created_at)}</span>
                <div className="drive-item__more" onClick={(event) => event.stopPropagation()}>
                  <button aria-label="Действия" onClick={(event) => { event.stopPropagation(); setMenuId(menuId === item.id ? 0 : item.id) }}><MoreHorizontal size={19} /></button>
                  {menuId === item.id && <div className="drive-item__menu" onClick={(event) => event.stopPropagation()}>{item.kind === 'file' && <a href={downloadUrl(item)} onClick={() => setMenuId(0)}><Download size={14} /> Скачать</a>}<button onClick={() => { setMenuId(0); setDialog({ type: 'rename', item, value: item.name }) }}><Pencil size={14} /> Переименовать</button><button className="danger" onClick={() => { setMenuId(0); setDialog({ type: 'delete', item }) }}><Trash2 size={14} /> Удалить</button></div>}
                </div>
              </div>
            )
          })}
        </div>
      </section>

      <DriveDialog dialog={dialog} busy={busy} onClose={() => !busy && setDialog(null)} onSubmit={submitDialog} />
    </div>
  )
}
