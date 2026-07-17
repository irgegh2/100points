import { ChevronRight } from 'lucide-react'

export default function AdminFold({ title, meta, children }) {
  return (
    <details className="admin__fold">
      <summary>
        <span className="admin__fold-caret"><ChevronRight size={16} /></span>
        <span className="admin__fold-title">{title}</span>
        {meta && <span className="admin__fold-meta">{meta}</span>}
      </summary>
      <div className="admin__fold-body">{children}</div>
    </details>
  )
}
