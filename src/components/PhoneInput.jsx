// Поле телефона с маской +7 (XXX) XXX XX-XX.
// «+7» несъёмный, вводятся только цифры — скобки, пробелы и дефис ставятся сами.
// value/onChange работают с каноничным видом '+7XXXXXXXXXX' (для сравнения/отправки).

function digitsOf(value) {
  let d = String(value || '').replace(/\D/g, '') // только цифры, напр. '79161112233'
  if (d.startsWith('7')) d = d.slice(1)           // убираем код страны
  return d.slice(0, 10)                            // максимум 10 цифр
}

export function formatPhone(d) {
  // Важно: не дописываем разделитель «хвостом» после последней цифры,
  // иначе Backspace стирает разделитель, а он тут же возвращается — и цифру не удалить.
  let s = '+7'
  if (d.length === 0) return s
  s += ' (' + d.slice(0, 3)
  if (d.length > 3) s += ') ' + d.slice(3, 6)
  if (d.length > 6) s += ' ' + d.slice(6, 8)
  if (d.length > 8) s += '-' + d.slice(8, 10)
  return s
}

export default function PhoneInput({ value, onChange, ...props }) {
  const display = formatPhone(digitsOf(value))

  const handle = (e) => {
    const d = digitsOf(e.target.value)
    onChange('+7' + d)
  }

  // Не даём стереть весь «+7» — Backspace при пустом номере игнорируем.
  const onKeyDown = (e) => {
    if (e.key === 'Backspace' && digitsOf(value).length === 0) e.preventDefault()
  }

  return (
    <input
      type="tel"
      inputMode="numeric"
      value={display}
      onChange={handle}
      onKeyDown={onKeyDown}
      {...props}
    />
  )
}
