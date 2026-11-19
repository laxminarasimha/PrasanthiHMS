import type { StaffBrief } from './shared'

// ---- What the server sends for pay, payroll and advances. Amounts arrive as text ("1234.50") and stay text: ----
// ---- nothing is added up in the browser except a preview that says so.                                     ----

export type PartKind = 'earning' | 'deduction' | 'employer'
export type Rule = { percent?: number | string; ceiling?: number | string; only_if_pay_up_to?: number | string; amount?: number | string; round?: 'rupee' | 'rupee_up'; slabs?: { up_to: number | string | null; amount: number | string }[] }
export type Part = { id: number; code: string; name: string; kind: PartKind; basis: string; is_statutory: boolean; is_enabled: boolean; reduces_with_absence: boolean; rule: Rule | null; sort: number }
export type SalaryLine = { component_id: number; code: string; name: string; kind: PartKind; basis: string; amount: string | null; percent: string | null; by_rule: boolean }
export type Salary = {
  id: number
  effective_from: string
  pay_basis: string
  overtime_rate: string | null
  note: string | null
  approved: boolean
  approved_at: string | null
  approved_by: string | null
  entered_by: string | null
  monthly: string
  lines: SalaryLine[]
  can_approve?: boolean
  can_withdraw?: boolean
}
export type SalaryRow = StaffBrief & { current: { effective_from: string; pay_basis: string; monthly: string } | null; next: { effective_from: string; monthly: string } | null; waiting: number }

export type RunStatus = 'draft' | 'reviewed' | 'approved' | 'finalised' | 'paid' | 'reversed'
export type RunCan = { create: boolean; edit: boolean; approve: boolean; finalise: boolean; reverse: boolean; export: boolean; see_pay: boolean; approve_this?: boolean }
export type Run = {
  id: number
  period: string
  period_name: string
  label: string | null
  status: RunStatus
  gross: string
  deductions: string
  net: string
  employer_cost: string
  replaces_run_id: number | null
  staff?: number
  unpaid?: { slips: number; net: string } | null
}
export type SlipRow = {
  id: number
  employee: StaffBrief | null
  slip_no: string | null
  payment_status: 'unpaid' | 'paid' | 'held'
  paid_on: string | null
  payment_mode: string | null
  notes: number
  gross: string | null
  deductions: string | null
  net: string | null
  unpaid_days: number | null
  one_offs: number | null
}
type Step = { name: string | null; at: string | null } | null
export type RunDetail = Run & {
  can: RunCan
  period_start: string
  period_end: string
  steps: { prepared: Step; reviewed: Step; approved: Step; finalised: Step; reversed: Step }
  reverse_reason: string | null
  worked_out_at: string | null
  left_out: { employee_id: number; name?: string; reason: string }[]
  departments: { name: string; staff: number; gross: string; deductions: string; net: string; employer_cost: string }[] | null
  payslips: SlipRow[]
}
export type SlipLine = { id: number; kind: PartKind; code: string; name: string; amount: string; explanation: string; one_off: boolean }
export type Slip = {
  id: number
  run_id: number
  run_status: RunStatus
  period: string
  period_name: string
  slip_no: string | null
  employee: (StaffBrief & { joined_on: string | null }) | null
  days: { in_month: number; employed: number; present: number; paid_leave: number; unpaid_leave: number; absent: number; holidays: number; weekly_offs: number; unpaid: number; paid_days: number; worked_minutes: number; overtime_minutes: number; notes: string[] } | null
  pay_basis: string | null
  lines: SlipLine[]
  gross: string
  deductions: string
  net: string
  employer_cost: string
  payment_status: 'unpaid' | 'paid' | 'held'
  paid_on: string | null
  payment_mode: string | null
  payment_reference: string | null
  /** Paid out on this payslip; less than the net when part was paid under a reversed payroll. */
  paid_amount: string | null
  already_paid: string
  reversed: boolean
  final: boolean
}

export type AdvanceStatus = 'requested' | 'approved' | 'rejected' | 'issued' | 'closed' | 'written_off'
export type Advance = { id: number; advance_no: string | null; kind: 'advance' | 'loan'; amount: string; reason: string | null; status: AdvanceStatus; issued_on: string | null; instalments: number; first_period: string | null; owed: string | null; employee: StaffBrief | null }
export type AdvanceDetail = Advance & {
  requested_by: string | null
  approved_by: string | null
  approved_at: string | null
  payment_mode: string | null
  payment_reference: string | null
  schedule: { id: number; period: string; period_name: string; amount: string; status: 'due' | 'deducted' | 'skipped' | 'waived' }[]
  ledger: { id: number; date: string; kind: string; amount: string; balance_after: string; reason: string | null; by: string | null }[]
  can_decide: boolean
  can_issue: boolean
  can_take_back: boolean
  can_let_go: boolean
}

// ---- Words and looks ----

export const RUN_LABEL: Record<RunStatus, string> = { draft: 'Draft', reviewed: 'Reviewed', approved: 'Approved', finalised: 'Finalised', paid: 'Paid', reversed: 'Reversed' }
export const RUN_CLASS: Record<RunStatus, string> = { draft: 'status-booked', reviewed: 'status-arrived', approved: 'status-ongoing', finalised: 'ok', paid: 'ok', reversed: 'off' }
export const PAYMENT_LABEL: Record<string, string> = { unpaid: 'Not paid yet', paid: 'Paid', held: 'Held back' }
export const PAYMENT_CLASS: Record<string, string> = { unpaid: 'status-arrived', paid: 'ok', held: 'off' }
export const ADVANCE_LABEL: Record<AdvanceStatus, string> = { requested: 'Waiting', approved: 'Approved, not given yet', rejected: 'Refused', issued: 'Being repaid', closed: 'Repaid', written_off: 'Written off' }
export const ADVANCE_CLASS: Record<AdvanceStatus, string> = { requested: 'status-arrived', approved: 'status-ongoing', rejected: 'off', issued: 'status-ongoing', closed: 'ok', written_off: 'status-booked' }
export const INSTALMENT_LABEL: Record<string, string> = { due: 'To be taken', deducted: 'Taken', skipped: 'Skipped', waived: 'Let go' }
export const LEDGER_LABEL: Record<string, string> = { issued: 'Given', payroll_deduction: 'Taken from salary', repayment: 'Paid back by hand', waiver: 'Waived', write_off: 'Written off', reversal: 'Put back: payroll reversed' }
export const KIND_LABEL: Record<PartKind, string> = { earning: 'Earning', deduction: 'Deduction', employer: 'Hospital’s share' }
export const BASIS_LABEL: Record<string, string> = {
  fixed: 'A fixed amount a month', percent_of_basic: 'A percentage of basic pay', percent_of_gross: 'A percentage of pay earned', per_day: 'An amount for each paid day', per_hour: 'An amount for each hour worked',
  slab_of_gross: 'An amount by slab of pay earned',
}
export const PAY_BASIS_LABEL: Record<string, string> = { monthly: 'By the month', daily: 'By the day', hourly: 'By the hour' }
export const PAY_MODES = [
  { value: 'bank', label: 'Bank transfer' },
  { value: 'cash', label: 'Cash' },
  { value: 'upi', label: 'UPI' },
  { value: 'cheque', label: 'Cheque' },
]
export const payModeLabel = (mode: string | null) => PAY_MODES.find((m) => m.value === mode)?.label ?? mode ?? ''

/** "1234.5" → "₹1,234.50". Always with paise, so a column of pay lines up. Nothing for nothing. */
export const inr = (amount: string | number | null | undefined) => {
  if (amount === null || amount === undefined || amount === '') return '—'
  const n = Number(amount)
  return (n < 0 ? '-₹' : '₹') + Math.abs(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

/** A part's rule in a few words, for the list. A statutory part with nothing entered says so plainly. */
export function ruleInWords(part: Pick<Part, 'basis' | 'rule' | 'is_statutory'>): string {
  const r = part.rule
  if (!r || Object.keys(r).length === 0) return part.is_statutory ? 'No rate entered yet' : 'Set on each person’s salary'
  const said: string[] = []
  if (r.percent !== undefined) said.push(`${Number(r.percent)}%`)
  if (r.amount !== undefined) said.push(inr(r.amount))
  if (r.slabs?.length) said.push(`${r.slabs.length} slab${r.slabs.length === 1 ? '' : 's'}`)
  if (r.ceiling !== undefined) said.push(`worked on no more than ${inr(r.ceiling)}`)
  if (r.only_if_pay_up_to !== undefined) said.push(`only for pay up to ${inr(r.only_if_pay_up_to)}`)
  if (r.round) said.push(r.round === 'rupee_up' ? 'rounded up to the rupee' : 'rounded to the rupee')
  return said.join(', ')
}

export type RunAction = 'work_out' | 'review' | 'approve' | 'send_back' | 'finalise' | 'pay' | 'reverse' | 'export'

/**
 * What this person may do next with a run, in the order the buttons are shown. The server decides again when a
 * button is pressed; this only keeps buttons that cannot work off the screen.
 */
export function runActions(run: { status: RunStatus; can: RunCan }): RunAction[] {
  const { status, can } = run
  const sendBack: RunAction[] = can.edit || can.approve ? ['send_back'] : []
  switch (status) {
    case 'draft':
      return can.edit ? ['work_out', 'review'] : []
    case 'reviewed':
      return [...(can.approve && can.approve_this !== false ? (['approve'] as RunAction[]) : []), ...sendBack]
    case 'approved':
      return [...(can.finalise ? (['finalise'] as RunAction[]) : []), ...sendBack]
    case 'finalised':
    case 'paid':
      return [...(can.approve && status === 'finalised' ? (['pay'] as RunAction[]) : []), ...(can.export && can.see_pay ? (['export'] as RunAction[]) : []), ...(can.reverse ? (['reverse'] as RunAction[]) : [])]
    default:
      return []
  }
}

/**
 * A preview, for the form only, of what a salary comes to for a full month: the fixed earnings plus those that are
 * a percentage of basic pay. The server works out the real figure when the salary is saved.
 */
export function monthlyPreview(parts: Part[], typed: Record<number, string>): number {
  const basic = parts.find((p) => p.code === 'BASIC' && p.basis === 'fixed')
  const basicAmount = basic ? Number(typed[basic.id] || 0) : 0
  return parts
    .filter((p) => p.kind === 'earning')
    .reduce((sum, p) => sum + (p.basis === 'fixed' ? Number(typed[p.id] || 0) : p.basis === 'percent_of_basic' ? Math.round(basicAmount * Number(typed[p.id] || 0)) / 100 : 0), 0)
}
