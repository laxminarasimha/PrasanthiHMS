import { useAuth } from '../auth'

// ---- What the server sends ----

export type StaffBrief = { id: number; employee_no: string; name: string; designation: string | null; status: string; department: { id: number; name: string } | null }
export type Employee = StaffBrief & {
  phone: string | null
  email: string | null
  gender: string | null
  emergency_contact_name: string | null
  emergency_contact_phone: string | null
  department_id: number | null
  manager_id: number | null
  manager: { id: number; name: string } | null
  work_location: string | null
  joined_on: string | null
  confirmed_on: string | null
  separated_on: string | null
  separation_reason: string | null
  employment_type: string
  shift_id: number | null
  shift: string | null
  weekly_off: string[] | null
  holiday_calendar_id: number | null
  payment_mode: string | null
  user_id: number | null
  /** Whether the person signs in, and as what. What they may do there is for those who manage logins. */
  login?: { id: number; role: string; role_label: string; is_active: boolean; sign_in_with: 'password' | 'pin'; email: string | null; waiting_for_own_pin: boolean } | null
}
export type DayStatus = 'present' | 'half_day' | 'half_leave' | 'on_leave' | 'absent' | 'not_in' | 'later' | 'open' | 'holiday' | 'weekly_off' | 'not_employed' | ''
export type Day = {
  date: string
  status: DayStatus
  present: number
  leave: number
  absent: number
  worked_minutes: number
  late_minutes: number
  early_minutes?: number
  overtime_minutes?: number
  open: boolean
  leave_type: string | null
  holiday: string | null
  note: string | null
}
export type Spell = {
  id: number
  employee_id: number
  work_date: string
  check_in: string | null
  check_out: string | null
  state: 'open' | 'completed' | 'review'
  day_status: string | null
  worked_minutes: number | null
  late_minutes: number
  early_minutes: number
  overtime_minutes: number
  origin: string
  note: string | null
}
export type Totals = { present: number; leave: number; paid_leave: number; absent: number; holidays: number; weekly_offs: number; late_days: number; early_days: number; open: number; worked_minutes: number; overtime_minutes: number }
export type Correction = {
  id: number
  employee: StaffBrief | null
  work_date: string
  reason: string
  before: Record<string, string | null> | null
  after: Record<string, string | null>
  status: 'pending' | 'approved' | 'rejected' | 'cancelled' | 'applied'
  asked_by: string | null
  asked_at: string | null
  decided_by: string | null
  decided_at: string | null
  decision_note: string | null
  can_decide?: boolean
}
export type LeaveRequest = {
  id: number
  employee: StaffBrief | null
  type: { id: number; code: string; name: string; is_paid: boolean } | null
  from_date: string
  to_date: string
  from_part: string
  to_part: string
  days: number
  reason: string | null
  status: 'pending' | 'approved' | 'rejected' | 'cancelled'
  asked_by: string | null
  asked_at: string | null
  decided_by: string | null
  decided_at: string | null
  decision_note: string | null
  can_decide?: boolean
}
export type LeaveType = { id: number; code: string; name: string; is_paid: boolean; allow_half_day: boolean; left?: number }

// ---- Words and looks, the same on every screen ----

export const DAY_LABEL: Record<string, string> = {
  present: 'Present', half_day: 'Half day', half_leave: 'Half day leave', on_leave: 'On leave', absent: 'Absent', not_in: 'Not in yet', later: 'On duty later', open: 'In', holiday: 'Holiday',
  weekly_off: 'Weekly off', not_employed: '—', '': '—',
}
/** One letter for the month grid, where a box is all the room there is. */
export const DAY_LETTER: Record<string, string> = { present: 'P', half_day: '½', half_leave: '½L', on_leave: 'L', absent: 'A', not_in: '·', later: '·', open: 'In', holiday: 'H', weekly_off: 'O', not_employed: '', '': '' }
export const DAY_CLASS: Record<string, string> = {
  present: 'ok', half_day: 'status-arrived', half_leave: 'status-ongoing', on_leave: 'status-ongoing', absent: 'off', not_in: 'status-booked', later: 'status-booked', open: 'ok', holiday: 'status-booked', weekly_off: 'status-booked',
}
export const REQUEST_CLASS: Record<string, string> = { pending: 'status-arrived', approved: 'ok', applied: 'ok', rejected: 'off', cancelled: 'status-booked' }
export const REQUEST_LABEL: Record<string, string> = { pending: 'Waiting', approved: 'Approved', applied: 'Made directly', rejected: 'Refused', cancelled: 'Cancelled' }
export const TYPE_LABEL: Record<string, string> = { permanent: 'Permanent', contract: 'Contract', part_time: 'Part-time', consultant: 'Consultant', trainee: 'Trainee' }
export const STATUS_LABEL: Record<string, string> = { active: 'Active', on_notice: 'On notice', inactive: 'Left' }
export const WEEKDAYS: [string, string][] = [['mon', 'Mon'], ['tue', 'Tue'], ['wed', 'Wed'], ['thu', 'Thu'], ['fri', 'Fri'], ['sat', 'Sat'], ['sun', 'Sun']]
export const PART_LABEL: Record<string, string> = { full: 'Whole day', first_half: 'First half', second_half: 'Second half' }

/** 505 → "8h 25m". Nothing for nothing. */
export const hours = (minutes: number | null | undefined) => (minutes ? `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}m` : '')
/** 1 → "1 day", 2.5 → "2.5 days". */
export const days = (n: number) => `${n} ${n === 1 ? 'day' : 'days'}`
/** "2026-03" → "March 2026". */
export const monthName = (month: string) => new Date(`${month}-01T00:00:00`).toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })
export const thisMonth = () => new Date().toLocaleDateString('en-CA').slice(0, 7)
export const addMonths = (month: string, by: number) => {
  const d = new Date(`${month}-01T00:00:00`)
  d.setMonth(d.getMonth() + by)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

/**
 * A sheet of attendance, read into the lines the server expects. The first row names the columns; they are found by
 * what they are called, in any order: an employee number, a date, a time in and a time out. Returns the problem
 * instead when a needed column cannot be found.
 */
export function readAttendanceSheet(table: string[][]): { rows: { employee_no: string; name: string; date: string; check_in: string; check_out: string }[] } | { problem: string } {
  const [head, ...body] = table.filter((r) => r.some((c) => c.trim() !== ''))
  if (!head || body.length === 0) return { problem: 'The sheet has no lines under its heading row.' }
  const find = (...words: string[]) => head.findIndex((h) => words.some((w) => h.trim().toLowerCase().replace(/[^a-z]/g, '').includes(w)))
  const at = { no: find('employeeno', 'employeeid', 'empno', 'empid', 'staffno', 'staffid', 'code'), name: find('name'), date: find('date', 'day'), in: find('checkin', 'intime', 'timein', 'in'), out: find('checkout', 'outtime', 'timeout', 'out') }
  if (at.no < 0) return { problem: 'No column for the employee number was found. Name it "Employee no".' }
  if (at.date < 0) return { problem: 'No column for the date was found. Name it "Date".' }
  if (at.in < 0 && at.out < 0) return { problem: 'No column for the check-in or check-out time was found. Name them "Check in" and "Check out".' }
  const cell = (row: string[], i: number) => (i >= 0 ? (row[i] ?? '').trim() : '')
  return { rows: body.map((r) => ({ employee_no: cell(r, at.no), name: cell(r, at.name), date: cell(r, at.date), check_in: cell(r, at.in), check_out: cell(r, at.out) })) }
}

/** What the signed-in person may do in the workforce module. Read from their sign-in, which follows permission changes. */
export function useWorkforce() {
  const { user } = useAuth()
  const can = (permission: string) => Boolean(user?.abilities?.[permission])
  return {
    can,
    /** Whether they look after anyone's records but their own. */
    looksAfterStaff: ['wf:reports:view', 'wf:staff:view', 'wf:attendance:view', 'wf:leave:view', 'wf:payroll:view', 'wf:salary:view', 'wf:advances:view', 'wf:settings:admin'].some(can),
  }
}
