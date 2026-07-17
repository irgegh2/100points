import { useRef } from 'react'
import { GripVertical, ChevronUp, ChevronDown } from 'lucide-react'
import { catReorder } from '../../api.js'

// Переиспользуемый список с изменением порядка: стрелки ↑↓ + drag-and-drop за «ручку».
// Перетаскивание начинается ТОЛЬКО с грипа (не с инпутов внутри строки).
// items — массив с полем id; onChange(newItems) обновляет состояние; entity — таблица для catReorder.
export default function ReorderList({ items, onChange, entity, renderRow }) {
  const dragFrom = useRef(null)
  const dragOver = useRef(null)

  const apply = (arr) => {
    onChange(arr)
    catReorder(entity, arr.map((x) => x.id)).catch((e) => alert('Не удалось сохранить порядок: ' + e.message))
  }
  const move = (from, to) => {
    if (to < 0 || to >= items.length || from === to) return
    const arr = [...items]
    const [m] = arr.splice(from, 1)
    arr.splice(to, 0, m)
    apply(arr)
  }
  const onDrop = (i) => {
    const from = dragFrom.current
    dragFrom.current = null; dragOver.current = null
    if (from !== null && from !== i) move(from, i)
  }

  return (
    <div className="admin__sortlist">
      {items.map((it, i) => (
        <div
          className="admin__sortrow"
          key={it.id}
          onDragOver={(e) => { e.preventDefault(); dragOver.current = i }}
          onDrop={() => onDrop(i)}
        >
          <span
            className="admin__grip"
            title="Перетащить"
            draggable
            onDragStart={() => { dragFrom.current = i }}
            onDragEnd={() => { dragFrom.current = null }}
          ><GripVertical size={16} /></span>

          <div className="admin__sortmain">{renderRow(it, i)}</div>

          <div className="admin__sortarrows">
            <button type="button" disabled={i === 0} title="Вверх" onClick={() => move(i, i - 1)}><ChevronUp size={15} /></button>
            <button type="button" disabled={i === items.length - 1} title="Вниз" onClick={() => move(i, i + 1)}><ChevronDown size={15} /></button>
          </div>
        </div>
      ))}
    </div>
  )
}
