import { useEffect, useState } from 'react'
import { Plus, Trash2, Save, ChevronRight, X } from 'lucide-react'
import { catList, catCreate, catUpdate, catDelete, adminBankQuestions } from '../../api.js'
import ReorderList from './ReorderList.jsx'
import AdminQuestionsModal from './AdminQuestionsModal.jsx'
import { useDangerConfirm } from './DangerConfirm.jsx'

const PARTS = [['first', 'Первая часть'], ['second', 'Вторая часть']]

// ── Состав варианта-пробника: выбор существующих вопросов банка ──
function VariantQuestionsModal({ variant, subjectId, onClose }) {
  const confirmDelete = useDangerConfirm()
  const [questions, setQuestions] = useState([]) // все вопросы банка предмета
  const [selected, setSelected] = useState([])   // [{ id (variant_question), question_id, position }]
  const [q, setQ] = useState('')
  const [busy, setBusy] = useState(false)

  const load = () => adminBankQuestions(subjectId, variant.id)
    .then((d) => { setQuestions(d.questions || []); setSelected(d.selected || []) })
    .catch((e) => alert(e.message))
  useEffect(() => { load() }, [])

  const add = async (qid) => {
    setBusy(true)
    try { await catCreate('variant_questions', { variant_id: variant.id, question_id: qid, position: selected.length }); await load() }
    catch (e) { alert(e.message) } finally { setBusy(false) }
  }
  const remove = async (vq) => {
    const item = questions.find((question) => Number(question.id) === Number(vq.question_id))
    if (!await confirmDelete({ title: 'Убрать вопрос из варианта?', target: (item?.text || `Вопрос #${vq.question_id}`).slice(0, 140), consequence: 'Вопрос будет убран только из этого варианта. Сам вопрос в банке останется.' })) return
    setBusy(true)
    try { await catDelete('variant_questions', vq.id); await load() }
    catch (e) { alert(e.message) } finally { setBusy(false) }
  }

  const qMap = {}; questions.forEach((x) => { qMap[x.id] = x })
  const selIds = new Set(selected.map((s) => s.question_id))
  const needle = q.trim().toLowerCase()
  const available = questions.filter((x) => !selIds.has(x.id) && (!needle || (x.text || '').toLowerCase().includes(needle)))

  return (
    <div className="admin__modal" onClick={onClose}>
      <div className="admin__modal-box admin__modal-box--wide" onClick={(e) => e.stopPropagation()}>
        <div className="admin__modal-head"><h3>Вопросы варианта · {variant.name}</h3><button className="admin__iconbtn" onClick={onClose}><X size={18} /></button></div>

        <h4 className="lesson__mat-head">В варианте ({selected.length})</h4>
        {selected.length === 0 && <div className="admin__empty admin__empty--sm">Пока пусто — добавь вопросы из банка ниже. Они пойдут ученику в режиме «Пробник» в этом порядке.</div>}
        {selected.map((s, i) => {
          const it = qMap[s.question_id]
          return (
            <div className="admin__drow" key={s.id}>
              <span className="admin__qnum">{i + 1}</span>
              <span style={{ flex: 1 }}>{it ? <>{it.topic_number != null && <b>№{it.topic_number} </b>}{(it.text || '').slice(0, 110)}</> : `вопрос #${s.question_id} (не из этого предмета)`}</span>
              <button className="admin__iconbtn admin__iconbtn--danger" title="Убрать из варианта" onClick={() => remove(s)} disabled={busy}><Trash2 size={15} /></button>
            </div>
          )
        })}

        <h4 className="lesson__mat-head">Добавить из банка</h4>
        <input className="admin__inp" placeholder="Поиск по тексту вопроса" value={q} onChange={(e) => setQ(e.target.value)} />
        {questions.length === 0 && <div className="admin__empty admin__empty--sm">В банке этого предмета пока нет вопросов — заведи их выше в темах → подтемах → «Вопросы».</div>}
        {available.map((x) => (
          <div className="admin__drow" key={x.id}>
            <span style={{ flex: 1 }}>{x.topic_number != null && <b>№{x.topic_number} </b>}<span className="admin__pill-muted">{x.subtopic_name}</span> {(x.text || '').slice(0, 100)}</span>
            <button className="btn btn-light btn-sm" onClick={() => add(x.id)} disabled={busy}><Plus size={14} /> В вариант</button>
          </div>
        ))}
      </div>
    </div>
  )
}

// ── Подтемы темы + переход к вопросам ──
function SubtopicsModal({ topic, onClose }) {
  const confirmDelete = useDangerConfirm()
  const [items, setItems] = useState([])
  const [qModal, setQModal] = useState(null)
  const load = () => catList('subtopics', { topic_id: topic.id }).then((d) => setItems(d.items || []))
  useEffect(() => { load() }, [])
  const add = () => catCreate('subtopics', { topic_id: topic.id, name: 'Новая подтема', position: items.length }).then((d) => setItems((list) => [...list, d.item]))
  const save = (s) => catUpdate('subtopics', { id: s.id, name: s.name }).catch((e) => alert(e.message))
  const del = async (s) => {
    if (!await confirmDelete({ title: 'Удалить подтему?', target: `Подтема «${s.name}»`, consequence: 'Подтема и все вопросы внутри неё будут удалены из банка.' })) return
    try { await catDelete('subtopics', s.id); setItems((list) => list.filter((x) => x.id !== s.id)) } catch (e) { alert(e.message) }
  }
  const patch = (id, v) => setItems((a) => a.map((x) => x.id === id ? { ...x, name: v } : x))
  return (
    <div className="admin__modal" onClick={onClose}>
      <div className="admin__modal-box admin__modal-box--wide" onClick={(e) => e.stopPropagation()}>
        <div className="admin__modal-head"><h3>Подтемы · №{topic.number} {topic.name}</h3><button className="admin__iconbtn" onClick={onClose}><X size={18} /></button></div>
        {items.length === 0 && <div className="admin__empty">Нет подтем</div>}
        <ReorderList entity="subtopics" items={items} onChange={setItems} renderRow={(s) => (
          <div className="admin__drow">
            <input className="admin__inp" value={s.name} onChange={(e) => patch(s.id, e.target.value)} onBlur={() => save(s)} />
            <button className="admin__iconbtn" title="Сохранить" onClick={() => save(s)}><Save size={15} /></button>
            <button className="admin__iconbtn admin__iconbtn--danger" title="Удалить" onClick={() => del(s)}><Trash2 size={15} /></button>
            <button className="admin__open" onClick={() => setQModal(s)}>Вопросы <ChevronRight size={15} /></button>
          </div>
        )} />
        <button className="btn btn-orange btn-lg" onClick={add}><Plus size={16} /> Добавить подтему</button>
        {qModal && <AdminQuestionsModal parent={{ key: 'subtopic_id', id: qModal.id, title: qModal.name }} onClose={() => setQModal(null)} />}
      </div>
    </div>
  )
}

export default function AdminBank() {
  const confirmDelete = useDangerConfirm()
  const [subjects, setSubjects] = useState([])
  const [subjectId, setSubjectId] = useState('')
  const [topics, setTopics] = useState([])
  const [sources, setSources] = useState([])
  const [variants, setVariants] = useState([])
  const [tModal, setTModal] = useState(null)
  const [vModal, setVModal] = useState(null) // вариант, у которого открыт состав вопросов

  const loadSubjects = () => catList('subjects').then((d) => { setSubjects(d.items || []); if (!subjectId && d.items?.length) setSubjectId(d.items[0].id) })
  const loadAll = (sid) => {
    if (!sid) return
    catList('topics', { subject_id: sid }).then((d) => setTopics(d.items || []))
    catList('bank_sources', { subject_id: sid }).then((d) => setSources(d.items || []))
    catList('bank_variants', { subject_id: sid }).then((d) => setVariants(d.items || []))
  }
  useEffect(() => { loadSubjects() }, [])
  useEffect(() => { loadAll(subjectId) }, [subjectId])

  // темы
  const addTopic = () => catCreate('topics', { subject_id: subjectId, number: (topics.length + 1), name: 'Новая тема', part: 'first', error_rate: '', position: topics.length }).then((d) => setTopics((list) => [...list, d.item]))
  const saveTopic = (t) => catUpdate('topics', { id: t.id, number: t.number, name: t.name, part: t.part, error_rate: t.error_rate }).catch((e) => alert(e.message))
  const delTopic = async (t) => {
    if (!await confirmDelete({ title: 'Удалить тему банка?', target: `Тема «${t.name}»`, consequence: 'Все подтемы, вопросы и связанный прогресс учеников будут удалены.' })) return
    try { await catDelete('topics', t.id); setTopics((list) => list.filter((x) => x.id !== t.id)) } catch (e) { alert(e.message) }
  }
  const patchTopic = (id, k, v) => setTopics((a) => a.map((x) => x.id === id ? { ...x, [k]: v } : x))

  // источники / варианты
  const addSource = () => catCreate('bank_sources', { subject_id: subjectId, name: 'Новый источник', position: sources.length }).then((d) => setSources((list) => [...list, d.item]))
  const saveSource = (s) => catUpdate('bank_sources', { id: s.id, name: s.name }).catch((e) => alert(e.message))
  const delSource = async (s) => {
    if (!await confirmDelete({ title: 'Удалить источник?', target: `Источник «${s.name}»`, consequence: 'Источник и все собранные внутри него варианты будут удалены.' })) return
    try { await catDelete('bank_sources', s.id); setSources((list) => list.filter((x) => x.id !== s.id)); setVariants((list) => list.filter((v) => v.source_id !== s.id)) } catch (e) { alert(e.message) }
  }
  const patchSource = (id, v) => setSources((a) => a.map((x) => x.id === id ? { ...x, name: v } : x))
  const addVariant = (sourceId) => catCreate('bank_variants', { source_id: sourceId, subject_id: subjectId, name: 'Новый вариант', position: variants.length }).then((d) => setVariants((list) => [...list, d.item]))
  const saveVariant = (v) => catUpdate('bank_variants', { id: v.id, name: v.name }).catch((e) => alert(e.message))
  const delVariant = async (v) => {
    if (!await confirmDelete({ title: 'Удалить вариант?', target: `Вариант «${v.name}»`, consequence: 'Состав варианта и прогресс учеников по нему будут удалены. Исходные вопросы банка останутся.' })) return
    try { await catDelete('bank_variants', v.id); setVariants((list) => list.filter((x) => x.id !== v.id)) } catch (e) { alert(e.message) }
  }
  const patchVariant = (id, val) => setVariants((a) => a.map((x) => x.id === id ? { ...x, name: val } : x))

  return (
    <div className="admin-wrap admin__editor">
      <section className="admin__panel">
        <div className="admin__panel-head">
          <h2>Банк заданий</h2>
          <label className="admin__field" style={{ minWidth: 240 }}>Предмет
            <select className="admin__inp" value={subjectId} onChange={(e) => setSubjectId(Number(e.target.value))}>
              {subjects.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
          </label>
        </div>
      </section>

      {/* Темы (номера заданий) */}
      <section className="admin__panel">
        <div className="admin__panel-head">
          <h2>Номера заданий (темы)</h2>
          <button className="btn btn-orange btn-sm" onClick={addTopic}><Plus size={15} /> Тема</button>
        </div>
        <p className="admin__hint">№ задания в экзамене, название, часть и % ошибок (по статистике ФИПИ — ставишь вручную).</p>
        {topics.length === 0 && <div className="admin__empty">Нет тем</div>}
        <ReorderList entity="topics" items={topics} onChange={setTopics} renderRow={(t) => (
          <div className="admin__drow">
            <input className="admin__inp admin__inp--n" type="number" value={t.number ?? ''} onChange={(e) => patchTopic(t.id, 'number', e.target.value)} onBlur={() => saveTopic(t)} placeholder="№" />
            <input className="admin__inp" value={t.name} onChange={(e) => patchTopic(t.id, 'name', e.target.value)} onBlur={() => saveTopic(t)} />
            <select className="admin__inp admin__inp--part" value={t.part || 'first'} onChange={(e) => { patchTopic(t.id, 'part', e.target.value); saveTopic({ ...t, part: e.target.value }) }}>{PARTS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
            <input className="admin__inp admin__inp--n" type="number" value={t.error_rate ?? ''} onChange={(e) => patchTopic(t.id, 'error_rate', e.target.value)} onBlur={() => saveTopic(t)} placeholder="%" />
            <button className="admin__iconbtn" title="Сохранить" onClick={() => saveTopic(t)}><Save size={15} /></button>
            <button className="admin__iconbtn admin__iconbtn--danger" title="Удалить" onClick={() => delTopic(t)}><Trash2 size={15} /></button>
            <button className="admin__open" onClick={() => setTModal(t)}>Подтемы <ChevronRight size={15} /></button>
          </div>
        )} />
      </section>

      {/* Источники и варианты (для режима «Пробник») */}
      <section className="admin__panel">
        <div className="admin__panel-head">
          <h2>Источники и варианты</h2>
          <button className="btn btn-light btn-sm" onClick={addSource}><Plus size={15} /> Источник</button>
        </div>
        <p className="admin__hint">Источники (ФИПИ / Ященко / прошлые годы) → варианты для режима «Пробник». Кнопкой «Вопросы» собери вариант из существующих вопросов банка (тем/подтем) — они пойдут ученику по порядку.</p>
        {sources.length === 0 && <div className="admin__empty admin__empty--sm">Нет источников</div>}
        {sources.map((s) => (
          <div className="admin__month" key={s.id}>
            <div className="admin__month-head">
              <input className="admin__inp admin__inp--bold" value={s.name} onChange={(e) => patchSource(s.id, e.target.value)} onBlur={() => saveSource(s)} />
              <button className="admin__iconbtn" title="Сохранить" onClick={() => saveSource(s)}><Save size={15} /></button>
              <button className="admin__iconbtn admin__iconbtn--danger" title="Удалить" onClick={() => delSource(s)}><Trash2 size={15} /></button>
              <button className="btn btn-light btn-sm" onClick={() => addVariant(s.id)}><Plus size={14} /> Вариант</button>
            </div>
            {variants.filter((v) => v.source_id === s.id).map((v) => (
              <div className="admin__drow" key={v.id} style={{ margin: '6px 0' }}>
                <input className="admin__inp" value={v.name} onChange={(e) => patchVariant(v.id, e.target.value)} onBlur={() => saveVariant(v)} />
                <button className="admin__iconbtn" title="Сохранить" onClick={() => saveVariant(v)}><Save size={15} /></button>
                <button className="admin__iconbtn admin__iconbtn--danger" title="Удалить" onClick={() => delVariant(v)}><Trash2 size={15} /></button>
                <button className="admin__open" onClick={() => setVModal(v)}>Вопросы <ChevronRight size={15} /></button>
              </div>
            ))}
          </div>
        ))}
      </section>

      {tModal && <SubtopicsModal topic={tModal} onClose={() => setTModal(null)} />}
      {vModal && <VariantQuestionsModal variant={vModal} subjectId={subjectId} onClose={() => setVModal(null)} />}
    </div>
  )
}
