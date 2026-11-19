import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { WorkforcePayroll } from './Payroll'
import type { RunCan, RunDetail } from './pay'

const get = vi.fn()
const post = vi.fn()
vi.mock('../api', () => ({
  api: { get: (...args: unknown[]) => get(...args), post: (...args: unknown[]) => post(...args), delete: vi.fn() },
  fieldErrors: (err: { response?: { data?: { errors?: Record<string, string[]> } } }) => Object.fromEntries(Object.entries(err.response?.data?.errors ?? {}).map(([k, v]) => [k, v[0]])),
}))
vi.mock('../auth', () => ({ useAuth: () => ({ user: { abilities: { 'wf:payroll:view': true, 'wf:self:view': true } } }) }))

const can = (over: Partial<RunCan> = {}): RunCan => ({ create: false, edit: false, approve: false, finalise: false, reverse: false, export: false, see_pay: true, approve_this: true, ...over })
const person = (id: number, name: string) => ({ id, employee_no: `E${id}`, name, designation: 'Staff Nurse', status: 'active', department: { id: 1, name: 'Nursing' } })
const run = (over: Partial<RunDetail> = {}): RunDetail => ({
  id: 7, period: '2026-04', period_name: 'April 2026', label: null, status: 'draft', gross: '48000.00', deductions: '933.33', net: '47067.00', employer_cost: '47066.67', replaces_run_id: null,
  can: can(), period_start: '2026-04-01', period_end: '2026-04-30', steps: { prepared: { name: 'Hema HR', at: '2026-05-02 10:00' }, reviewed: null, approved: null, finalised: null, reversed: null },
  reverse_reason: null, worked_out_at: '2026-05-02 10:00', left_out: [], departments: null,
  payslips: [{ id: 31, employee: person(1, 'Asha Sample'), slip_no: null, payment_status: 'unpaid', paid_on: null, payment_mode: null, notes: 0, gross: '30000.00', deductions: '933.33', net: '29067.00', unpaid_days: 1, one_offs: 0 }],
  ...over,
})
const show = (address = '/workforce/payroll?run=7') =>
  render(
    <MemoryRouter initialEntries={[address]}>
      <WorkforcePayroll />
    </MemoryRouter>,
  )
const buttons = () => screen.getAllByRole('button').map((b) => b.textContent)

afterEach(() => {
  cleanup()
  get.mockReset()
  post.mockReset()
})

describe('a payroll run on screen', () => {
  it('shows no figure, and no "nobody is in this payroll", until the run arrives', async () => {
    let arrive: (value: unknown) => void = () => undefined
    get.mockReturnValue(new Promise((resolve) => (arrive = resolve)))
    show()

    expect(screen.getByText('Loading the payroll…')).toBeTruthy()
    expect(document.querySelectorAll('.tile').length).toBe(0)
    expect(screen.queryByText('Nobody is in this payroll.')).toBeNull()

    arrive({ data: run() })
    await waitFor(() => expect(screen.getByText('Payroll for April 2026')).toBeTruthy())
    expect(screen.getByText('₹47,067.00')).toBeTruthy()
    expect(screen.getByText('Asha Sample')).toBeTruthy()
  })

  it('says so when the run cannot be opened, and shows nothing that looks like a total', async () => {
    get.mockRejectedValue(new Error('offline'))
    show()
    await waitFor(() => expect(screen.getByText('This payroll could not be opened.')).toBeTruthy())
    expect(document.querySelectorAll('.tile').length).toBe(0)
  })

  it('gives the person who prepared a run only the steps that are theirs', async () => {
    get.mockResolvedValue({ data: run({ can: can({ create: true, edit: true }) }) })
    show()
    await waitFor(() => expect(screen.getByText('Mark as reviewed')).toBeTruthy())
    expect(buttons()).toContain('Work it out again')
    expect(buttons()).not.toContain('Approve')
    expect(buttons()).not.toContain('Finalise')
    expect(buttons()).not.toContain('Reverse')
  })

  it('tells whoever prepared it that someone else must approve, and offers no Approve button', async () => {
    get.mockResolvedValue({ data: run({ status: 'reviewed', can: can({ edit: true, approve: true, approve_this: false }) }) })
    show()
    await waitFor(() => expect(screen.getByText('You prepared or reviewed this payroll, so someone else must approve it.')).toBeTruthy())
    expect(buttons()).not.toContain('Approve')
    expect(buttons()).toContain('Send back to draft')
  })

  it('shows names but no pay to someone who may not see salaries, and says why', async () => {
    const hidden = run().payslips.map((s) => ({ ...s, gross: null, deductions: null, net: null, unpaid_days: null, one_offs: null }))
    get.mockResolvedValue({ data: run({ status: 'finalised', can: can({ see_pay: false }), payslips: hidden }) })
    show()
    await waitFor(() => expect(screen.getByText('Asha Sample')).toBeTruthy())
    expect(screen.queryByText('₹29,067.00')).toBeNull()
    expect(screen.getByText(/What each person is paid is shown only to those with “See salaries”/)).toBeTruthy()
    // The run's own total is theirs to see.
    expect(screen.getByText('₹47,067.00')).toBeTruthy()
  })

  it('names who was left out and why, and shows the server’s refusal in its own words', async () => {
    get.mockResolvedValue({ data: run({ can: can({ edit: true }), left_out: [{ employee_id: 2, name: 'Ravi Sample', reason: 'No salary has been set.' }] }) })
    post.mockRejectedValue({ response: { status: 422, data: { errors: { status: ['Attendance, leave, salary or an advance has changed since this was worked out, for Asha Sample. Send it back to draft and work it out again.'] } } } })
    show()
    await waitFor(() => expect(screen.getByText('Ravi Sample')).toBeTruthy())
    expect(screen.getByText(/No salary has been set\./)).toBeTruthy()

    fireEvent.click(screen.getByText('Mark as reviewed'))
    await waitFor(() => expect(screen.getByText(/has changed since this was worked out, for Asha Sample/)).toBeTruthy())
    expect(post).toHaveBeenCalledWith('/workforce/payroll/runs/7/step', { to: 'reviewed' })
  })

  it('asks for a reason before a reversal can be sent, and warns when salaries were already paid', async () => {
    const paid = run().payslips.map((s) => ({ ...s, payment_status: 'paid' as const, paid_on: '2026-05-04', payment_mode: 'bank', slip_no: 'PS-202604-0001' }))
    get.mockResolvedValue({ data: run({ status: 'paid', can: can({ reverse: true }), payslips: paid }) })
    show()
    await waitFor(() => expect(screen.getByText('Reverse')).toBeTruthy())
    fireEvent.click(screen.getByText('Reverse'))

    expect(screen.getByText(/already recorded as paid: the money has left/)).toBeTruthy()
    const go = screen.getByText('Reverse the payroll') as HTMLButtonElement
    expect(go.disabled).toBe(true)
    fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'The incentive was for May' } })
    expect(go.disabled).toBe(false)
    expect(post).not.toHaveBeenCalled()
  })
})

describe('the list of payroll runs', () => {
  it('shows an honest empty list only once the answer is in, and keeps "Start a payroll" from those who may not', async () => {
    get.mockResolvedValue({ data: { can: can(), runs: [] } })
    show('/workforce/payroll')
    await waitFor(() => expect(screen.getByText('No payroll has been run here yet.')).toBeTruthy())
    expect(screen.queryByText('+ Start a payroll')).toBeNull()
  })

  it('shows what is still to be paid on a finalised run', async () => {
    get.mockResolvedValue({ data: { can: can({ create: true }), runs: [{ ...run({ status: 'finalised' }), staff: 2, unpaid: { slips: 1, net: '18000.00' } }] } })
    show('/workforce/payroll')
    await waitFor(() => expect(screen.getByText('April 2026')).toBeTruthy())
    expect(screen.getByText('₹18,000.00 to 1 still to pay')).toBeTruthy()
    expect(screen.getByText('+ Start a payroll')).toBeTruthy()
  })
})
