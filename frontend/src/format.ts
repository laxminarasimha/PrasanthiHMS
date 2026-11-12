import type { AppointmentStatus } from './types'

export const rupees = (amount: number) =>
  '₹' + amount.toLocaleString('en-IN', { minimumFractionDigits: amount % 1 ? 2 : 0, maximumFractionDigits: 2 })

/** Today's date as YYYY-MM-DD in the browser's own time zone. */
export const today = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** "2026-11-12 14:05" -> "2:05 PM" */
export const clock = (dateTime: string) => {
  const [h, m] = dateTime.slice(11, 16).split(':').map(Number)
  return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`
}

export const longDate = (date: string) =>
  new Date(date.slice(0, 10) + 'T00:00:00').toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })

export const STATUS_LABELS: Record<AppointmentStatus, string> = {
  booked: 'Booked',
  arrived: 'Arrived',
  ongoing: 'On-going',
  reviewed: 'Reviewed',
  cancelled: 'Cancelled',
}

export const PAYMENT_MODES = [
  { value: 'cash', label: 'Cash' },
  { value: 'upi', label: 'UPI / Mobile' },
  { value: 'card', label: 'Card' },
  { value: 'cheque', label: 'Cheque' },
  { value: 'bank_transfer', label: 'Bank transfer' },
  { value: 'insurance', label: 'Insurance' },
]

export const modeLabel = (mode: string) => PAYMENT_MODES.find((m) => m.value === mode)?.label ?? mode

/** "2027-05-31" -> "May 2027", the way expiry is printed on a strip. */
export const expiryLabel = (date: string) =>
  new Date(date.slice(0, 10) + 'T00:00:00').toLocaleDateString('en-IN', { month: 'short', year: 'numeric' })

/** Whole days from today until the given date (negative once it has passed). */
export const daysUntil = (date: string) => Math.round((new Date(date.slice(0, 10) + 'T00:00:00').getTime() - new Date(today() + 'T00:00:00').getTime()) / 86400000)
