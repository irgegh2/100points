import { useEffect, useState } from 'react'
import { Clock, Check, ChevronLeft, GraduationCap, Download, ExternalLink, FileText } from 'lucide-react'
import { reviewQueue, reviewGet, reviewGrade, adminExportUrl } from '../../api.js'
import RichReviewEditor from './RichReviewEditor.jsx'

function ansText(a) {
  const v = a.your_value
  if (a.type === 'single') return v != null ? a.options[v] : '—'
  if (a.type === 'multi') return Array.isArray(v) && v.length ? v.map((i) => a.options[i]).join(', ') : '—'
  if (a.type === 'file') return v?.note || '— нет подписи'
  return v && String(v).trim() ? v : '— нет ответа'
}

export default function AdminReview() {
  const [queue, setQueue] = useState([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState(null)       // { submission, answers }
  const [grades, setGrades] = useState({})     // { answer_id: { points, comment } }
  const [busy, setBusy] = useState(false)

  const load = () => reviewQueue().then((d) => { setQueue(d.items || []); setLoading(false) }).catch(() => setLoading(false))
  useEffect(() => { load() }, [])

  const openSub = async (id) => {
    try {
      const d = await reviewGet(id)
      setOpen(d)
      const g = {}
      d.answers.filter((a) => ['textarea', 'file'].includes(a.type)).forEach((a) => { g[a.id] = { points: a.earned || 0, comment: a.mentor_comment || '' } })
      setGrades(g)
    } catch (e) { alert(e.message) }
  }
  const setG = (aid, k, v) => setGrades((s) => ({ ...s, [aid]: { ...s[aid], [k]: v } }))

  const submit = async () => {
    setBusy(true)
    try {
      const payload = Object.entries(grades).map(([answer_id, g]) => ({ answer_id: Number(answer_id), points: Number(g.points) || 0, comment: g.comment }))
      await reviewGrade(open.submission.id, payload)
      setOpen(null); await load()
    } catch (e) { alert(e.message) }
    finally { setBusy(false) }
  }

  // Экран проверки одной работы.
  if (open) {
    const open_qs = open.answers.filter((a) => ['textarea', 'file'].includes(a.type))
    const auto = open.answers.filter((a) => !['textarea', 'file'].includes(a.type))
    return (
      <div className="admin-wrap">
        <button className="lesson__back" onClick={() => setOpen(null)}><ChevronLeft size={17} /> К очереди</button>
        <section className="admin__panel" style={{ marginTop: 12 }}>
          <h2>{open.submission.user_name} · {open.submission.homework_title}</h2>
          <p className="admin__hint">Автопроверка: {open.submission.score} из {open.submission.max_score} б. Оцени развёрнутые ответы ниже.</p>

          {open_qs.map((a) => (
            <div className="admin__reviewq" key={a.id}>
              <div className="admin__reviewq-text">{a.text} <span className="admin__reviewq-pts">макс {a.points} б.</span></div>
              {a.type === 'file' ? <div className="admin__review-answer">
                <div className="admin__reviewq-ans"><b>Подпись ученика</b><p>{ansText(a)}</p></div>
                <div className="admin__review-files">{(a.your_value?.files || []).map((file) => <a href={file.url} target="_blank" rel="noreferrer" key={file.id}><FileText size={18} /><span><b>{file.name}</b><small>{Math.max(1, Math.round((file.size || 0) / 1024))} КБ</small></span><ExternalLink size={15} /></a>)}</div>
              </div> : <div className="admin__reviewq-ans">{ansText(a)}</div>}
              {a.solution && <div className="admin__reviewq-sol"><b>Эталон:</b> {a.solution}</div>}
              <label className="admin__review-points">Баллы за ответ (0–{a.points}) <input className="admin__inp" type="number" min={0} max={a.points} value={grades[a.id]?.points ?? 0} onChange={(e) => setG(a.id, 'points', e.target.value)} /></label>
              <label className="admin__review-comment">Разбор эксперта</label>
              <RichReviewEditor value={grades[a.id]?.comment ?? ''} onChange={(value) => setG(a.id, 'comment', value)} />
            </div>
          ))}

          {auto.length > 0 && (
            <details className="admin__reviewauto">
              <summary>Автопроверенные ответы ({auto.length})</summary>
              {auto.map((a) => (
                <div className="admin__reviewq-ans" key={a.id} style={{ marginTop: 6 }}>
                  <span className={a.is_correct ? 'admin__ok' : 'admin__bad'}>{a.is_correct ? '✓' : '✗'}</span> {a.text} — <b>{ansText(a)}</b> ({a.earned}/{a.points})
                </div>
              ))}
            </details>
          )}

          <button className="btn btn-orange btn-lg" onClick={submit} disabled={busy}>{busy ? 'Сохраняем…' : 'Проверено — выставить баллы'}</button>
          <p className="admin__hint" style={{ marginTop: 8 }}><GraduationCap size={13} style={{ verticalAlign: '-2px' }} /> После проверки работе случайно назначится эксперт-проверяющий.</p>
        </section>
      </div>
    )
  }

  return (
    <div className="admin-wrap">
      <section className="admin__panel">
        <div className="admin__panel-head">
          <h2><Clock size={18} /> Очередь проверки <span className="admin__count">{queue.length}</span></h2>
          <a className="btn btn-light btn-sm" href={adminExportUrl('results')}><Download size={15} /> Все результаты (CSV)</a>
        </div>
        <p className="admin__hint">Работы с развёрнутыми и файловыми ответами, ожидающие ручной проверки.</p>
        {loading && <div className="admin__empty">Загрузка…</div>}
        {!loading && queue.length === 0 && <div className="admin__empty"><Check size={18} /> Очередь пуста — все работы проверены.</div>}
        <div className="admin__list">
          {queue.map((q) => (
            <button className="admin__crow" key={q.id} onClick={() => openSub(q.id)}>
              <span className="admin__qbadge">{q.open_count}</span>
              <div className="admin__crow-main">
                <div className="admin__crow-title">{q.user_name} · {q.homework_title}{q.level === 'hard' && ' · повышенный'}</div>
                <div className="admin__crow-sub">{q.subject} · авто {q.score}/{q.max_score} б. · {q.created_at}</div>
              </div>
              <span className="admin__open">Проверить →</span>
            </button>
          ))}
        </div>
      </section>
    </div>
  )
}
