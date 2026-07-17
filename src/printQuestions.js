// Печать списка вопросов в отдельном окне (для банка, ДЗ, пробников).
export function printQuestions(title, questions) {
  const w = window.open('', '_blank')
  if (!w) return
  const esc = (s) => String(s ?? '').replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]))
  const items = questions.map((q, i) => {
    const opts = q.options ? '<ol type="1">' + q.options.map((o) => `<li>${esc(o)}</li>`).join('') + '</ol>' : ''
    const line = q.type === 'text' || q.type === 'textarea' ? '<div style="border-bottom:1px solid #999;height:22px;margin-top:6px"></div>' : ''
    return `<div style="margin:0 0 18px"><b>№${i + 1}.</b> ${esc(q.text)}${opts}${line}</div>`
  }).join('')
  w.document.write(`<html><head><title>${esc(title)}</title><meta charset="utf-8"><style>body{font-family:-apple-system,Arial,sans-serif;padding:30px;max-width:720px;margin:auto;color:#111}h1{font-size:20px}ol{margin:6px 0}</style></head><body><h1>${esc(title)}</h1>${items}<script>window.onload=()=>window.print()<\/script></body></html>`)
  w.document.close()
}
