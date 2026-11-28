import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { Consultants, StatementBody, type ConsultantRow, type Statement } from './Consultants'

const get = vi.fn()
vi.mock('../api', () => ({
  api: { get: (...args: unknown[]) => get(...args), post: vi.fn(), put: vi.fn() },
  fieldErrors: () => ({}),
}))
// The owner's login: works the front desk and IPD, and looks after consultants' fees.
vi.mock('../auth', () => ({ useAuth: () => ({ user: { can_front_desk: true, can_ipd: true, can_consult: true, can_run_lab: false, can_pay_consultants: true, abilities: { canPayConsultants: true } } }) }))

const row: ConsultantRow = {
  doctor_id: 7, doctor: 'Dr Sample Own', department: 'General Medicine', share: 1995, visits: 1, first_visits: 1, visit_fees: 1000, earned: 2995, paid: 0, due: 2995,
  shares: [
    { key: 'front_desk', counter: 'Front desk', collected: 1750, percent: 50, share: 875 },
    { key: 'lab', counter: 'Lab', collected: 200, percent: 10, share: 20 },
    { key: 'ipd', counter: 'IPD', collected: 0, percent: 20, share: 0 },
  ],
}
const statement: Statement = {
  ...row, month: '2026-11', month_name: 'November 2026', phone: null, payments: [],
  visit_list: [{ id: 3, visited_on: '2026-11-22', kind: 'first', fee: 1000, note: null, patient: { id: 41, name: 'Sample Ward Patient', uhid: '2026110041' }, admission_id: 12, ipd_no: 'IP00012' }],
}
const bill = (id: number, over: Record<string, unknown> = {}) => ({ id, bill_no: `B000${id}`, on: '2026-11-24', kind: 'appointment', cancelled: false, ...over })
const line = (id: number, amount: number, over: Record<string, unknown> = {}) => ({
  id, on: '2026-11-24 11:00', entered_on: null, type: amount < 0 ? 'refund' : 'payment', mode: 'cash', reference: null, amount, bill: bill(55), order: null, admission: null,
  patient: { id: 31, name: 'Sample Patient', uhid: '2026110031' }, cancelled_lab_bill: false, ...over,
})
const frontDesk = {
  doctor_id: 7, month: '2026-11', month_name: 'November 2026', counter: 'front_desk', label: 'Front desk', collected: 1750,
  by_mode: { cash: 1150, card: 0, upi: 0, cheque: 0, bank_transfer: 0, insurance: 0, deposit: 600 },
  lines: [
    line(101, 750, { on: '2026-11-10 11:00', entered_on: '2026-11-24', bill: bill(54, { on: '2026-11-10' }) }),
    line(102, 500),
    line(103, -100, { reference: 'Charged twice' }),
    line(104, 600, { mode: 'deposit', bill: bill(56, { on: '2026-11-20' }), patient: { id: 32, name: 'Sample Saver', uhid: '2026110032' } }),
    line(105, 200, { bill: bill(57, { kind: 'lab', cancelled: true }), cancelled_lab_bill: true }),
    line(106, -200, { bill: bill(57, { kind: 'lab', cancelled: true }), cancelled_lab_bill: true, reference: 'Bill cancelled' }),
  ],
}
const lab = { ...frontDesk, counter: 'lab', label: 'Lab', collected: 200, by_mode: { cash: 0, card: 0, upi: 200 }, lines: [line(201, 200, { mode: 'upi', bill: bill(58, { kind: 'lab' }), order: { id: 9, order_no: 'L00009' } })] }
const ipd = { ...frontDesk, counter: 'ipd', label: 'IPD', collected: 0, by_mode: { cash: 0, card: 0, upi: 0 }, lines: [] }

function Where() {
  const at = useLocation()
  return <output data-testid="where">{at.pathname + at.search + at.hash}</output>
}
function Back() {
  const navigate = useNavigate()
  return (
    <button type="button" onClick={() => navigate(-1)}>
      Go back
    </button>
  )
}
const show = (address = '/workforce/consultants?month=2026-11&doctor=7') =>
  render(
    <MemoryRouter initialEntries={[address]}>
      <Routes>
        <Route
          path="/workforce/consultants"
          element={
            <>
              <Consultants />
              <Where />
            </>
          }
        />
        <Route
          path="*"
          element={
            <>
              <Where />
              <Back />
            </>
          }
        />
      </Routes>
    </MemoryRouter>,
  )
const where = () => screen.getByTestId('where').textContent
const popup = () => within(screen.getByRole('dialog'))
const listsAsked = () => get.mock.calls.filter(([url]) => String(url).endsWith('/collected'))

beforeEach(() => {
  get.mockImplementation((url: string, options?: { params?: { counter?: string } }) => {
    if (url === '/consultants') return Promise.resolve({ data: { month: '2026-11', month_name: 'November 2026', rates: { first_visit: 1000, later_visit: 500 }, rows: [row], totals: { earned: 2995, paid: 0, due: 2995 } } })
    if (url === '/consultants/7') return Promise.resolve({ data: statement })
    if (url === '/consultants/7/collected') return Promise.resolve({ data: { front_desk: frontDesk, lab, ipd }[options?.params?.counter ?? ''] })
    return Promise.reject(new Error(`Not expected: ${url}`))
  })
})
afterEach(() => {
  cleanup()
  get.mockReset()
})

describe("a consultant's figure opens the payments behind it", () => {
  it('opens the front desk payments inside the same window, each opening its bill and patient', async () => {
    show()
    await waitFor(() => expect(popup().getByText("Their own cases: a share of what was collected")).toBeTruthy())
    expect(listsAsked()).toEqual([])

    fireEvent.click(popup().getByRole('button', { name: '₹1,750.00' }))
    await waitFor(() => expect(popup().getByText('Sample Saver')).toBeTruthy())
    expect(get).toHaveBeenCalledWith('/consultants/7/collected', { params: { month: '2026-11', counter: 'front_desk' } })
    // One window only: the list is inside the doctor's month, not a second pop-up over it.
    expect(screen.getAllByRole('dialog')).toHaveLength(1)
    expect(popup().getByRole('button', { name: '₹1,750.00' }).getAttribute('aria-expanded')).toBe('true')

    const list = within(document.querySelector('.collected-list') as HTMLElement)
    // A bill paid and then partly refunded is on two lines, each opening it.
    expect(list.getAllByRole('link', { name: 'B00055' }).map((a) => a.getAttribute('href'))).toEqual(['/front-desk/bills?open=55', '/front-desk/bills?open=55'])
    expect(list.getByRole('link', { name: 'Sample Saver' }).getAttribute('href')).toBe('/patients/32#bills')
    // A refund is its own minus line; money from a deposit says so; a cancelled lab bill is marked.
    expect(list.getAllByText('-₹100.00').length).toBe(1)
    expect(list.getByText('Payment · From deposit')).toBeTruthy()
    expect(list.getAllByText('Cancelled lab bill')).toHaveLength(2)
    // Typed in later: it shows the day it was entered, and the bill's own day where that differs.
    expect(list.getByText('Entered on 24 Nov 2026')).toBeTruthy()
    expect(list.getByText('Bill of 20 Nov 2026')).toBeTruthy()
    // The entries come to the figure clicked, and the same money is shown by how it was paid.
    expect(document.querySelector('.collected-total')?.textContent).toBe('6 entries, coming to ₹1,750.00')
    expect(list.getByText('The same money, by how it was paid')).toBeTruthy()
    expect(list.getByText('Bills moved onto an inpatient bill are counted with the IPD case.')).toBeTruthy()
    expect(where()).toBe('/workforce/consultants?month=2026-11&doctor=7&counter=front_desk')
  })

  it('comes back to the same doctor and the same list after a bill is opened', async () => {
    show()
    await waitFor(() => expect(popup().getByRole('button', { name: '₹200.00' })).toBeTruthy())
    fireEvent.click(popup().getByRole('button', { name: '₹200.00' }))
    await waitFor(() => expect(popup().getByText('L00009')).toBeTruthy())
    expect(where()).toBe('/workforce/consultants?month=2026-11&doctor=7&counter=lab')

    fireEvent.click(popup().getByRole('link', { name: 'B00058' }))
    expect(where()).toBe('/front-desk/bills?open=58')
    expect(screen.queryByRole('dialog')).toBeNull()

    fireEvent.click(screen.getByRole('button', { name: 'Go back' }))
    await waitFor(() => expect(popup().getByRole('link', { name: 'B00058' })).toBeTruthy())
    expect(where()).toBe('/workforce/consultants?month=2026-11&doctor=7&counter=lab')
    expect(listsAsked().map(([, options]) => options.params.counter)).toEqual(['lab', 'lab'])
  })

  it('opens a figure of nothing, closes the list on a second click, and forgets both when the window closes', async () => {
    show('/workforce/consultants?month=2026-11&doctor=7&counter=ipd')
    await waitFor(() => expect(popup().getByText('Nothing was collected here in this month.')).toBeTruthy())
    expect(document.querySelector('.collected-total')?.textContent).toBe('0 entries, coming to ₹0.00')

    fireEvent.click(popup().getByRole('button', { name: '₹0.00' }))
    expect(document.querySelector('.collected-list')).toBeNull()
    expect(where()).toBe('/workforce/consultants?month=2026-11&doctor=7')

    fireEvent.click(popup().getByRole('button', { name: '₹1,750.00' }))
    expect(where()).toBe('/workforce/consultants?month=2026-11&doctor=7&counter=front_desk')
    fireEvent.click(popup().getByRole('button', { name: 'Close' }))
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(where()).toBe('/workforce/consultants?month=2026-11')
  })

  it('says nothing about an empty list until the list has come, and says so when it cannot come', async () => {
    let arrive: (value: unknown) => void = () => undefined
    const asked = get.getMockImplementation() as (url: string, options?: unknown) => Promise<unknown>
    get.mockImplementation((url: string, options?: { params?: { counter?: string } }) =>
      url.endsWith('/collected') ? (options?.params?.counter === 'lab' ? Promise.reject(new Error('offline')) : new Promise((resolve) => (arrive = resolve))) : asked(url, options),
    )
    show('/workforce/consultants?month=2026-11&doctor=7&counter=ipd')
    await waitFor(() => expect(document.querySelector('.collected-list')).not.toBeNull())
    expect(within(document.querySelector('.collected-list') as HTMLElement).getByText('Loading…')).toBeTruthy()
    expect(popup().queryByText('Nothing was collected here in this month.')).toBeNull()
    expect(document.querySelector('.collected-total')).toBeNull()

    arrive({ data: ipd })
    await waitFor(() => expect(popup().getByText('Nothing was collected here in this month.')).toBeTruthy())

    fireEvent.click(popup().getByRole('button', { name: '₹200.00' }))
    await waitFor(() => expect(popup().getByText('Could not load these payments. Check the connection and try again.')).toBeTruthy())
    expect(popup().queryByText('Nothing was collected here in this month.')).toBeNull()
  })

  it("names each visit's patient and IPD case as links on the screen", async () => {
    show()
    await waitFor(() => expect(popup().getByRole('link', { name: 'Sample Ward Patient' })).toBeTruthy())
    expect(popup().getByRole('link', { name: 'Sample Ward Patient' }).getAttribute('href')).toBe('/patients/41')
    expect(popup().getByRole('link', { name: 'IP00012' }).getAttribute('href')).toBe('/ipd/12')
  })

  it('opens a doctor from the month table and keeps them in the address', async () => {
    show('/workforce/consultants?month=2026-11')
    await waitFor(() => expect(screen.getByText('Dr Sample Own')).toBeTruthy())
    expect(screen.queryByRole('dialog')).toBeNull()
    fireEvent.click(screen.getByText('Dr Sample Own'))
    await waitFor(() => expect(popup().getByRole('button', { name: '₹1,750.00' })).toBeTruthy())
    expect(where()).toBe('/workforce/consultants?month=2026-11&doctor=7')
  })

  it('leaves the paper statement with plain figures and names', () => {
    render(
      <MemoryRouter>
        <StatementBody s={statement} />
      </MemoryRouter>,
    )
    expect(screen.queryAllByRole('button')).toHaveLength(0)
    expect(screen.queryAllByRole('link')).toHaveLength(0)
    expect(screen.getByText('₹1,750.00')).toBeTruthy()
    expect(screen.getByText('Sample Ward Patient')).toBeTruthy()
    expect(screen.queryByText('IP00012')).toBeNull()
  })
})
