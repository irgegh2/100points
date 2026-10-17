export function prettyAnswer(value) {
  return String(value ?? '')
    .replace(/-inf(?:inity)?/gi, '−∞')
    .replace(/\+inf(?:inity)?/gi, '+∞')
    .replace(/\binf(?:inity)?\b/gi, '∞')
}

export function canonicalTextAnswer(correct) {
  const value = Array.isArray(correct) ? correct[0] : correct
  return prettyAnswer(value)
}
