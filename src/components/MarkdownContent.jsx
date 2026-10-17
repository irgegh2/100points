import { Fragment } from 'react'
import katex from 'katex'
import 'katex/dist/katex.min.css'

function formula(source, displayMode, key) {
  const html = katex.renderToString(source, {
    displayMode,
    throwOnError: false,
    strict: 'ignore',
  })
  const Tag = displayMode ? 'div' : 'span'
  return <Tag className={`markdown__math markdown__math--${displayMode ? 'display' : 'inline'}`} dangerouslySetInnerHTML={{ __html: html }} key={key} />
}

function inline(text) {
  const pattern = /(!\[[^\]]*\]\((?:https?:\/\/|\/)[^)]+\)|\[[^\]]+\]\((?:https?:\/\/|\/)[^)]+\)|\$[^$\n]+\$|\*\*[^*]+\*\*|`[^`]+`|\*[^*]+\*)/g
  return String(text).split(pattern).filter(Boolean).map((part, index) => {
    let m = part.match(/^!\[([^\]]*)\]\(((?:https?:\/\/|\/)[^)]+)\)$/)
    if (m) return <img className="markdown__image" src={m[2]} alt={m[1]} loading="lazy" key={index} />
    m = part.match(/^\[([^\]]+)\]\(((?:https?:\/\/|\/)[^)]+)\)$/)
    if (m) return <a href={m[2]} target="_blank" rel="noreferrer" key={index}>{m[1]}</a>
    if (part.startsWith('$') && part.endsWith('$')) return formula(part.slice(1, -1), false, index)
    if (part.startsWith('**') && part.endsWith('**')) return <strong key={index}>{part.slice(2, -2)}</strong>
    if (part.startsWith('`') && part.endsWith('`')) return <code key={index}>{part.slice(1, -1)}</code>
    if (part.startsWith('*') && part.endsWith('*')) return <em key={index}>{part.slice(1, -1)}</em>
    return <Fragment key={index}>{part}</Fragment>
  })
}

function tableCells(line) {
  let value = String(line).trim()
  if (value.startsWith('|')) value = value.slice(1)
  if (value.endsWith('|')) value = value.slice(0, -1)
  return value.split('|').map((cell) => cell.trim())
}

function isTableDivider(line) {
  const cells = tableCells(line)
  return cells.length >= 2 && cells.every((cell) => /^:?-{3,}:?$/.test(cell))
}

export default function MarkdownContent({ source, empty = 'Комментарий не добавлен' }) {
  const lines = String(source || '').replace(/\r/g, '').split('\n')
  if (!String(source || '').trim()) return <p className="markdown__empty">{empty}</p>
  const content = []
  for (let index = 0; index < lines.length; index += 1) {
    const line = lines[index]
    if (line.includes('|') && index + 1 < lines.length && isTableDivider(lines[index + 1])) {
      const headers = tableCells(line)
      const rows = []
      index += 2
      while (index < lines.length && lines[index].includes('|') && lines[index].trim()) {
        const cells = tableCells(lines[index])
        if (cells.length !== headers.length) break
        rows.push(cells)
        index += 1
      }
      index -= 1
      content.push(
        <div className="markdown__table-wrap" key={`table-${index}`}>
          <table className="markdown__table">
            <thead><tr>{headers.map((cell, cellIndex) => <th key={cellIndex}>{inline(cell)}</th>)}</tr></thead>
            <tbody>{rows.map((cells, rowIndex) => <tr key={rowIndex}>{cells.map((cell, cellIndex) => <td key={cellIndex}>{inline(cell)}</td>)}</tr>)}</tbody>
          </table>
        </div>,
      )
      continue
    }
    if (!line.trim()) { content.push(<div className="markdown__space" key={index} />); continue }
    const displayMath = line.trim().match(/^\$\$(.+)\$\$$/)
    if (displayMath) { content.push(formula(displayMath[1], true, index)); continue }
    const heading = line.match(/^(#{1,3})\s+(.+)$/)
    if (heading) { const Tag = `h${heading[1].length + 2}`; content.push(<Tag key={index}>{inline(heading[2])}</Tag>); continue }
    if (/^>\s?/.test(line)) { content.push(<blockquote key={index}>{inline(line.replace(/^>\s?/, ''))}</blockquote>); continue }
    if (/^[-*]\s+/.test(line)) { content.push(<div className="markdown__li" key={index}><span>•</span><p>{inline(line.replace(/^[-*]\s+/, ''))}</p></div>); continue }
    const ordered = line.match(/^\d+\.\s+(.+)$/)
    if (ordered) { content.push(<div className="markdown__li" key={index}><span>{line.match(/^\d+/)[0]}.</span><p>{inline(ordered[1])}</p></div>); continue }
    content.push(<p key={index}>{inline(line)}</p>)
  }
  return <div className="markdown">{content}</div>
}
