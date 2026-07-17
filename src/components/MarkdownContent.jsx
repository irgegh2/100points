import { Fragment } from 'react'

function inline(text) {
  const pattern = /(!\[[^\]]*\]\((?:https?:\/\/|\/)[^)]+\)|\[[^\]]+\]\((?:https?:\/\/|\/)[^)]+\)|\*\*[^*]+\*\*|`[^`]+`|\*[^*]+\*)/g
  return String(text).split(pattern).filter(Boolean).map((part, index) => {
    let m = part.match(/^!\[([^\]]*)\]\(((?:https?:\/\/|\/)[^)]+)\)$/)
    if (m) return <img className="markdown__image" src={m[2]} alt={m[1]} loading="lazy" key={index} />
    m = part.match(/^\[([^\]]+)\]\(((?:https?:\/\/|\/)[^)]+)\)$/)
    if (m) return <a href={m[2]} target="_blank" rel="noreferrer" key={index}>{m[1]}</a>
    if (part.startsWith('**') && part.endsWith('**')) return <strong key={index}>{part.slice(2, -2)}</strong>
    if (part.startsWith('`') && part.endsWith('`')) return <code key={index}>{part.slice(1, -1)}</code>
    if (part.startsWith('*') && part.endsWith('*')) return <em key={index}>{part.slice(1, -1)}</em>
    return <Fragment key={index}>{part}</Fragment>
  })
}

export default function MarkdownContent({ source, empty = 'Комментарий не добавлен' }) {
  const lines = String(source || '').replace(/\r/g, '').split('\n')
  if (!String(source || '').trim()) return <p className="markdown__empty">{empty}</p>
  return <div className="markdown">{lines.map((line, index) => {
    if (!line.trim()) return <div className="markdown__space" key={index} />
    const heading = line.match(/^(#{1,3})\s+(.+)$/)
    if (heading) { const Tag = `h${heading[1].length + 2}`; return <Tag key={index}>{inline(heading[2])}</Tag> }
    if (/^>\s?/.test(line)) return <blockquote key={index}>{inline(line.replace(/^>\s?/, ''))}</blockquote>
    if (/^[-*]\s+/.test(line)) return <div className="markdown__li" key={index}><span>•</span><p>{inline(line.replace(/^[-*]\s+/, ''))}</p></div>
    const ordered = line.match(/^\d+\.\s+(.+)$/)
    if (ordered) return <div className="markdown__li" key={index}><span>{line.match(/^\d+/)[0]}.</span><p>{inline(ordered[1])}</p></div>
    return <p key={index}>{inline(line)}</p>
  })}</div>
}
