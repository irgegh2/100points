const imageUrls = (questions = []) => Array.from(new Set(
  questions.flatMap((question) => Array.isArray(question?.images) ? question.images : [])
    .map((url) => String(url || '').trim())
    .filter(Boolean),
))

const startImagePreload = (src) => {
  let image = new Image()
  let settled = false
  let resolvePromise
  const promise = new Promise((resolve) => { resolvePromise = resolve })
  const finish = () => {
    if (settled) return
    settled = true
    if (image) { image.onload = null; image.onerror = null }
    resolvePromise()
  }
  image.decoding = 'async'
  image.onload = async () => {
    try { await image?.decode?.() } catch { /* файл уже загружен, decode может не поддерживаться */ }
    finish()
  }
  image.onerror = finish
  image.src = src
  if (image.complete) {
    Promise.resolve(image.decode?.()).catch(() => {}).finally(finish)
  }
  return {
    promise,
    cancel: () => {
      if (settled) return
      if (image) { image.onload = null; image.onerror = null; image.src = '' }
      image = null
      finish()
    },
  }
}

// Загружаем и декодируем иллюстрации до показа задания. После завершения ссылки на
// временные Image освобождаются, а сами файлы остаются в браузерном HTTP-кэше.
export async function preloadQuestionImages(questions, timeoutMs = 10000) {
  const urls = imageUrls(questions)
  if (!urls.length || typeof Image === 'undefined') return
  const jobs = urls.map(startImagePreload)
  let timedOut = false
  let timer
  await Promise.race([
    Promise.all(jobs.map((job) => job.promise)),
    new Promise((resolve) => { timer = setTimeout(() => { timedOut = true; resolve() }, timeoutMs) }),
  ])
  clearTimeout(timer)
  if (timedOut) jobs.forEach((job) => job.cancel())
}

// Перед открытием ждём только иллюстрации первого вопроса. Остальные прогреваем
// без отдельного экрана, пока ученик уже читает и решает первый вопрос.
export async function preloadOpeningQuestionImages(questions = []) {
  if (!questions.length) return
  await preloadQuestionImages([questions[0]])
  if (questions.length > 1) preloadQuestionImages(questions.slice(1), 30000).catch(() => {})
}
