import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react'
import { ArrowLeft, ShieldAlert, Trash2, X } from 'lucide-react'

const DangerConfirmContext = createContext(null)
const CONFIRM_WORD = 'УДАЛИТЬ'

export function DangerConfirmProvider({ children }) {
  const [request, setRequest] = useState(null)

  const confirmDanger = useCallback((options) => new Promise((resolve) => {
    setRequest({
      title: 'Удалить объект?',
      target: '',
      consequence: 'Связанные данные тоже будут удалены без возможности восстановления.',
      ...options,
      resolve,
    })
  }), [])

  const finish = useCallback((result) => {
    setRequest((current) => {
      current?.resolve(result)
      return null
    })
  }, [])

  return (
    <DangerConfirmContext.Provider value={confirmDanger}>
      {children}
      {request && <DangerConfirmDialog request={request} onFinish={finish} />}
    </DangerConfirmContext.Provider>
  )
}

export function useDangerConfirm() {
  const context = useContext(DangerConfirmContext)
  if (!context) throw new Error('useDangerConfirm must be used inside DangerConfirmProvider')
  return context
}

function DangerConfirmDialog({ request, onFinish }) {
  const [step, setStep] = useState(1)
  const [understood, setUnderstood] = useState(false)
  const [typed, setTyped] = useState('')
  const inputRef = useRef(null)

  useEffect(() => {
    const onKey = (event) => {
      if (event.key === 'Escape') onFinish(false)
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onFinish])

  useEffect(() => {
    if (step === 3) inputRef.current?.focus()
  }, [step])

  return (
    <div className="danger-confirm" role="presentation" onMouseDown={(e) => e.target === e.currentTarget && onFinish(false)}>
      <section className="danger-confirm__card" role="dialog" aria-modal="true" aria-labelledby="danger-confirm-title">
        <header className="danger-confirm__head">
          <div className="danger-confirm__icon"><ShieldAlert size={23} /></div>
          <div>
            <span className="danger-confirm__eyebrow">Необратимое действие</span>
            <h2 id="danger-confirm-title">{request.title}</h2>
          </div>
          <button className="danger-confirm__close" onClick={() => onFinish(false)} aria-label="Закрыть"><X size={20} /></button>
        </header>

        <div className="danger-confirm__progress" aria-label={`Шаг ${step} из 3`}>
          {[1, 2, 3].map((n) => <span key={n} className={n <= step ? 'active' : ''} />)}
          <small>Шаг {step} из 3</small>
        </div>

        {step === 1 && <div className="danger-confirm__body">
          <p className="danger-confirm__question">Вы действительно хотите удалить:</p>
          <div className="danger-confirm__target">{request.target || 'выбранный объект'}</div>
          <div className="danger-confirm__warning"><b>Что произойдёт:</b><span>{request.consequence}</span></div>
          <p className="danger-confirm__hint">На следующем шаге потребуется отдельно подтвердить понимание последствий.</p>
        </div>}

        {step === 2 && <div className="danger-confirm__body">
          <p className="danger-confirm__question">Подтвердите последствия</p>
          <label className={`danger-confirm__check ${understood ? 'checked' : ''}`}>
            <input type="checkbox" checked={understood} onChange={(e) => setUnderstood(e.target.checked)} />
            <span><b>Я понимаю, что удаление необратимо</b><small>{request.consequence}</small></span>
          </label>
          <p className="danger-confirm__hint">Без этой отметки перейти к финальному удалению нельзя.</p>
        </div>}

        {step === 3 && <div className="danger-confirm__body">
          <p className="danger-confirm__question">Последняя проверка</p>
          <p className="danger-confirm__typecopy">Введите <b>{CONFIRM_WORD}</b>, чтобы разблокировать кнопку.</p>
          <input
            ref={inputRef}
            className="danger-confirm__input"
            value={typed}
            onChange={(e) => setTyped(e.target.value)}
            placeholder={CONFIRM_WORD}
            autoComplete="off"
          />
          <div className="danger-confirm__final-target">Будет удалено: <b>{request.target || 'выбранный объект'}</b></div>
        </div>}

        <footer className="danger-confirm__actions">
          {step > 1
            ? <button className="btn btn-light" onClick={() => setStep((value) => value - 1)}><ArrowLeft size={16} /> Назад</button>
            : <button className="btn btn-light" onClick={() => onFinish(false)}>Отмена</button>}
          {step < 3 && <button className="btn danger-confirm__next" disabled={step === 2 && !understood} onClick={() => setStep((value) => value + 1)}>Продолжить</button>}
          {step === 3 && <button className="btn danger-confirm__delete" disabled={typed.trim() !== CONFIRM_WORD} onClick={() => onFinish(true)}><Trash2 size={16} /> Удалить навсегда</button>}
        </footer>
      </section>
    </div>
  )
}
