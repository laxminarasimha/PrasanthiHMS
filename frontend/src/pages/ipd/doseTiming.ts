/** The ward's medicine round times, as "HH:MM", for once, twice, three and four times a day. */
export type Rounds = Record<'od' | 'bd' | 'tds' | 'qid', string[]>

export const ROUND_LABEL: Record<keyof Rounds, string> = { od: 'Once a day (OD)', bd: 'Twice a day (BD)', tds: 'Three times a day (TDS)', qid: 'Four times a day (QID)' }

const ROUND_OF: Record<string, keyof Rounds> = {
  od: 'od', 'once daily': 'od', 'once a day': 'od', daily: 'od',
  bd: 'bd', bid: 'bd', 'twice daily': 'bd', 'twice a day': 'bd',
  tds: 'tds', tid: 'tds', 'thrice daily': 'tds', 'three times a day': 'tds',
  qid: 'qid', qds: 'qid', 'four times a day': 'qid',
}

/**
 * What an order's timing means, only when it is written plainly:
 * OD, BD, TDS and QID follow the ward's rounds; "8 hourly", "every 6 hours" and "q8h" count hours from the last dose.
 * Anything else, such as "if fever" or "SOS", gives null: no due time is guessed for it.
 */
export const planOf = (frequency: string | null): { round: keyof Rounds } | { hours: number } | null => {
  const f = (frequency ?? '').toLowerCase().trim()
  if (!f || /sos|prn|stat|needed|\bif\b|\bwhen\b/.test(f)) return null
  if (ROUND_OF[f]) return { round: ROUND_OF[f] }
  const match = f.match(/^(\d+)\s*-?\s*(?:hourly|hrly)$/) ?? f.match(/^every\s+(\d+)\s*(?:h|hr|hrs|hours?)$/) ?? f.match(/^q\s*(\d+)\s*h$/)
  const hours = match ? Number(match[1]) : 0
  return hours >= 1 && hours <= 72 ? { hours } : null
}

/** Every round, as a moment in time, from the day before to two days after the given moment. */
const roundsAround = (moment: number, times: string[]) => {
  const all: number[] = []
  for (let day = -1; day <= 2; day++) {
    for (const t of times) {
      const [hours, minutes] = t.split(':').map(Number)
      const at = new Date(moment)
      at.setDate(at.getDate() + day)
      at.setHours(hours, minutes, 0, 0)
      all.push(at.getTime())
    }
  }
  return all.sort((a, b) => a - b)
}

/**
 * The round at which the next dose falls.
 * A dose counts for the round nearest to it, early or late, and the next dose is the round after that one.
 * Before any dose is given, it is the first round from when the order was written.
 */
export const nextRound = (times: string[], lastGiven: number | null, ordered: number) => {
  if (times.length === 0) return null
  if (lastGiven === null) return roundsAround(ordered, times).find((r) => r >= ordered) ?? null
  const rounds = roundsAround(lastGiven, times)
  const counted = rounds.reduce((best, r) => (Math.abs(r - lastGiven) < Math.abs(best - lastGiven) ? r : best))
  return rounds.find((r) => r > counted) ?? null
}

/** "08:00" -> "8 AM", "14:30" -> "2:30 PM", "00:00" -> "12 midnight" */
export const roundClock = (t: string) => {
  const [h, m] = t.split(':').map(Number)
  if (m === 0 && h === 0) return '12 midnight'
  if (m === 0 && h === 12) return '12 noon'
  return `${h % 12 || 12}${m ? `:${String(m).padStart(2, '0')}` : ''} ${h < 12 ? 'AM' : 'PM'}`
}
