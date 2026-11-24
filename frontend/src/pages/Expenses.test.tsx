import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { Expenses } from './Expenses'
import type { Expense, ExpenseList } from './expenseRules'

const get = vi.fn()
const post = vi.fn()
vi.mock('../api', () => ({
  api: { get: (...args: unknown[]) => get(...args), post: (...args: unknown[]) => post(...args), put: vi.fn() },
  fieldErrors: (err: { response?: { data?: { errors?: Record<string, string[]> } } }) => Object.fromEntries(Object.entries(err.response?.data?.errors ?? {}).map(([k, v]) => [k, v[0]])),
}))
// The page sits under the front desk's strip of pages, which asks who is signed in.
vi.mock('../auth', () => ({ useAuth: () => ({ user: { can_front_desk: true, can_record_expenses: true } }) }))

const can = { edit: false, submit: false, decide: false, pay: false, cancel: false }
const row = (over: Partial<Expense> = {}): Expense => ({
  id: 5, expense_no: 'EX00005', status: 'submitted', spent_on: '2026-05-05', paid_on: null, category: 'Repairs and maintenance', category_id: 1, department_id: null, department: null, paid_to: 'Sample Electricals',
  bill_no: 'SE/221', description: null, amount: 12000, tax: 0, mode: 'cash', reference: null, decision_note: null, cancelled_at: null, cancel_reason: null, source_type: null, posted: false, has_receipt: false,
  needs_large_approval: false, creator: { id: 2, name: 'Cashier One' }, can, ...over,
})
const list = (over: Partial<ExpenseList> = {}): ExpenseList => ({
  from: '2026-05-05', to: '2026-05-05', show: 'all', rows: [], paid_out: { expenses: 0, expenses_cash: 0, suppliers: 0, suppliers_cash: 0, cash: 0, total: 0, by_category: {}, by_mode: {} },
  counts: { waiting: 0, to_pay: 0, drafts: 0 }, categories: ['Tea and refreshments'], headings: [], departments: [], modes: ['cash'], today: '2026-05-05',
  can: { approve: false, approve_large: false, reverse: false, export: false, set_up: false }, ...over,
})
const show = (address = '/expenses') =>
  render(
    <MemoryRouter initialEntries={[address]}>
      <Expenses />
    </MemoryRouter>,
  )

afterEach(() => {
  cleanup()
  get.mockReset()
  post.mockReset()
})

describe('the expenses list', () => {
  it('says nothing about "no expenses" until the answer is in, and then shows nought only as an answer', async () => {
    let arrive: (value: unknown) => void = () => undefined
    get.mockReturnValue(new Promise((resolve) => (arrive = resolve)))
    show()
    expect(screen.queryByText(/No expenses entered/)).toBeNull()
    expect(document.querySelectorAll('.tile').length).toBe(0)

    arrive({ data: list() })
    await waitFor(() => expect(screen.getByText(/No expenses entered for/)).toBeTruthy())
    expect(document.querySelectorAll('.tile').length).toBe(4)
  })

  it('offers Approve only to someone who may decide this one, and Pay only once it is approved', async () => {
    get.mockResolvedValue({ data: list({ show: 'waiting', counts: { waiting: 1, to_pay: 1, drafts: 0 }, rows: [row({ can: { ...can, decide: true } }), row({ id: 6, expense_no: 'EX00006', status: 'approved', can: { ...can, pay: true } })] }) })
    show('/expenses?show=waiting')
    await waitFor(() => expect(screen.getByText('EX00005')).toBeTruthy())
    expect(screen.getAllByText('Approve').length).toBe(1)
    expect(screen.getAllByText('Pay').length).toBe(1)

    post.mockResolvedValue({ data: {} })
    fireEvent.click(screen.getByText('Approve'))
    await waitFor(() => expect(post).toHaveBeenCalledWith('/expenses/5/decide', { approve: true }))
  })

  it('shows nothing to act on for an entry posted by payroll, and says where it came from', async () => {
    get.mockResolvedValue({ data: list({ rows: [row({ status: 'paid', paid_on: '2026-05-05', category: 'Salaries (from payroll)', posted: true, source_type: 'payroll_payment' })] }) })
    show()
    await waitFor(() => expect(screen.getByText('From Payroll')).toBeTruthy())
    expect(screen.queryByText('Edit')).toBeNull()
    expect(screen.queryByText('Cancel')).toBeNull()
    expect(screen.queryByText('Attach bill')).toBeNull()
  })

  it('warns, before saving, that an expense above its heading’s limit must be approved, and offers to send it', async () => {
    get.mockResolvedValue({ data: list({ headings: [{ id: 1, name: 'Repairs and maintenance', approval_above: '5000.00', approver_limit: null, is_payroll: false }] }) })
    show()
    await waitFor(() => expect(screen.getByText('+ Add expense')).toBeTruthy())
    fireEvent.click(screen.getByText('+ Add expense'))
    fireEvent.change(screen.getByLabelText(/Amount/), { target: { value: '12000' } })
    fireEvent.change(screen.getByLabelText(/What for/), { target: { value: 'Repairs and maintenance' } })
    expect(screen.getByText(/anything above ₹5,000 is approved before it is paid/)).toBeTruthy()
    fireEvent.click(screen.getByText('To be approved'))
    expect(screen.getByText('Send for approval')).toBeTruthy()
    expect(screen.getByText('Nothing comes off the drawer until it has been approved and paid.')).toBeTruthy()
  })
})
