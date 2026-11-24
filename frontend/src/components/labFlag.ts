/**
 * Whether a lab value is above or below its normal range.
 *
 * Only ranges written in a way that leaves no doubt are judged; anything else gives no flag, and the person
 * reading the report decides. Understood: "70 - 140", "4,000 - 11,000", "70 to 140", "< 200", "up to 40",
 * "less than 5", "> 60", "above 40", "≥ 3.5", and one range for each sex on a line, as in
 * "Male : 14 - 17; Female : 12 - 15", when the patient's sex is known.
 */
export type Flag = 'high' | 'low' | null

const NUM = String.raw`\d[\d,]*(?:\.\d+)?`
const number = (text: string) => Number(text.replace(/,/g, ''))

const BETWEEN = new RegExp(String.raw`^(${NUM})\s*(?:-|–|—|to)\s*(${NUM})$`, 'i')
const UPPER = new RegExp(String.raw`^(<=|=<|≤|<|up\s*to|less\s+than|below|under)\s*(${NUM})$`, 'i')
const LOWER = new RegExp(String.raw`^(>=|=>|≥|>|more\s+than|greater\s+than|above|over)\s*(${NUM})$`, 'i')
// A range written after "Male" or "Female": the label, an optional colon or dash, then one of the plain forms.
const BY_SEX = new RegExp(String.raw`\b(females?|women|males?|men|f|m)\b\s*[:=–-]?\s*((?:(?:<=|=<|≤|<|>=|=>|≥|>)\s*)?${NUM}(?:\s*(?:-|–|—|to)\s*${NUM})?)`, 'gi')

/** One plain range. `undefined` when it is not written in a form that can be judged. */
function judge(value: number, range: string): Flag | undefined {
  const text = range.trim()
  const between = text.match(BETWEEN)
  if (between) return value < number(between[1]) ? 'low' : value > number(between[2]) ? 'high' : null
  const upper = text.match(UPPER)
  if (upper) {
    // "up to 40" and "≤ 40" allow 40 itself; "< 40" and "below 40" do not.
    const allowed = /^(<=|=<|≤|up)/i.test(upper[1])
    return (allowed ? value > number(upper[2]) : value >= number(upper[2])) ? 'high' : null
  }
  const lower = text.match(LOWER)
  if (lower) {
    const allowed = /^(>=|=>|≥)/.test(lower[1])
    return (allowed ? value < number(lower[2]) : value <= number(lower[2])) ? 'low' : null
  }
  return undefined
}

/** The part of a range like "Male : 14 - 17; Female : 12 - 15" that is for this sex; null when the range is not of that kind. */
function forSex(range: string, sex: string | null | undefined): string | null {
  const parts = [...range.matchAll(BY_SEX)]
  if (parts.length < 2) return null
  // Everything in the range has to be one of those labelled parts, or it is not safe to read.
  if (range.replace(BY_SEX, '').replace(/[\s;,/|&]|\band\b/gi, '') !== '') return null
  const wanted = sex === 'male' ? /^m/i : sex === 'female' ? /^(f|w)/i : null
  const mine = wanted ? parts.filter((p) => wanted.test(p[1])) : []
  return mine.length === 1 ? mine[0][2] : null
}

export function flagFor(value: string, range: string | null, sex?: string | null): Flag {
  const typed = value.trim()
  const n = number(typed)
  if (!range || typed === '' || Number.isNaN(n)) return null
  return judge(n, range) ?? judge(n, forSex(range, sex) ?? '') ?? null
}
