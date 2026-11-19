import type { StaffBrief } from './shared'

export type RosterShift = { id: number; name: string; starts_at: string; ends_at: string; night: boolean; is_active: boolean }
export type Duty = { shift_id: number | null; off: boolean; rostered: boolean }
export type RosterData = {
  from: string
  to: string
  dates: string[]
  shifts: RosterShift[]
  can_edit: boolean
  rows: (StaffBrief & { usual_shift_id: number | null; can_edit: boolean; days: Record<string, Duty | null> })[]
  cover: Record<string, Record<string, number>>
}

/** "20:00" → "8 PM", "10:30" → "10:30 AM", "00:00" → "12 AM". */
export function shortTime(hhmm: string): string {
  const [h, m] = hhmm.split(':').map(Number)
  const hour = h % 12 === 0 ? 12 : h % 12
  return `${hour}${m ? `:${String(m).padStart(2, '0')}` : ''} ${h < 12 ? 'AM' : 'PM'}`
}

/** A day's duty in a few words: "Day off", "Night duty 8 PM–8 AM", or "No shift set". */
export function dutyLabel(duty: Pick<Duty, 'off'>, shift: Pick<RosterShift, 'name' | 'starts_at' | 'ends_at'> | undefined): string {
  if (duty.off) return 'Day off'
  return shift ? `${shift.name} ${shortTime(shift.starts_at)}–${shortTime(shift.ends_at)}` : 'No shift set'
}

/** The Monday of the week a date falls in, as YYYY-MM-DD. */
export function weekStart(date: string): string {
  const d = new Date(`${date}T00:00:00`)
  d.setDate(d.getDate() - ((d.getDay() + 6) % 7))
  return d.toLocaleDateString('en-CA')
}
