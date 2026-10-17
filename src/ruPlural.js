// Русские числительные: 1 блок, 2 блока, 5 блоков, 11 блоков, 21 блок.
export function pluralRu(value, one, few, many) {
  const n = Math.abs(Math.trunc(Number(value) || 0))
  const lastTwo = n % 100
  const last = n % 10
  if (lastTwo >= 11 && lastTwo <= 14) return many
  if (last === 1) return one
  if (last >= 2 && last <= 4) return few
  return many
}

export function countRu(value, one, few, many) {
  return `${value} ${pluralRu(value, one, few, many)}`
}
