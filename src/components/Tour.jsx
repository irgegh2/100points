import { useState } from 'react'
import { X, Check, Compass, Award } from 'lucide-react'

const STEPS = [
  { label: 'Пройди вводное тестирование', pts: 5 },
  { label: 'Посмотри видео практики', pts: 10 },
  { label: 'Реши тест', pts: 15 },
  { label: 'Пройди практику', pts: 25 },
  { label: 'Реши домашку', pts: 25 },
  { label: 'Найди пробник', pts: 25 },
  { label: 'Получи первую ачивку', pts: 100 },
]

export default function Tour({ defaultOpen = false, defaultModal = false }) {
  const [open, setOpen] = useState(defaultOpen)
  // Прогресс тура сохраняется в браузере — не сбрасывается при перезагрузке.
  const [done, setDone] = useState(() => {
    try { return new Set(JSON.parse(localStorage.getItem('tour_done') || '[]')) } catch { return new Set() }
  })
  const [modal, setModal] = useState(defaultModal)

  const toggle = (i) => setDone((s) => {
    const n = new Set(s)
    n.has(i) ? n.delete(i) : n.add(i)
    try { localStorage.setItem('tour_done', JSON.stringify([...n])) } catch { /* пусто */ }
    if (n.size === STEPS.length && localStorage.getItem('tour_modal') !== '1') {
      setModal(true) // тур пройден — поздравление один раз
      try { localStorage.setItem('tour_modal', '1') } catch { /* пусто */ }
    }
    return n
  })

  return (
    <div className="tour">
      {open && (
        <div className="tour__panel">
          <div className="tour__head">
            <b>Тур по платформе</b>
            <span className="tour__count">{done.size} из {STEPS.length}</span>
            <button className="tour__close" onClick={() => setOpen(false)} aria-label="Закрыть"><X size={16} /></button>
          </div>
          <div className="tour__steps">
            {STEPS.map((s, i) => {
              const isDone = done.has(i)
              return (
                <button className={`tstep ${isDone ? 'done' : ''}`} key={i} onClick={() => toggle(i)}>
                  <span className="tstep__node">{isDone ? <Check size={13} /> : i + 1}</span>
                  <span className="tstep__label">{s.label}</span>
                  <span className="tstep__pts">+{s.pts}</span>
                </button>
              )
            })}
          </div>
        </div>
      )}

      <button className="tour__pill" onClick={() => setOpen((o) => !o)}>
        <Compass size={16} /> Тур по платформе <b>{done.size} из {STEPS.length}</b>
      </button>

      {modal && (
        <div className="tmodal" onClick={() => setModal(false)}>
          <div className="tmodal__card" onClick={(e) => e.stopPropagation()}>
            <div className="tmodal__art">
              <span className="tmodal__circle"><Award size={46} /></span>
              <i className="tmodal__conf c1" /><i className="tmodal__conf c2" /><i className="tmodal__conf c3" />
              <i className="tmodal__conf c4" /><i className="tmodal__conf c5" /><i className="tmodal__conf c6" />
            </div>
            <span className="tmodal__badge"><Award size={13} /> Получено +5</span>
            <h2>Вы прошли тур и получили первую ачивку</h2>
            <p>Вы успешно прошли тур и разблокировали свою первую ачивку! Теперь вы можете получать достижения за свои действия и прогресс.</p>
            <button className="btn btn-blue btn-lg btn-block" onClick={() => setModal(false)}>Отлично</button>
          </div>
        </div>
      )}
    </div>
  )
}
