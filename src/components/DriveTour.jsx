import { useEffect, useState } from 'react'
import { ArrowLeft, ArrowRight, CloudUpload, FolderSearch, Gauge, HardDrive, X } from 'lucide-react'
import { driveTourStatus, driveTourUpdate } from '../api.js'

const STEPS = [
  {
    target: 'intro', icon: HardDrive, kicker: 'Новое на платформе', title: 'Твой учебный диск',
    text: 'Храни конспекты, проекты, фотографии, видео и документы в личном облаке. Файлы видны только тебе.',
  },
  {
    target: 'actions', icon: CloudUpload, kicker: 'Загрузка', title: 'Файлы и папки',
    text: 'Можно выбрать сразу несколько файлов и разложить их по папкам. Загрузка продолжается прямо в облако.',
  },
  {
    target: 'browser', icon: FolderSearch, kicker: 'Порядок', title: 'Всё легко найти',
    text: 'Используй поиск, открывай папки, скачивай, переименовывай и удаляй материалы — как на обычном диске.',
  },
  {
    target: 'quota', icon: Gauge, kicker: 'Место', title: 'Диск растёт вместе с тобой',
    text: 'Сразу доступно 100 МБ, а каждая сданная домашка добавляет ещё 25 МБ без верхнего лимита. В магазине можно купить +1 ГБ.',
  },
]

export default function DriveTour({ onNavigate, blocked = false }) {
  const [ready, setReady] = useState(false)
  const [candidate, setCandidate] = useState(false)
  const [open, setOpen] = useState(false)
  const [step, setStep] = useState(0)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    let alive = true
    driveTourStatus()
      .then((data) => {
        if (!alive) return
        setStep(Math.min(STEPS.length - 1, Math.max(0, Number(data.step) || 0)))
        setCandidate(Boolean(data.eligible) && !data.completed)
        setReady(true)
      })
      .catch(() => { if (alive) setReady(true) })
    return () => { alive = false }
  }, [])

  useEffect(() => {
    setOpen(ready && candidate && !blocked)
  }, [ready, candidate, blocked])

  useEffect(() => {
    if (!open) return undefined
    onNavigate?.('drive')
    const current = STEPS[step]
    const timer = window.setTimeout(() => {
      document.querySelectorAll('.drive-tour-target').forEach((node) => node.classList.remove('drive-tour-target'))
      const target = document.querySelector(`[data-drive-tour="${current.target}"]`)
      target?.classList.add('drive-tour-target')
      target?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
    }, 100)
    return () => {
      window.clearTimeout(timer)
      document.querySelectorAll('.drive-tour-target').forEach((node) => node.classList.remove('drive-tour-target'))
    }
  }, [open, step, onNavigate])

  const closeForever = async () => {
    if (busy) return
    setBusy(true)
    setError('')
    try {
      await driveTourUpdate({ action: 'dismiss', step })
      setCandidate(false)
      setOpen(false)
    } catch (err) {
      setError(err.message || 'Не удалось закрыть обзор. Попробуй ещё раз.')
    } finally {
      setBusy(false)
    }
  }

  const next = async () => {
    if (step < STEPS.length - 1) {
      const nextStep = step + 1
      setStep(nextStep)
      driveTourUpdate({ action: 'progress', step: nextStep }).catch(() => {})
      return
    }
    setBusy(true)
    setError('')
    try {
      await driveTourUpdate({ action: 'complete', step })
      setCandidate(false)
      setOpen(false)
    } catch (err) {
      setError(err.message || 'Не удалось завершить обзор. Попробуй ещё раз.')
    } finally {
      setBusy(false)
    }
  }

  if (!open) return null
  const current = STEPS[step]
  const Icon = current.icon
  const percent = ((step + 1) / STEPS.length) * 100

  return (
    <section className="tourguide tourguide--drive" aria-live="polite" aria-label="Обзор Стобалльного диска">
      <div className="tourguide__card">
        <div className="tourguide__top">
          <span className="tourguide__route"><HardDrive size={15} /> Стобалльный диск</span>
          <span className="tourguide__count">{step + 1} из {STEPS.length}</span>
          <button className="tourguide__close" onClick={closeForever} disabled={busy} aria-label="Больше не показывать"><X size={18} /></button>
        </div>
        <div className="tourguide__track"><span style={{ width: `${percent}%` }} /></div>
        <div className="tourguide__body">
          <div className="drivetour__feature"><Icon size={21} /></div>
          <span className="tourguide__kicker">{current.kicker}</span>
          <h2>{current.title}</h2>
          <p>{current.text}</p>
          {error && <div className="tourguide__error">{error}</div>}
        </div>
        <div className="tourguide__actions">
          <button className="tourguide__back" onClick={() => setStep((value) => Math.max(0, value - 1))} disabled={step === 0 || busy}>
            <ArrowLeft size={17} /> Назад
          </button>
          <button className="tourguide__next" onClick={next} disabled={busy}>
            {step === STEPS.length - 1 ? (busy ? 'Сохраняем…' : 'Начать пользоваться') : 'Дальше'}
            {step === STEPS.length - 1 ? <HardDrive size={17} /> : <ArrowRight size={17} />}
          </button>
        </div>
      </div>
    </section>
  )
}
