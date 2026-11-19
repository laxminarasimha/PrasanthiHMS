import { rupees } from '../format'

// ---- What the server sends for expenses ----

export type ExpenseStatus = 'draft' | 'submitted' | 'approved' | 'rejected' | 'paid'
type Who = { id: number; name: string } | null
export type Expense = {
  id: number
  expense_no: string
  status: ExpenseStatus
  spent_on: string
  paid_on: string | null
  category: string
  category_id: number | null
  department_id: number | null
  department: { id: number; name: string } | null
  paid_to: string | null
  bill_no: string | null
  description: string | null
  amount: number
  tax: number
  mode: string
  reference: string | null
  decision_note: string | null
  cancelled_at: string | null
  cancel_reason: string | null
  source_type: string | null
  posted: boolean
  has_receipt: boolean
  needs_large_approval: boolean
  creator?: Who
  canceller?: Who
  decider?: Who
  payer?: Who
  can: { edit: boolean; submit: boolean; decide: boolean; pay: boolean; cancel: boolean }
}
export type Heading = { id: number; name: string; approval_above: string | number | null; approver_limit: string | number | null; is_payroll: boolean; is_active?: boolean }
export type PaidOut = { expenses: number; expenses_cash: number; suppliers: number; suppliers_cash: number; cash: number; total: number; by_category: Record<string, number>; by_mode: Record<string, number> }
export type ExpenseList = {
  from: string
  to: string
  show: string
  rows: Expense[]
  paid_out: PaidOut
  counts: { waiting: number; to_pay: number; drafts: number }
  categories: string[]
  headings: Heading[]
  departments: { id: number; name: string }[]
  modes: string[]
  today: string
  can: { approve: boolean; approve_large: boolean; reverse: boolean; export: boolean; set_up: boolean }
}

export const EXPENSE_STATUS_LABEL: Record<ExpenseStatus, string> = { draft: 'Draft', submitted: 'Waiting for approval', approved: 'Approved, to pay', rejected: 'Refused', paid: 'Paid' }
export const EXPENSE_STATUS_CLASS: Record<ExpenseStatus, string> = { draft: 'status-booked', submitted: 'status-arrived', approved: 'status-ongoing', rejected: 'off', paid: 'ok' }

/**
 * What a heading asks before an expense of this amount can be paid, in a sentence, or null when it asks nothing.
 * Pass Infinity to describe the heading's rule as a whole.
 */
export function approvalHint(heading: Pick<Heading, 'name' | 'approval_above' | 'approver_limit'> | undefined, amount: number): string | null {
  if (!heading || heading.approval_above === null || heading.approval_above === '') return null
  const above = Number(heading.approval_above)
  const limit = heading.approver_limit === null || heading.approver_limit === '' ? null : Number(heading.approver_limit)
  if (amount === Infinity) {
    const first = above === 0 ? 'Every expense is approved before it is paid' : `Approved before paying above ${rupees(above)}`
    return limit === null ? first : `${first}; above ${rupees(limit)} it needs “Approve large expenses”`
  }
  if (!(amount > above)) return null
  const who = limit !== null && amount > limit ? ' by someone allowed to approve large expenses' : ''
  return above === 0 ? `Everything under ${heading.name} is approved${who} before it is paid. Send it for approval.` : `Under ${heading.name}, anything above ${rupees(above)} is approved${who} before it is paid. Send it for approval.`
}

/** 1 → "1st", 22 → "22nd", 11 → "11th". */
export const ordinal = (n: number) => `${n}${[11, 12, 13].includes(n % 100) ? 'th' : (['th', 'st', 'nd', 'rd'][n % 10] ?? 'th')}`
