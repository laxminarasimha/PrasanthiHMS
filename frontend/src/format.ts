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

export const modeLabel = (mode: string) => (mode === 'ipd' ? 'Moved to IPD bill' : mode === 'deposit' ? 'From deposit' : (PAYMENT_MODES.find((m) => m.value === mode)?.label ?? mode))

/** "2027-05-31" -> "May 2027", the way expiry is printed on a strip. */
export const expiryLabel = (date: string) =>
  new Date(date.slice(0, 10) + 'T00:00:00').toLocaleDateString('en-IN', { month: 'short', year: 'numeric' })

/** Whole days from today until the given date (negative once it has passed). */
export const daysUntil = (date: string) => Math.round((new Date(date.slice(0, 10) + 'T00:00:00').getTime() - new Date(today() + 'T00:00:00').getTime()) / 86400000)

export const ADMISSION_STATUS: Record<string, string> = {
  admitted: 'Admitted',
  discharged: 'Discharged',
  lama: 'LAMA',
  dama: 'DAMA',
  absconded: 'Absconded',
  died: 'Died',
}

/** Current local date and time as "YYYY-MM-DDTHH:MM", the format date-time inputs use. */
export const nowLocal = () => {
  const d = new Date()
  return `${today()}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

/** How a bill's payment state reads in a list: an amount due, "On IPD bill" when it was moved there, or "Paid". */
export const settlement = (due: number, movedToIpd: number | null | undefined) =>
  due > 0 ? `${rupees(due)} due` : Number(movedToIpd) > 0 ? 'On IPD bill' : 'Paid'

/** Saves rows as a CSV file the pharmacist can open in Excel. */
export function downloadCsv(filename: string, headers: string[], rows: (string | number | null | undefined)[][]) {
  const cell = (value: string | number | null | undefined) => `"${String(value ?? '').replace(/"/g, '""')}"`
  const text = [headers, ...rows].map((row) => row.map(cell).join(',')).join('\r\n')
  const link = document.createElement('a')
  // The BOM makes Excel read the rupee sign and other non-English characters correctly.
  link.href = URL.createObjectURL(new Blob(['\ufeff' + text], { type: 'text/csv;charset=utf-8' }))
  link.download = filename
  link.click()
  URL.revokeObjectURL(link.href)
}

/** First day of the current month, as YYYY-MM-DD. */
export const monthStart = () => today().slice(0, 8) + '01'

/** For timestamps the server sends in universal time (bill and payment creation): shown in the viewer's own time. */
export const stampTime = (iso: string) => new Date(iso).toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' }).toUpperCase()
export const stampDate = (iso: string) => new Date(iso).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })

export const STAGE_LABEL = { booked: 'Booked', collected: 'Sample collected', reported: 'Reported', approved: 'Approved' } as const
export const STAGE_CLASS = { booked: 'status-booked', collected: 'status-arrived', reported: 'status-ongoing', approved: 'ok' } as const

export const TITLES = ['Mr', 'Mrs', 'Ms', 'Dr', 'Md', 'Smt', 'Sri', 'Kumari', 'Master', 'Baby']
export const AGE_UNITS = [
  { value: 'years', label: 'Years' },
  { value: 'months', label: 'Months' },
  { value: 'weeks', label: 'Weeks' },
  { value: 'days', label: 'Days' },
]

/** A patient's name with their title, as it is printed: "Smt Lakshmi Devi". */
export const fullName = (p: { name: string; title?: string | null }) => (p.title ? `${p.title} ${p.name}` : p.name)
