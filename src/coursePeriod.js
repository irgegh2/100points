// Временно: при обычном открытии курса выбираем первый период в программе.
// Вернуть выбор по серверной дате: true. Подробности: docs/course-period-default.md.
export const AUTO_SELECT_COURSE_PERIOD_BY_DATE = false

export const periodKind = (period) => period?.kind === 'block' ? 'block' : 'month'

const WORDS = {
  month: { one: 'месяц', gen: 'месяца', prep: 'месяце', plural: 'месяцы', many: 'месяцев' },
  block: { one: 'блок', gen: 'блока', prep: 'блоке', plural: 'блоки', many: 'блоков' },
}

export const periodWord = (period, form = 'one', capital = false) => {
  const value = WORDS[periodKind(period)][form] || WORDS[periodKind(period)].one
  return capital ? value.charAt(0).toLocaleUpperCase('ru-RU') + value.slice(1) : value
}

export const periodOptionLabel = (period) => `${periodWord(period, 'one', true)} · ${period?.name || 'Без названия'}`
