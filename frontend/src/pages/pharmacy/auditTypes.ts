/** A stock count as the lists show it. The figures are left out for someone counting blind. */
export type CountBrief = {
  id: number
  count_no: string
  title: string
  scope: 'all' | 'rack' | 'letters' | 'spot' | 'chosen' | 'reported'
  status: 'counting' | 'submitted' | 'approved' | 'cancelled'
  blind: boolean
  started_by: string | null
  started_at: string
  submitted_at: string | null
  approved_by: string | null
  approved_at: string | null
  lines: number
  counted: number
  short_lines?: number
  short_units?: number
  short_value?: number
  extra_lines?: number
  extra_units?: number
  extra_value?: number
}

export type CountRow = {
  id: number
  stock_batch_id: number
  medicine_id: number
  medicine: string
  batch_no: string
  expiry: string | null
  rack_no: string | null
  counted_units: number | null
  counted_by: string | null
  counted_at: string | null
  recount: boolean
  note: string | null
  /** What the records said: when the batch was counted, or right now if it has not been. Hidden from a blind counter. */
  on_record?: number
  difference?: number | null
  unit_cost?: number
  unit_mrp?: number
  value?: number | null
  reason?: string | null
  applied_units?: number | null
}

export type CountFull = CountBrief & {
  notes: string | null
  submitted_by: string | null
  sees_records: boolean
  can_approve: boolean
  reasons: Record<string, string>
  losses: string[]
  rows: CountRow[]
}

export const COUNT_STATUS = { counting: 'Being counted', submitted: 'Waiting for approval', approved: 'Approved', cancelled: 'Cancelled' } as const
export const COUNT_STATUS_CLASS = { counting: 'status-ongoing', submitted: 'status-arrived', approved: 'ok', cancelled: 'status-booked' } as const

/** Why the shelf and the records differ, in the order the choices are offered. */
export const REASONS: [string, string][] = [
  ['missing', 'Missing, not explained'],
  ['unbilled', 'Given without a bill (ward, OT, staff)'],
  ['damaged', 'Damaged or broken'],
  ['expired', 'Expired and removed'],
  ['mixup', 'Sold from another batch'],
  ['miscount', 'Earlier counting or entry mistake'],
  ['found', 'Found, more than recorded'],
]

/** "2026-11-18 14:05:00" -> "18 Nov, 2:05 PM" */
export const stamp = (at: string) => {
  const d = new Date(at.replace(' ', 'T'))
  return `${d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })}, ${d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' }).toUpperCase()}`
}
