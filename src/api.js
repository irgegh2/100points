// Клиент к PHP API.
// Локально API крутится на http://localhost:8000 (php -S),
// на проде — на том же домене, путь /api.
const BASE = import.meta.env.DEV ? 'http://localhost:8000' : '/api'

async function call(path, { method = 'GET', body } = {}) {
  const res = await fetch(BASE + path, {
    method,
    credentials: 'include', // чтобы ходила сессионная кука
    headers: body ? { 'Content-Type': 'application/json' } : undefined,
    body: body ? JSON.stringify(body) : undefined,
  })
  let payload = {}
  try { payload = await res.json() } catch { /* пусто */ }
  // Единый формат ответа: { ok:true, data } | { ok:false, error }.
  if (!res.ok || payload.ok === false) {
    throw new Error(payload.error || 'Ошибка запроса')
  }
  // Возвращаем полезную нагрузку. ?? payload — на случай неперенесённого эндпоинта.
  return payload.data ?? payload
}

// ── Ученик ───────────────────────────────────────────────
export const login = (login, password) => call('/login.php', { method: 'POST', body: { login, password } })
export const me = () => call('/me.php')
export const logout = () => call('/logout.php', { method: 'POST' })

// ── Профиль ученика ──────────────────────────────────────
export const profileGet = () => call('/profile_get.php')
export const profileSave = (payload) => call('/profile_save.php', { method: 'POST', body: payload })

// Готовые аватарки из бандла (public/img/avatars/av-01..av-20.svg).
// Хранятся статикой на хостинге.
// ?v=3 — кэш-бастинг: имена файлов те же, контент сменился, а SVG кэшируется надолго;
// версия в URL даёт браузеру новый ключ кэша. При смене картинок — поднять версию (и в avatar_preset.php).
export const AVATAR_PRESETS = Array.from({ length: 20 }, (_, i) => {
  const id = `av-${String(i + 1).padStart(2, '0')}`
  return { id, url: `/img/avatars/${id}.svg?v=3` }
})
export const avatarPreset = (preset) => call('/avatar_preset.php', { method: 'POST', body: { preset } })

// Загрузка аватара — multipart (FormData), Content-Type ставит браузер сам.
export async function avatarUpload(file) {
  const fd = new FormData()
  fd.append('avatar', file)
  const res = await fetch(BASE + '/avatar_upload.php', {
    method: 'POST',
    credentials: 'include',
    body: fd,
  })
  let payload = {}
  try { payload = await res.json() } catch { /* пусто */ }
  if (!res.ok || payload.ok === false) {
    throw new Error(payload.error || 'Не удалось загрузить файл')
  }
  return payload.data ?? payload
}

// ── Каталог контента (курсы/уроки) ───────────────────────
// Витрина курсов с фильтрами (тип/предмет), программа курса, один урок.
export const coursesList = ({ type, subject } = {}) => {
  const q = new URLSearchParams()
  if (type && type !== 'Все') q.set('type', type)
  if (subject) q.set('subject', subject)
  const qs = q.toString()
  return call('/courses_list.php' + (qs ? `?${qs}` : ''))
}
export const courseGet = (id) => call(`/course_get.php?id=${id}`)
export const lessonGet = (id) => call(`/lesson_get.php?id=${id}`)
// Отметка «урок пройден» (для фильтра «Скрыть пройденное» в программе курса).
export const lessonProgressSet = (lessonId, done) => call('/lesson_progress_set.php', { method: 'POST', body: { lesson_id: lessonId, done } })
export const subscriptionGet = () => call('/subscription_get.php')
// Демонстрационная заглушка: платёжного провайдера нет, доступ открывает админ.
export const subscriptionRequest = (planId) => call('/subscription_request.php', { method: 'POST', body: { plan_id: planId } })

// Публичная заявка с лендинга (имя/класс/телефон). Без авторизации.
export const leadCreate = (payload) => call('/lead_create.php', { method: 'POST', body: payload })

// ── Домашние задания (Этап 4) ─────────────────────────────
export const homeworksList = () => call('/homeworks_list.php')
export const homeworkGet = (id) => call(`/homework_get.php?id=${id}`)
export const homeworkSubmit = (homeworkId, answers) => call('/homework_submit.php', { method: 'POST', body: { homework_id: homeworkId, answers } })
export const homeworkResult = (id) => call(`/homework_result.php?id=${id}`)
export async function answerFileUpload(questionId, file, assignmentId = null) {
  const fd = new FormData()
  fd.append('question_id', questionId)
  if (assignmentId) fd.append('assignment_id', assignmentId)
  fd.append('file', file)
  const res = await fetch(BASE + '/answer_file_upload.php', { method: 'POST', credentials: 'include', body: fd })
  let payload = {}; try { payload = await res.json() } catch { /* пусто */ }
  if (!res.ok || payload.ok === false) throw new Error(payload.error || 'Не удалось загрузить файл')
  return payload.data ?? payload
}
export const answerFileDelete = (uploadId) => call('/answer_file_delete.php', { method: 'POST', body: { upload_id: uploadId } })
export const answerFilePending = (questionId) => call(`/answer_file_pending.php?question_id=${questionId}`)

// ── Пробники (Этап 6) ─────────────────────────────────────
export const mockList = ({ subject, month } = {}) => {
  const q = new URLSearchParams()
  if (subject && subject !== 'Все предметы') q.set('subject', subject)
  if (month) q.set('month', month)
  const qs = q.toString()
  return call('/mock_list.php' + (qs ? `?${qs}` : ''))
}
export const mockGet = (id) => call(`/mock_get.php?id=${id}`)
export const mockSubmit = (mockId, answers, assignmentId = null) => call('/mock_submit.php', { method: 'POST', body: { mock_id: mockId, answers, ...(assignmentId ? { assignment_id: assignmentId } : {}) } })
export const mockResult = (id) => call(`/mock_result.php?id=${id}`)
export const proctoredSessionGet = (assignmentId) => call(`/proctored_session_get.php?assignment_id=${assignmentId}`)
export const proctoredStudentStart = (assignmentId) => call('/proctored_student_start.php', { method: 'POST', body: { assignment_id: assignmentId } })
export const proctoredStudentSync = (payload) => call('/proctored_student_sync.php', { method: 'POST', body: payload })
export const proctoredChatSend = (assignmentId, content) => call('/proctored_chat_send.php', { method: 'POST', body: { assignment_id: assignmentId, content } })
export const proctoredSignalSend = (assignmentId, type, payload) => call('/proctored_signal_send.php', { method: 'POST', body: { assignment_id: assignmentId, type, payload } })
export const proctoredSignalList = (assignmentId, afterId = 0) => call(`/proctored_signal_list.php?assignment_id=${assignmentId}&after_id=${afterId}`)

// Автосохранение черновика ответов (kind: 'homework'|'mock').
export const draftSave = (kind, refId, answers) => call('/draft_save.php', { method: 'POST', body: { kind, ref_id: refId, answers } })

// ── Банк заданий (Этап 7) ─────────────────────────────────
export const bankMeta = (subjectId) => call('/bank_meta.php' + (subjectId ? `?subject_id=${subjectId}` : ''))
export const bankSession = (payload) => call('/bank_session.php', { method: 'POST', body: payload })
export const bankCheck = (answers) => call('/bank_check.php', { method: 'POST', body: { answers } })
export const bankSolved = (subjectId) => call(`/bank_solved.php?subject_id=${subjectId}`)

// ── ИИ-тренажёр + ИИ-чаты (Этап 8) ────────────────────────
export const trainerMeta = (subjectId) => call('/trainer_meta.php' + (subjectId ? `?subject_id=${subjectId}` : ''))
export const trainerTaskNext = (subjectId, level) => call('/trainer_task_next.php', { method: 'POST', body: { subject_id: subjectId, level } })
export const trainerTaskAnswer = (taskId, answer) => call('/trainer_task_answer.php', { method: 'POST', body: { task_id: taskId, answer } })
export const trainerQaGenerate = (subjectId, level) => call('/trainer_qa_generate.php', { method: 'POST', body: { subject_id: subjectId, level } })
export const trainerQaCheck = (genId, value) => call('/trainer_qa_check.php', { method: 'POST', body: { gen_id: genId, value } })
export const aiChatCreate = (payload) => call('/ai_chat_create.php', { method: 'POST', body: payload })
export const aiChatSend = (chatId, content) => call('/ai_chat_send.php', { method: 'POST', body: { chat_id: chatId, content } })
export const aiChatList = () => call('/ai_chat_list.php')
export const aiChatGet = (id) => call(`/ai_chat_get.php?id=${id}`)
export const aiChatRename = (chatId, title) => call('/ai_chat_rename.php', { method: 'POST', body: { chat_id: chatId, title } })
export const aiChatDelete = (chatId) => call('/ai_chat_delete.php', { method: 'POST', body: { chat_id: chatId } })
// «Помощь по платформе» — ответ через базу знаний (RAG), не стрим.
export const aiPlatformAsk = (chatId, content) => call('/ai_platform_ask.php', { method: 'POST', body: { chat_id: chatId, content } })

// Стриминг ответа ИИ: onDelta(chunk, fullSoFar) вызывается по мере генерации. Возвращает полный текст.
export async function aiChatStream(chatId, content, onDelta) {
  const res = await fetch(BASE + '/ai_chat_stream.php', {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ chat_id: chatId, content }),
  })
  if (!res.ok) {
    let err = 'Поток недоступен'
    try { err = (await res.json()).error || err } catch { /* пусто */ }
    throw new Error(err)
  }
  const reader = res.body.getReader()
  const dec = new TextDecoder()
  let full = ''
  for (;;) {
    const { done, value } = await reader.read()
    if (done) break
    full += dec.decode(value, { stream: true })
    onDelta(full)
  }
  return full
}

// ── Геймификация (Этап 9) ─────────────────────────────────
export const walletGet = () => call('/wallet_get.php')
export const themeAccess = () => call('/theme_access.php')
export const achievementsList = () => call('/achievements_list.php')
export const dailyGet = () => call('/daily_get.php')
export const tourStatus = () => call('/tour_status.php')
export const tourProgress = (step) => call('/tour_progress.php', { method: 'POST', body: { step } })
export const tourComplete = () => call('/tour_complete.php', { method: 'POST' })

// ── Магазин (Этап 10) ─────────────────────────────────────
export const shopList = () => call('/shop_list.php')
export const shopBuy = (itemId, homeworkId = null) => call('/shop_buy.php', { method: 'POST', body: { item_id: itemId, ...(homeworkId ? { homework_id: homeworkId } : {}) } })
export const deadlineExtensionOptions = () => call('/deadline_extension_options.php')
export const purchasesList = () => call('/purchases_list.php')
export const adminPurchases = (status) => call('/admin_purchases.php' + (status ? `?status=${status}` : ''))
export const adminPurchaseUpdate = (purchaseId, status, note) => call('/admin_purchase_update.php', { method: 'POST', body: { purchase_id: purchaseId, status, note } })
export const adminWalletGrant = (userId, amount, reason) => call('/admin_wallet_grant.php', { method: 'POST', body: { user_id: userId, amount, reason } })

// ── Расписание (Этап 11) ──────────────────────────────────
export const scheduleGet = (week) => call('/schedule_get.php' + (week ? `?week=${week}` : ''))
export const todayGet = () => call('/today_get.php')

// ── Онлайн-наставник (Этап 12) ────────────────────────────
export const chatList = () => call('/chat_list.php')
export const chatThread = (subjectId) => call(`/chat_thread.php?subject_id=${subjectId}`)
export const messageSend = (subjectId, text, attachment) => call('/message_send.php', { method: 'POST', body: { subject_id: subjectId, text, attachment } })
// Загрузка вложения для чата наставника (multipart). Возвращает { url }.
export async function chatUpload(file) {
  const fd = new FormData()
  fd.append('file', file)
  const res = await fetch(BASE + '/chat_upload.php', { method: 'POST', credentials: 'include', body: fd })
  let payload = {}
  try { payload = await res.json() } catch { /* пусто */ }
  if (!res.ok || payload.ok === false) throw new Error(payload.error || 'Не удалось загрузить файл')
  return payload.data ?? payload
}
export const adminChats = (unread) => call('/admin_chats.php' + (unread ? '?unread=1' : ''))
export const adminChatThread = (chatId) => call(`/admin_chat_thread.php?chat_id=${chatId}`)
export const adminChatSend = (chatId, text) => call('/admin_chat_send.php', { method: 'POST', body: { chat_id: chatId, text } })

// ── Уведомления (Этап 13) ─────────────────────────────────
export const notifList = () => call('/notif_list.php')
export const notifRead = (id) => call('/notif_read.php', { method: 'POST', body: id === 'all' ? { all: true } : { id } })
export const adminNotify = (target, text, link) => call('/admin_notify.php', { method: 'POST', body: { target, text, link } })

// ── Настройки / тех.работы (Этап 16) ──────────────────────
export const maintenanceGet = () => call('/maintenance_get.php')

// ── Библиотека (Этап 15) ──────────────────────────────────
export const libraryGet = ({ subject_id, q } = {}) => {
  const p = new URLSearchParams()
  if (subject_id) p.set('subject_id', subject_id)
  if (q) p.set('q', q)
  const qs = p.toString()
  return call('/library_get.php' + (qs ? `?${qs}` : ''))
}
export async function libraryUpload(file, { subject_id, category, title } = {}) {
  const fd = new FormData()
  fd.append('file', file)
  if (subject_id) fd.append('subject_id', subject_id)
  if (category) fd.append('category', category)
  if (title) fd.append('title', title)
  const res = await fetch(BASE + '/admin_library_upload.php', { method: 'POST', credentials: 'include', body: fd })
  let payload = {}
  try { payload = await res.json() } catch { /* пусто */ }
  if (!res.ok || payload.ok === false) throw new Error(payload.error || 'Не удалось загрузить файл')
  return payload.data ?? payload
}
export const adminSettingsGet = () => call('/admin_settings_get.php')
export const adminSettingsSet = (payload) => call('/admin_settings_set.php', { method: 'POST', body: payload })

// ── Заявки (лиды лендинга, запросы тарифа, эскалации ИИ-помощи) ──
export const adminLeads = ({ status, kind } = {}) => {
  const p = new URLSearchParams()
  if (status) p.set('status', status)
  if (kind) p.set('kind', kind)
  const qs = p.toString()
  return call('/admin_leads.php' + (qs ? `?${qs}` : ''))
}
export const adminLeadUpdate = (payload) => call('/admin_lead_update.php', { method: 'POST', body: payload })

// Ссылка на выгрузку CSV (открывается напрямую — скачивание файла с сессионной кукой).
export const adminExportUrl = (type, params = {}) => {
  const p = new URLSearchParams({ type, ...params })
  return `${BASE}/admin_export.php?${p.toString()}`
}

// ── Админ ────────────────────────────────────────────────
export const adminLogin = (username, password) => call('/admin_login.php', { method: 'POST', body: { username, password } })
export const adminMe = () => call('/admin_me.php')
export const adminLogout = () => call('/admin_logout.php', { method: 'POST' })
export const usersList = () => call('/users_list.php')
export const userGet = (id) => call(`/users_get.php?id=${id}`)
export const userCreate = (payload) => call('/users_create.php', { method: 'POST', body: payload })
export const userUpdate = (payload) => call('/users_update.php', { method: 'POST', body: payload })
export const userDelete = (id) => call('/users_delete.php', { method: 'POST', body: { id } })
export const adminMockAssignments = (mockId) => call(`/admin_mock_assignments.php?mock_id=${mockId}`)
export const adminMockAssign = (payload) => call('/admin_mock_assign.php', { method: 'POST', body: payload })
export const adminMockUnassign = (assignmentId) => call('/admin_mock_unassign.php', { method: 'POST', body: { assignment_id: assignmentId } })
export const adminProctoredMonitor = (assignmentId) => call(`/admin_proctored_monitor.php?assignment_id=${assignmentId}`)
// Чтение ИИ-чатов ученика админом.
export const adminUserChats = (userId) => call(`/admin_user_chats.php?user_id=${userId}`)
export const adminChatMessages = (chatId) => call(`/admin_chat_messages.php?chat_id=${chatId}`)
export const adminAiChats = (q) => call('/admin_ai_chats.php' + (q ? `?q=${encodeURIComponent(q)}` : ''))

// ── Админ: CRUD каталога ──────────────────────────────────
// Единый эндпоинт admin_catalog.php: entity ∈ subjects|courses|months|blocks|lessons|materials|timecodes.
const adminCatalog = (entity, action, data = {}) =>
  call('/admin_catalog.php', { method: 'POST', body: { entity, action, data } })
export const catList   = (entity, parent = {}) => adminCatalog(entity, 'list', parent)
export const catCreate = (entity, data) => adminCatalog(entity, 'create', data)
export const catUpdate = (entity, data) => adminCatalog(entity, 'update', data)
export const catDelete = (entity, id) => adminCatalog(entity, 'delete', { id })
export const catReorder = (entity, ids) => adminCatalog(entity, 'reorder', { ids })
// Вопросы банка предмета + состав варианта (для модалки «Вопросы варианта»).
export const adminBankQuestions = (subjectId, variantId) =>
  call(`/admin_bank_questions.php?subject_id=${subjectId}${variantId ? `&variant_id=${variantId}` : ''}`)

// Админ: проверка работ (Этап 5)
export const reviewQueue = ({ subject, user_id } = {}) => {
  const q = new URLSearchParams()
  if (subject) q.set('subject', subject)
  if (user_id) q.set('user_id', user_id)
  const qs = q.toString()
  return call('/review_queue.php' + (qs ? `?${qs}` : ''))
}
export const reviewGet = (submissionId) => call(`/review_get.php?submission_id=${submissionId}`)
export const reviewGrade = (submissionId, grades) => call('/review_grade.php', { method: 'POST', body: { submission_id: submissionId, grades } })
export async function adminReviewImageUpload(file) {
  const fd = new FormData(); fd.append('image', file)
  const res = await fetch(BASE + '/admin_review_image_upload.php', { method: 'POST', credentials: 'include', body: fd })
  let payload = {}; try { payload = await res.json() } catch { /* пусто */ }
  if (!res.ok || payload.ok === false) throw new Error(payload.error || 'Не удалось загрузить изображение')
  return payload.data ?? payload
}

// Админ: доступ к контенту (подписки + зачисления)
export const adminAccessGet = (userId) => call(`/admin_access_get.php?user_id=${userId}`)
export const adminSubscriptionSet = (payload) => call('/admin_subscription_set.php', { method: 'POST', body: payload })
export const adminEnroll = (payload) => call('/admin_enroll.php', { method: 'POST', body: payload })

// Загрузка файла-материала к уроку (multipart). Возвращает созданную строку materials.
export async function materialUpload(lessonId, file, name) {
  const fd = new FormData()
  fd.append('file', file)
  fd.append('lesson_id', lessonId)
  if (name) fd.append('name', name)
  const res = await fetch(BASE + '/admin_material_upload.php', { method: 'POST', credentials: 'include', body: fd })
  let payload = {}
  try { payload = await res.json() } catch { /* пусто */ }
  if (!res.ok || payload.ok === false) throw new Error(payload.error || 'Не удалось загрузить файл')
  return payload.data ?? payload
}
