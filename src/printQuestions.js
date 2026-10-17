import katex from 'katex'

// Печать списка вопросов в отдельном окне (для банка, ДЗ, пробников).
export function printQuestions(title, questions) {
  const w = window.open('', '_blank')
  if (!w) return
  const esc = (s) => String(s ?? '').replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]))
  // Формулы между $...$ и $$...$$ печатаем через KaTeX, остальной текст экранируем.
  const text = (s) => String(s ?? '').split(/(\$\$[^$]+\$\$|\$[^$\n]+\$)/g).map((part) => {
    if (part.startsWith('$$') && part.endsWith('$$')) {
      return katex.renderToString(part.slice(2, -2), { displayMode: true, throwOnError: false, strict: 'ignore' })
    }
    if (part.startsWith('$') && part.endsWith('$')) {
      return katex.renderToString(part.slice(1, -1), { displayMode: false, throwOnError: false, strict: 'ignore' })
    }
    return esc(part).replace(/\n/g, '<br>')
  }).join('')
  // Окно печати — about:blank: относительные ссылки (/uploads/…) там не резолвятся,
  // поэтому приводим адреса картинок к абсолютным от текущего домена.
  const abs = (src) => { try { return new URL(src, window.location.href).href } catch { return src } }
  const items = questions.map((q, i) => {
    const imgs = (q.images || []).map((src) =>
      `<img src="${esc(abs(src))}" style="max-width:340px;max-height:240px;display:block;margin:8px 0;border-radius:8px">`
    ).join('')
    const videoNote = (q.videos || []).length ? '<div style="margin:7px 0;color:#555;font-size:12px">Видео доступно в онлайн-версии задания.</div>' : ''
    const opts = q.options ? '<ol type="1">' + q.options.map((o) => `<li>${text(o)}</li>`).join('') + '</ol>' : ''
    const lineCount = ['textarea', 'file', 'audio'].includes(q.type) ? 3 : (q.type === 'text' ? 1 : 0)
    const line = '<div style="border-bottom:1px solid #999;height:22px;margin-top:6px"></div>'.repeat(lineCount)
    return `<div style="margin:0 0 18px;break-inside:avoid"><b>№${i + 1}.</b> ${text(q.text)}${imgs}${videoNote}${opts}${line}</div>`
  }).join('')
  const styleLinks = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
    .map((link) => `<link rel="stylesheet" href="${esc(link.href)}">`).join('')
  w.document.write(`<html><head><title>${esc(title)}</title><meta charset="utf-8">${styleLinks}<style>body{font-family:-apple-system,Arial,sans-serif;padding:30px;max-width:720px;margin:auto;color:#111;line-height:1.45}h1{font-size:20px}ol{margin:6px 0}.katex-display{margin:.45em 0;overflow:hidden}</style></head><body><h1>${esc(title)}</h1>${items}<script>window.onload=()=>setTimeout(()=>window.print(),250)<\/script></body></html>`)
  w.document.close()
}
