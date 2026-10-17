import { useEffect, useState } from 'react'
import { ArrowLeft, ArrowRight, Award, BookOpen, Compass, Gem, X } from 'lucide-react'
import { tourComplete, tourProgress, tourStatus } from '../api.js'

const STEPS = [
  {
    section: 'home', target: 'home', kicker: 'Точка старта', title: 'Это твоя главная',
    text: 'Здесь собраны занятия на сегодня, ежедневные задания и твой учебный прогресс.',
  },
  {
    section: 'courses', target: 'courses', kicker: 'Учебный маршрут', title: 'Курсы и уроки',
    text: 'В курсах лежат открытые тебе программы. Заходи в курс, выбирай урок и двигайся по разделам.',
  },
  {
    section: 'schedule', target: 'schedule', kicker: 'Не пропусти', title: 'Расписание',
    text: 'Здесь видны вебинары, практики и дедлайны. Платформа сама собирает их в одну неделю.',
  },
  {
    section: 'aichat', target: 'aichat', kicker: 'Можно спрашивать', title: 'ИИ-чат',
    text: 'Если тема не даётся, открой чат: помощник объяснит ход решения и ответит по платформе.',
  },
  {
    section: 'trainer', target: 'trainer', kicker: 'Закрепи тему', title: 'ИИ-тренажёр',
    text: 'Тренажёр подбирает задания по предмету и сложности, а затем сразу разбирает ответ.',
  },
  {
    section: 'homework', target: 'homework', kicker: 'Проверь себя', title: 'Домашние задания',
    text: 'Решай домашки до дедлайна. За успешные работы начисляются кристаллы и открываются достижения.',
  },
  {
    section: 'shop', target: 'wallet', kicker: 'Награда за путь', title: 'Кристаллы и магазин',
    text: 'Кристаллы видны сверху. Их можно получать за учёбу и тратить в магазине. Заверши тур — получишь первые 100.',
  },
]

export default function Tour({ onNavigate, onReward, onActiveChange }) {
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(false)
  const [step, setStep] = useState(0)
  const [completed, setCompleted] = useState(false)
  const [celebrate, setCelebrate] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    let alive = true
    tourStatus()
      .then((data) => {
        if (!alive) return
        const done = Boolean(data.completed)
        setCompleted(done)
        setStep(Math.min(STEPS.length - 1, Math.max(0, Number(data.step) || 0)))
        setOpen(!done)
        setLoading(false)
      })
      .catch(() => { if (alive) setLoading(false) })
    return () => { alive = false }
  }, [])

  useEffect(() => {
    onActiveChange?.(loading || open || celebrate)
  }, [loading, open, celebrate, onActiveChange])

  useEffect(() => () => onActiveChange?.(false), [onActiveChange])

  useEffect(() => {
    if (!open) return undefined
    const current = STEPS[step]
    onNavigate?.(current.section)

    const timer = window.setTimeout(() => {
      document.querySelectorAll('.tour-target').forEach((node) => node.classList.remove('tour-target'))
      const target = document.querySelector(`[data-tour="${current.target}"]`)
      target?.classList.add('tour-target')
      target?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
    }, 80)

    return () => {
      window.clearTimeout(timer)
      document.querySelectorAll('.tour-target').forEach((node) => node.classList.remove('tour-target'))
    }
  }, [open, step, onNavigate])

  const next = async () => {
    setError('')
    if (step < STEPS.length - 1) {
      const nextStep = step + 1
      setStep(nextStep)
      tourProgress(nextStep).catch(() => {})
      return
    }

    setBusy(true)
    try {
      const data = await tourComplete()
      setCompleted(true)
      setOpen(false)
      setCelebrate(true)
      onReward?.(data.balance)
    } catch (err) {
      setError(err.message || 'Не удалось начислить награду. Попробуй ещё раз.')
    } finally {
      setBusy(false)
    }
  }

  if (loading) return null
  const current = STEPS[step]
  const percent = ((step + 1) / STEPS.length) * 100

  return (
    <>
      {!completed && !open && (
        <div className="tour">
          <button className="tour__pill" onClick={() => setOpen(true)}>
            <Compass size={16} /> Продолжить тур <b>{step + 1}/{STEPS.length}</b>
          </button>
        </div>
      )}

      {open && (
        <section className="tourguide" aria-live="polite" aria-label="Тур по платформе">
          <div className="tourguide__card">
            <div className="tourguide__top">
              <span className="tourguide__route"><Compass size={15} /> Тур по платформе</span>
              <span className="tourguide__count">{step + 1} из {STEPS.length}</span>
              <button className="tourguide__close" onClick={() => setOpen(false)} aria-label="Закрыть тур"><X size={18} /></button>
            </div>
            <div className="tourguide__track"><span style={{ width: `${percent}%` }} /></div>
            <div className="tourguide__body">
              <span className="tourguide__kicker">{current.kicker}</span>
              <h2>{current.title}</h2>
              <p>{current.text}</p>
              {step === STEPS.length - 1 && <div className="tourguide__reward"><Gem size={17} /> +100 кристаллов после завершения</div>}
              {error && <div className="tourguide__error">{error}</div>}
            </div>
            <div className="tourguide__actions">
              <button className="tourguide__back" onClick={() => setStep((s) => Math.max(0, s - 1))} disabled={step === 0 || busy}>
                <ArrowLeft size={17} /> Назад
              </button>
              <button className="tourguide__next" onClick={next} disabled={busy}>
                {step === STEPS.length - 1 ? (busy ? 'Начисляем…' : 'Завершить и получить 100') : 'Дальше'}
                {step === STEPS.length - 1 ? <Gem size={17} /> : <ArrowRight size={17} />}
              </button>
            </div>
          </div>
        </section>
      )}

      {celebrate && (
        <div className="tmodal" onClick={() => setCelebrate(false)}>
          <div className="tmodal__card" onClick={(event) => event.stopPropagation()}>
            <div className="tmodal__art">
              <span className="tmodal__circle"><BookOpen size={42} /></span>
              <i className="tmodal__conf c1" /><i className="tmodal__conf c2" /><i className="tmodal__conf c3" />
              <i className="tmodal__conf c4" /><i className="tmodal__conf c5" /><i className="tmodal__conf c6" />
            </div>
            <span className="tmodal__badge"><Award size={13} /> Получено +100</span>
            <h2>Маршрут изучен</h2>
            <p>100 кристаллов уже в кошельке. Теперь можно возвращаться к учёбе — нужные разделы ты знаешь.</p>
            <button className="btn btn-orange btn-lg btn-block" onClick={() => setCelebrate(false)}>Начать заниматься</button>
          </div>
        </div>
      )}
    </>
  )
}
