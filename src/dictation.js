import { useEffect, useRef, useState } from 'react'

// Голосовой ввод через Web Speech API (без сервера). Возвращает { supported, listening, toggle }.
// onResult(text) вызывается с распознанной фразой — обычно дописываем её в поле ввода.
export function useDictation(onResult) {
  const SR = typeof window !== 'undefined' && (window.SpeechRecognition || window.webkitSpeechRecognition)
  const supported = !!SR
  const [listening, setListening] = useState(false)
  const recRef = useRef(null)
  const onResultRef = useRef(onResult)
  onResultRef.current = onResult

  const toggle = () => {
    if (!supported) return
    if (listening) { try { recRef.current?.stop() } catch { /* пусто */ } return }
    const rec = new SR()
    rec.lang = 'ru-RU'
    rec.interimResults = false
    rec.continuous = false
    rec.onresult = (e) => {
      const text = Array.from(e.results).map((r) => r[0].transcript).join(' ').trim()
      if (text) onResultRef.current?.(text)
    }
    rec.onend = () => setListening(false)
    rec.onerror = () => setListening(false)
    recRef.current = rec
    try { rec.start(); setListening(true) } catch { setListening(false) }
  }

  useEffect(() => () => { try { recRef.current?.abort() } catch { /* пусто */ } }, [])
  return { supported, listening, toggle }
}
