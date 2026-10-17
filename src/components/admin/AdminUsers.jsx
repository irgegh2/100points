import { useEffect, useState } from 'react'
import { Plus, Trash2, X, MessageSquare, ChevronLeft, Sparkles, Download, BarChart3, Search } from 'lucide-react'
import { usersList, userCreate, userGet, userUpdate, userDelete, adminAccessGet, adminSubscriptionSet, adminEnroll, adminUserChats, adminChatMessages, adminWalletGrant, adminExportUrl, adminVkContactReview } from '../../api.js'
import PhoneInput from '../PhoneInput.jsx'
import { useDangerConfirm } from './DangerConfirm.jsx'
import AdminStudentCenter from './AdminStudentCenter.jsx'
import { readHash, writeHash } from '../../hashNav.js'

const EMPTY = { name: '', email: '', phone: '', password: '' }
const ROLES = [['student', 'Ученик'], ['mentor', 'Наставник'], ['teacher', 'Преподаватель']]
const VK_STATUS = {
  missing: ['Не передана', 'missing'],
  pending: ['VK на проверке', 'pending'],
  approved: ['VK подтверждена', 'approved'],
  rejected: ['VK отклонена', 'rejected'],
}

export default function AdminUsers() {
  const confirmDelete = useDangerConfirm()
  const [users, setUsers] = useState([])
  const [form, setForm] = useState(EMPTY)
  const [msg, setMsg] = useState(null)
  const [busy, setBusy] = useState(false)
  const [edit, setEdit] = useState(null)   // полный профиль редактируемого { user, profile }
  const [editMsg, setEditMsg] = useState(null)
  const [editBusy, setEditBusy] = useState(false)
  const [chats, setChats] = useState(null)     // { user, list } — чаты ученика
  const [openChat, setOpenChat] = useState(null) // { title, user_name, messages }
  const [query, setQuery] = useState('')
  const [centerUserId, setCenterUserId] = useState(() => Number(readHash()[1]) || null)

  const load = () => usersList().then((d) => setUsers(d.users || []))
  useEffect(() => { load() }, [])

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }))

  const create = async (e) => {
    e.preventDefault()
    setBusy(true); setMsg(null)
    try {
      const { user } = await userCreate(form)
      setForm(EMPTY); await load()
      setMsg({ type: 'ok', text: `Аккаунт создан: ${user.email || user.phone}` })
    } catch (err) { setMsg({ type: 'err', text: err.message }) }
    finally { setBusy(false) }
  }

  const openEdit = async (id) => {
    setEditMsg(null)
    try {
      const [d, access] = await Promise.all([userGet(id), adminAccessGet(id)])
      const sub = access.subscription
      setEdit({
        ...d, password: '', access,
        subForm: { plan_id: sub?.plan_id ?? '', status: sub?.status ?? 'active', ends_at: sub?.ends_at ?? '' },
      })
    } catch (err) { alert(err.message) }
  }

  // Подписка ученика — сохраняется СРАЗУ при изменении (как галочки курсов).
  const applySub = async (next) => {
    setEdit((s) => ({ ...s, subForm: next }))
    try {
      const r = await adminSubscriptionSet({ user_id: edit.user.id, plan_id: next.plan_id, status: next.plan_id === '' ? 'none' : next.status, ends_at: next.ends_at })
      setEdit((s) => ({ ...s, access: { ...s.access, subscription: r.subscription } }))
    } catch (err) { alert(err.message) }
  }
  // Зачисление на курс.
  const isEnrolled = (courseId) => (edit?.access?.enrollments || []).some((e) => e.course_id === courseId)
  const toggleEnroll = async (courseId, on) => {
    try {
      await adminEnroll({ user_id: edit.user.id, course_id: courseId, action: on ? 'grant' : 'revoke' })
      setEdit((s) => {
        const list = s.access.enrollments.filter((e) => e.course_id !== courseId)
        if (on) list.push({ course_id: courseId, source: 'manual', expires_at: null })
        return { ...s, access: { ...s.access, enrollments: list } }
      })
    } catch (err) { alert(err.message) }
  }

  const eu = (k) => (e) => setEdit((s) => ({ ...s, user: { ...s.user, [k]: e.target.value } }))
  const ep = (k) => (e) => setEdit((s) => ({ ...s, profile: { ...s.profile, [k]: e.target.value } }))
  const setGoal = (i, k, v) => setEdit((s) => {
    const goals = s.profile.goals.map((g, j) => j === i ? { ...g, [k]: v } : g)
    return { ...s, profile: { ...s.profile, goals } }
  })
  const addGoal = () => setEdit((s) => ({ ...s, profile: { ...s.profile, goals: [...s.profile.goals, { subject: '', target_score: '', hours_per_week: '' }] } }))
  const delGoal = async (i) => {
    const goal = edit.profile.goals[i]
    if (!await confirmDelete({ title: 'Удалить цель ученика?', target: `Цель «${goal?.subject || 'Без предмета'}»`, consequence: 'Цель исчезнет из профиля после сохранения карточки ученика.' })) return
    setEdit((s) => ({ ...s, profile: { ...s.profile, goals: s.profile.goals.filter((_, j) => j !== i) } }))
  }

  const saveEdit = async (e) => {
    e.preventDefault()
    setEditBusy(true); setEditMsg(null)
    try {
      const { user, profile } = edit
      await userUpdate({
        id: user.id, name: user.name, email: user.email, phone: user.phone,
        password: edit.password || '', role: user.role,
        birthdate: profile.birthdate, avatar: profile.avatar,
        goals: profile.goals.filter((g) => (g.subject || '').trim()),
      })
      setEdit(null); await load()
    } catch (err) { setEditMsg(err.message) }
    finally { setEditBusy(false) }
  }

  const remove = async (u) => {
    if (!await confirmDelete({ title: 'Удалить ученика?', target: `Аккаунт «${u.name || u.email || u.phone}»`, consequence: 'Будут удалены профиль, доступы, подписка, ответы, прогресс, покупки, кристаллы, чаты и вся история ученика.' })) return
    try { await userDelete(u.id); setEdit(null); await load() }
    catch (err) { alert(err.message) }
  }

  // ИИ-чаты ученика (чтение админом).
  const showChats = async (u) => {
    try { const d = await adminUserChats(u.id); setChats({ user: u, list: d.chats }) }
    catch (err) { alert(err.message) }
  }
  const showChat = async (id) => {
    try { const d = await adminChatMessages(id); setOpenChat({ ...d.chat, messages: d.messages }) }
    catch (err) { alert(err.message) }
  }
  const KIND_LABEL = { platform: 'Помощь по платформе', task: 'Разбор задачи', subject: 'По предмету', free: 'Свободный' }

  // Выдача кристаллов ученику.
  const grant = async (amount) => {
    if (!edit || !amount) return
    try {
      const r = await adminWalletGrant(edit.user.id, amount, 'Начислено администратором')
      setEdit((s) => ({ ...s, grantMsg: `Готово. Баланс: ${r.crystals} 💎`, grantAmt: '' }))
    } catch (e) { setEdit((s) => ({ ...s, grantMsg: e.message })) }
  }

  const reviewVk = async (status) => {
    if (!edit?.vk_contact?.url || edit.vkBusy) return
    setEdit((s) => ({ ...s, vkBusy: true, vkMsg: '' }))
    try {
      const result = await adminVkContactReview({
        user_id: edit.user.id,
        status,
        reason: status === 'rejected' ? (edit.vkRejectReason || '') : '',
      })
      setEdit((s) => ({ ...s, vkBusy: false, vkMsg: status === 'approved' ? 'Ссылка подтверждена' : 'Ссылка отклонена — ученик увидит запрос заново', vk_contact: { ...s.vk_contact, ...result } }))
      await load()
    } catch (err) {
      setEdit((s) => ({ ...s, vkBusy: false, vkMsg: err.message }))
    }
  }

  const filteredUsers = users.filter((u) => {
    const needle = query.trim().toLowerCase()
    return !needle || `${u.name || ''} ${u.email || ''} ${u.phone || ''} ${u.vk_url || ''}`.toLowerCase().includes(needle)
  })
  const openCenter = (id) => { setCenterUserId(id); writeHash(['users', id]) }
  const closeCenter = () => { setCenterUserId(null); writeHash(['users']) }
  const editFromCenter = async () => {
    const id = centerUserId
    closeCenter()
    await openEdit(id)
  }

  if (centerUserId) return <AdminStudentCenter userId={centerUserId} onBack={closeCenter} onEdit={editFromCenter} />

  return (
    <div className="admin__cols">
      <section className="admin__panel">
        <h2><Plus size={18} /> Новый аккаунт</h2>
        <p className="admin__hint">Регистрация только отсюда — ученик входит по этим данным.</p>
        <form className="admin__form" onSubmit={create}>
          <label>Имя <span>(необязательно)</span><input value={form.name} onChange={set('name')} placeholder="Имя ученика" /></label>
          <label>E-mail <input type="email" value={form.email} onChange={set('email')} placeholder="student@mail.ru" /></label>
          <label>Телефон <PhoneInput value={form.phone} onChange={(v) => setForm((f) => ({ ...f, phone: v }))} /></label>
          <label>Пароль <input type="text" value={form.password} onChange={set('password')} placeholder="Придумай пароль" required /></label>
          {msg && <div className={`admin__msg admin__msg--${msg.type}`}>{msg.text}</div>}
          <button className="btn btn-orange btn-lg" disabled={busy}>{busy ? 'Создаём…' : 'Создать аккаунт'}</button>
        </form>
      </section>

      <section className="admin__panel">
        <div className="admin__panel-head">
          <h2>Ученики <span className="admin__count">{users.length}</span></h2>
          <a className="btn btn-light btn-sm" href={adminExportUrl('users')}><Download size={15} /> Excel (CSV)</a>
        </div>
        <label className="admin__search"><Search size={16} /><input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Найти по имени, почте или телефону" />{query && <button type="button" onClick={() => setQuery('')} aria-label="Очистить"><X size={15} /></button>}</label>
        <div className="admin__list">
          {filteredUsers.length === 0 && <div className="admin__empty">{users.length ? 'Ничего не найдено' : 'Пока нет аккаунтов'}</div>}
          {filteredUsers.map((u) => (
            <div className="admin__user-row" key={u.id}>
              <div>
                <div className="admin__user-name">{u.name || 'Без имени'} {u.role !== 'student' && <span className="admin__rolebadge">{(ROLES.find((r) => r[0] === u.role) || [, u.role])[1]}</span>}</div>
                <div className="admin__user-contacts">{u.email || '—'} · {u.phone || '—'}</div>
                {u.role === 'student' && <span className={`admin__vkbadge admin__vkbadge--${(VK_STATUS[u.vk_status] || VK_STATUS.missing)[1]}`}>{(VK_STATUS[u.vk_status] || VK_STATUS.missing)[0]}</span>}
              </div>
              <div className="admin__user-rowbtns">
                <button className="admin__edit-btn admin__edit-btn--primary" onClick={() => openCenter(u.id)}><BarChart3 size={15} /> Аналитика</button>
                <button className="admin__edit-btn" onClick={() => showChats(u)} title="Чаты с ИИ"><MessageSquare size={15} /></button>
                <button className="admin__edit-btn" onClick={() => openEdit(u.id)}>Редактировать</button>
              </div>
            </div>
          ))}
        </div>
      </section>

      {chats && (
        <div className="admin__modal" onClick={() => { setChats(null); setOpenChat(null) }}>
          <div className="admin__modal-box" onClick={(e) => e.stopPropagation()}>
            <div className="admin__modal-head">
              <h3>{openChat ? openChat.title : `Чаты ИИ · ${chats.user.name || chats.user.email || 'ученик'}`}</h3>
              <button className="admin__iconbtn" onClick={() => { setChats(null); setOpenChat(null) }}><X size={18} /></button>
            </div>
            {!openChat ? (
              <div className="admin__list">
                {chats.list.length === 0 && <div className="admin__empty">У ученика нет ИИ-чатов</div>}
                {chats.list.map((c) => (
                  <button className="admin__user-row" key={c.id} onClick={() => showChat(c.id)} style={{ width: '100%', textAlign: 'left' }}>
                    <div>
                      <div className="admin__user-name">{c.title}</div>
                      <div className="admin__user-contacts">{KIND_LABEL[c.kind] || c.kind}{c.created_at ? ' · ' + c.created_at.slice(0, 10) : ''}</div>
                    </div>
                    <MessageSquare size={16} />
                  </button>
                ))}
              </div>
            ) : (
              <>
                <button className="lesson__back" onClick={() => setOpenChat(null)}><ChevronLeft size={16} /> Все чаты</button>
                <div className="admin__chatlog">
                  {openChat.messages.map((m, i) => (
                    <div className={`aimsg aimsg--${m.role === 'user' ? 'user' : 'assistant'}`} key={i}>
                      {m.role !== 'user' && <span className="aimsg__ico"><Sparkles size={14} /></span>}
                      <div className="aimsg__bubble">{m.content}</div>
                    </div>
                  ))}
                </div>
              </>
            )}
          </div>
        </div>
      )}

      {edit && (
        <div className="admin__modal" onClick={() => setEdit(null)}>
          <div className="admin__modal-box" onClick={(e) => e.stopPropagation()}>
            <div className="admin__modal-head">
              <h3>Профиль ученика</h3>
              <button className="admin__iconbtn" onClick={() => setEdit(null)}><X size={18} /></button>
            </div>
            <form className="admin__form" onSubmit={saveEdit}>
              <label>Имя <input value={edit.user.name || ''} onChange={eu('name')} /></label>
              <div className="admin__row2">
                <label>E-mail <input type="email" value={edit.user.email || ''} onChange={eu('email')} /></label>
                <label>Роль <select value={edit.user.role} onChange={eu('role')}>{ROLES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></label>
              </div>
              <label>Телефон <PhoneInput value={edit.user.phone || ''} onChange={(v) => setEdit((s) => ({ ...s, user: { ...s.user, phone: v } }))} /></label>
              <div className="admin__row2">
                <label>Новый пароль <span>(пусто — не менять)</span><input type="text" value={edit.password} onChange={(e) => setEdit((s) => ({ ...s, password: e.target.value }))} /></label>
                <label>Дата рождения <input type="date" value={edit.profile.birthdate || ''} onChange={ep('birthdate')} /></label>
              </div>
              <label>Аватар <span>(путь/URL)</span><input value={edit.profile.avatar || ''} onChange={ep('avatar')} placeholder="/img/avatars/av-01.svg" /></label>

              {edit.user.role === 'student' && edit.vk_contact && (
                <div className="admin__access admin__vkcontact">
                  <div className="admin__vkcontact-head">
                    <div>
                      <div className="admin__access-head">Связь с персональным менеджером</div>
                      <p className="admin__hint">Ученик обязан передать настоящую ссылку VK. Напоминание исчезнет только после подтверждения.</p>
                    </div>
                    <span className={`admin__vkbadge admin__vkbadge--${(VK_STATUS[edit.vk_contact.status] || VK_STATUS.missing)[1]}`}>{(VK_STATUS[edit.vk_contact.status] || VK_STATUS.missing)[0]}</span>
                  </div>
                  {edit.vk_contact.url ? (
                    <>
                      <a className="admin__vklink" href={edit.vk_contact.url} target="_blank" rel="noreferrer">{edit.vk_contact.url}</a>
                      {edit.vk_contact.rejection_reason && <div className="admin__vkreason">Причина: {edit.vk_contact.rejection_reason}</div>}
                      <label>Причина отклонения <span>(увидит ученик)</span>
                        <input value={edit.vkRejectReason || ''} onChange={(e) => setEdit((s) => ({ ...s, vkRejectReason: e.target.value }))} placeholder="Например: ссылка ведёт не на профиль" />
                      </label>
                      <div className="admin__vkactions">
                        {edit.vk_contact.status !== 'approved' && <button type="button" className="btn btn-orange" disabled={edit.vkBusy} onClick={() => reviewVk('approved')}>Подтвердить ссылку</button>}
                        <button type="button" className="btn admin__delbtn" disabled={edit.vkBusy} onClick={() => reviewVk('rejected')}>{edit.vk_contact.status === 'approved' ? 'Отклонить и запросить заново' : 'Отклонить'}</button>
                      </div>
                      {edit.vkMsg && <div className="admin__hint">{edit.vkMsg}</div>}
                    </>
                  ) : <div className="admin__empty admin__empty--sm">Ученик ещё не отправил ссылку.</div>}
                </div>
              )}

              <div className="admin__goals">
                <div className="admin__goals-head"><b>Цели по предметам</b><button type="button" className="admin__addgoal" onClick={addGoal}><Plus size={14} /> добавить</button></div>
                {edit.profile.goals.length === 0 && <div className="admin__empty admin__empty--sm">Целей нет</div>}
                {edit.profile.goals.map((g, i) => (
                  <div className="admin__goal" key={i}>
                    <input className="admin__inp" value={g.subject || ''} onChange={(e) => setGoal(i, 'subject', e.target.value)} placeholder="Предмет" />
                    <input className="admin__inp admin__inp--sm" type="number" value={g.target_score ?? ''} onChange={(e) => setGoal(i, 'target_score', e.target.value)} placeholder="балл" />
                    <input className="admin__inp admin__inp--sm" type="number" value={g.hours_per_week ?? ''} onChange={(e) => setGoal(i, 'hours_per_week', e.target.value)} placeholder="ч/нед" />
                    <button type="button" className="admin__iconbtn admin__iconbtn--danger" onClick={() => delGoal(i)}><Trash2 size={15} /></button>
                  </div>
                ))}
              </div>

              {/* доступ к контенту: подписка + зачисления */}
              {edit.access && (
                <div className="admin__access">
                  <div className="admin__access-head">Доступ к контенту</div>
                  <p className="admin__hint" style={{ margin: '0 0 2px' }}>Тариф (для раздела «Подписка»). Курсы он НЕ открывает — доступ выдаётся галочками ниже. Сохраняется сразу.</p>
                  <div className="admin__row3">
                    <label>Тариф
                      <select value={edit.subForm.plan_id} onChange={(e) => applySub({ ...edit.subForm, plan_id: e.target.value, status: edit.subForm.status || 'active' })}>
                        <option value="">— нет подписки —</option>
                        {edit.access.plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                      </select>
                    </label>
                    <label>Статус
                      <select value={edit.subForm.status} onChange={(e) => applySub({ ...edit.subForm, status: e.target.value })} disabled={edit.subForm.plan_id === ''}>
                        <option value="active">активна</option><option value="expired">истекла</option>
                      </select>
                    </label>
                    <label>Активна до <input type="date" value={edit.subForm.ends_at || ''} onChange={(e) => applySub({ ...edit.subForm, ends_at: e.target.value })} disabled={edit.subForm.plan_id === ''} /></label>
                  </div>
                  <div className="admin__access-head" style={{ marginTop: '6px' }}>Доступ к курсам</div>
                  <p className="admin__hint" style={{ margin: '0 0 2px' }}>Отметь курсы, которые открыты этому ученику. Применяется сразу.</p>
                  <div className="admin__enroll">
                    {edit.access.courses.map((c) => (
                      <label className="admin__enroll-row" key={c.id}>
                        <input type="checkbox" checked={isEnrolled(c.id)} onChange={(e) => toggleEnroll(c.id, e.target.checked)} />
                        <span>{c.title}</span>
                      </label>
                    ))}
                  </div>
                </div>
              )}

              {/* Кристаллы */}
              <div className="admin__access">
                <div className="admin__access-head">Кристаллы</div>
                <p className="admin__hint" style={{ margin: '0 0 4px' }}>Начислить (или списать — отрицательным числом) кристаллы этому ученику.</p>
                <div className="admin__grant">
                  <input className="admin__inp" type="number" placeholder="напр. 10000" value={edit.grantAmt || ''} onChange={(e) => setEdit((s) => ({ ...s, grantAmt: e.target.value }))} />
                  <button type="button" className="btn btn-orange" onClick={() => grant(parseInt(edit.grantAmt, 10))}>Выдать</button>
                </div>
                {edit.grantMsg && <div className="admin__msg admin__msg--ok" style={{ marginTop: 6 }}>{edit.grantMsg}</div>}
              </div>

              {editMsg && <div className="admin__msg admin__msg--err">{editMsg}</div>}
              <div className="admin__edit-btns">
                <button className="btn btn-orange" disabled={editBusy}>{editBusy ? 'Сохраняем…' : 'Сохранить'}</button>
                <button type="button" className="btn btn-light" onClick={() => setEdit(null)}>Отмена</button>
                <button type="button" className="btn admin__delbtn" onClick={() => remove(edit.user)}><Trash2 size={15} /> Удалить</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
