import { today } from './format'
import type { User } from './types'

/** Whether this person may enter a bill or money for an earlier day. Without it, everything they enter is today's. */
export const mayDateBack = (user: User | null | undefined) => Boolean(user?.abilities?.canEnterEarlierDays)

/** The day, here, of a moment the server sent. */
export const dayOf = (iso: string) => {
  const d = new Date(iso)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** What to send with a bill or a payment: nothing for today, the day itself for an earlier one. */
export const earlier = (day: string) => (day && day !== today() ? day : null)
