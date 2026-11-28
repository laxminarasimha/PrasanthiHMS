import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, useNavigate } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Patient, PrescriptionForPharmacy, SellableMedicine } from '../../types'
import { PharmacyBilling } from './Billing'

const get = vi.fn()
const post = vi.fn()
vi.mock('../../api', () => ({
  api: { get: (...args: unknown[]) => get(...args), post: (...args: unknown[]) => post(...args), put: vi.fn() },
  fieldErrors: () => ({}),
}))
const auth = vi.hoisted(() => ({ user: { can_run_pharmacy: true, can_view_pharmacy: true } as Record<string, unknown> }))
vi.mock('../../auth', () => ({ useAuth: () => ({ user: auth.user }) }))

// Made-up patient, doctor and medicines.
const patient = { id: 7, uhid: 'P-0007', name: 'Sample Patient', mobile: '9000000001', gender: 'female', age: 40, age_label: '40y' } as Patient
const batch = (id: number, units: number, expiry: string): SellableMedicine['sellable_batches'][number] => ({ id, batch_no: `B${id}`, expiry, unit_mrp: 2, gst_percent: 12, units_in_stock: units, rack_no: null, box_no: null })
const tablet = (batches: SellableMedicine['sellable_batches']): SellableMedicine => ({ id: 5, name: 'Sample Amlodipine 5 Tablet', type: 'Tablet', sellable_batches: batches })
const capsule: SellableMedicine = { id: 6, name: 'Sample Cefixime 200 Capsule', type: 'Capsule', sellable_batches: [batch(201, 30, '2027-03-31')] }
const visitCard = (over: Partial<PrescriptionForPharmacy> = {}): PrescriptionForPharmacy => ({
  id: 31,
  kind: 'visit',
  completed_at: '2026-11-24 10:00',
  doctor: 'Dr Sample One',
  bill: null,
  filled: false,
  medicines: [{ medicine: 'Sample Amlodipine 5 Tablet', directions: '1-1-1 · 1 week', match: tablet([batch(101, 15, '2027-01-31'), batch(102, 40, '2027-06-30')]), quantity: 21, worked_out: '1-1-1 for 1 week (7 days) = 21' }],
  ...over,
})

/** A taper: one medicine on two rows of a visit's card, 1-0-1 for 5 days and then 1-0-0 for 5 days. */
const pred = (batches: SellableMedicine['sellable_batches']): SellableMedicine => ({ id: 8, name: 'Sample Pred 10 Tablet', type: 'Tablet', sellable_batches: batches })
const taperRows = (batches: SellableMedicine['sellable_batches']): PrescriptionForPharmacy['medicines'] => [
  { medicine: 'Sample Pred 10 Tablet', directions: '1 tablet · 1-0-1 · 5 days', match: pred(batches), quantity: 10, worked_out: '1 tablet 1-0-1 for 5 days = 10' },
  { medicine: 'Sample Pred 10 Tablet', directions: '1 tablet · 1-0-0 · 5 days', match: pred(batches), quantity: 5, worked_out: '1 tablet 1-0-0 for 5 days = 5' },
]

/** The counter opened for the patient, as the To do list and the Prescriptions page open it, with these cards. */
const show = (cards: PrescriptionForPharmacy[], found: SellableMedicine[] = []) => {
  get.mockImplementation((url: string) => Promise.resolve({ data: url === '/patients/7' ? patient : url === '/pharmacy/prescriptions' ? cards : url === '/pharmacy/sell-search' ? found : [] }))
  post.mockResolvedValue({ data: { id: 40, bill_no: 'P00040', customer_name: 'Sample Patient', total: 42, ipd_transfer: 0, due: 0 } })
  return render(
    <MemoryRouter initialEntries={['/pharmacy?patient=7']}>
      <PharmacyBilling />
    </MemoryRouter>,
  )
}
const quantities = () => (screen.getAllByLabelText(/^Quantity of /) as HTMLInputElement[]).map((box) => [box.getAttribute('aria-label'), box.value])
const saved = async () => {
  fireEvent.click(screen.getByRole('button', { name: 'Save' }))
  await waitFor(() => expect(post).toHaveBeenCalledTimes(1))
  return post.mock.calls[0][1] as { consultation_id: number | null; patient_id: number; items: { stock_batch_id: number; quantity: string }[] }
}

/** The cards on the screen, in the server's order, once they are in; and a card's button by its words. */
const theCards = async (container: HTMLElement, count: number) =>
  waitFor(() => {
    const found = Array.from(container.querySelectorAll('.rx-card')) as HTMLElement[]
    expect(found).toHaveLength(count)
    return found
  })
const inCard = (card: HTMLElement, name: string) => Array.from(card.querySelectorAll('button')).find((b) => b.textContent === name)!
/** The words of a card's rows' buttons, row by row. */
const rowButtons = (card: HTMLElement) => Array.from(card.querySelectorAll('li')).map((li) => Array.from(li.querySelectorAll('button')).map((b) => b.textContent))
/** Picks a batch of a medicine by name, as the pharmacist does in the search box. */
const pickByName = async (typed: string, batchNo: RegExp) => {
  fireEvent.change(screen.getByRole('combobox', { name: 'Search medicine' }), { target: { value: typed } })
  fireEvent.click(await screen.findByText(batchNo))
}

afterEach(() => {
  cleanup()
  get.mockReset()
  post.mockReset()
  auth.user = { can_run_pharmacy: true, can_view_pharmacy: true }
})

describe('the counter, billing from a prescription card', () => {
  it('adds what the prescription comes to, from the batch that expires first and then the next, and the bill remembers the visit', async () => {
    show([visitCard()])

    fireEvent.click(await screen.findByRole('button', { name: 'Add 21' }))
    expect(quantities()).toEqual([
      ['Quantity of Sample Amlodipine 5 Tablet', '15'],
      ['Quantity of Sample Amlodipine 5 Tablet', '6'],
    ])
    // The working is on the card and on the bill; the second line says where the rest came from.
    expect(screen.getAllByText('1-1-1 for 1 week (7 days) = 21')).toHaveLength(2)
    expect(screen.getByText('rest of the 21 prescribed')).toBeTruthy()

    // Still a box the pharmacist can change.
    fireEvent.change(screen.getAllByLabelText('Quantity of Sample Amlodipine 5 Tablet')[1], { target: { value: '5' } })
    const body = await saved()
    expect(post.mock.calls[0][0]).toBe('/pharmacy/sales')
    expect(body.patient_id).toBe(7)
    expect(body.consultation_id).toBe(31)
    expect(body.items.map((i) => [i.stock_batch_id, i.quantity])).toEqual([
      [101, '15'],
      [102, '5'],
    ])
  })

  it('never adds more than is in stock, and says how short it is', async () => {
    show([visitCard({ medicines: [{ ...visitCard().medicines[0], match: tablet([batch(101, 15, '2027-01-31')]) }] })])

    fireEvent.click(await screen.findByRole('button', { name: 'Add 15' }))
    expect(quantities()).toEqual([['Quantity of Sample Amlodipine 5 Tablet', '15']])
    expect(screen.getByText('prescribed 21, 15 in stock').className).toContain('due')
  })

  it('adds 1, as before, where nothing was worked out, and a medicine already on the bill is not added again', async () => {
    show([visitCard({ medicines: [{ ...visitCard().medicines[0], quantity: null, worked_out: null }] })])

    fireEvent.click(await screen.findByRole('button', { name: 'Add' }))
    fireEvent.click(screen.getByRole('button', { name: 'Add all in stock (1)' }))
    expect(quantities()).toEqual([['Quantity of Sample Amlodipine 5 Tablet', '1']])
    expect((await saved()).consultation_id).toBe(31)
  })

  it('never remembers a ward or discharge card, and shows a prescription already filled', async () => {
    const { container } = show([
      { id: 3, kind: 'ward', completed_at: '2026-11-23 10:00', doctor: 'Dr Sample One', note: 'Running on the ward.', medicines: [{ medicine: 'Sample Amlodipine 5 Tablet', directions: '1 tab · BD', match: tablet([batch(101, 15, '2027-01-31')]), new: true, quantity: null, worked_out: null }] },
      { id: 31, kind: 'discharge', completed_at: '2026-11-24 09:00', doctor: 'Dr Sample One', note: 'To take home.', medicines: [{ medicine: 'Sample Cefixime 200 Capsule', directions: '1 cap · BD · 5 days', match: capsule, quantity: 10, worked_out: '1 cap BD for 5 days = 10' }] },
      visitCard({ id: 30, bill: { id: 12, bill_no: 'P00012' }, filled: true }),
    ])

    fireEvent.click(await screen.findByRole('button', { name: 'Add 10' }))
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    expect(quantities()).toEqual([
      ['Quantity of Sample Cefixime 200 Capsule', '10'],
      ['Quantity of Sample Amlodipine 5 Tablet', '1'],
    ])
    const pill = container.querySelector('.rx-card.visit .pill.ok')
    expect(pill?.textContent).toBe('Filled · P00012')
    expect(screen.getByRole('link', { name: 'P00012' }).getAttribute('href')).toBe('/pharmacy/bills?open=12')

    // The discharge card's id is a summary's, never a visit's, so nothing is sent.
    expect((await saved()).consultation_id).toBeNull()
  })
})

describe('the counter, a medicine on more than one row of a card', () => {
  it('adds a second row to the medicine already on the bill, and never adds a row twice', async () => {
    show([visitCard({ medicines: taperRows([batch(301, 50, '2027-02-28')]) })])

    fireEvent.click(await screen.findByRole('button', { name: 'Add 10' }))
    expect(quantities()).toEqual([['Quantity of Sample Pred 10 Tablet', '10']])
    // The row now on the bill says so. Pressing it again adds nothing; it goes to the line.
    fireEvent.click(screen.getByRole('button', { name: 'On the bill' }))
    expect(quantities()).toEqual([['Quantity of Sample Pred 10 Tablet', '10']])
    expect(document.activeElement).toBe(screen.getByLabelText('Quantity of Sample Pred 10 Tablet'))

    // The second row's 5 are added to the 10, on the same line, and the line says how both were worked out.
    fireEvent.click(screen.getByRole('button', { name: 'Add 5' }))
    expect(quantities()).toEqual([['Quantity of Sample Pred 10 Tablet', '15']])
    expect(screen.getByText('1 tablet 1-0-1 for 5 days = 10; 1 tablet 1-0-0 for 5 days = 5')).toBeTruthy()
    expect(screen.getAllByRole('button', { name: 'On the bill' })).toHaveLength(2)
    fireEvent.click(screen.getAllByRole('button', { name: 'On the bill' })[1])
    expect(quantities()).toEqual([['Quantity of Sample Pred 10 Tablet', '15']])

    const body = await saved()
    expect(body.consultation_id).toBe(31)
    expect(body.items.map((i) => [i.stock_batch_id, i.quantity])).toEqual([[301, '15']])
  })

  it('adds up the rows of one medicine for Add all in stock', async () => {
    const amlodipine = visitCard().medicines[0]
    const [first, second] = taperRows([batch(301, 50, '2027-02-28')])
    show([visitCard({ medicines: [first, amlodipine, second] })])

    fireEvent.click(await screen.findByRole('button', { name: 'Add all in stock (3)' }))
    expect(quantities()).toEqual([
      ['Quantity of Sample Pred 10 Tablet', '15'],
      ['Quantity of Sample Amlodipine 5 Tablet', '15'],
      ['Quantity of Sample Amlodipine 5 Tablet', '6'],
    ])
    expect(screen.getAllByRole('button', { name: 'On the bill' })).toHaveLength(3)
    // Pressed again, nothing more is added.
    fireEvent.click(screen.getByRole('button', { name: 'Add all in stock (3)' }))
    expect(quantities()).toEqual([
      ['Quantity of Sample Pred 10 Tablet', '15'],
      ['Quantity of Sample Amlodipine 5 Tablet', '15'],
      ['Quantity of Sample Amlodipine 5 Tablet', '6'],
    ])
  })

  it('tops up the line on the bill as far as its batch has stock, then takes from the next batch', async () => {
    show([visitCard({ medicines: taperRows([batch(301, 12, '2027-02-28'), batch(302, 40, '2027-08-31')]) })])

    fireEvent.click(await screen.findByRole('button', { name: 'Add 10' }))
    fireEvent.click(screen.getByRole('button', { name: 'Add 5' }))
    expect(quantities()).toEqual([
      ['Quantity of Sample Pred 10 Tablet', '12'],
      ['Quantity of Sample Pred 10 Tablet', '3'],
    ])
    expect(screen.getByText('rest of the 5 prescribed')).toBeTruthy()
    expect((await saved()).items.map((i) => [i.stock_batch_id, i.quantity])).toEqual([
      [301, '12'],
      [302, '3'],
    ])
  })

  it('never adds more than is in stock, and says so when the bill already has all of it', async () => {
    show([visitCard({ medicines: taperRows([batch(301, 12, '2027-02-28')]) })])

    fireEvent.click(await screen.findByRole('button', { name: 'Add 10' }))
    // Only 2 more are in stock: the button says what it will add, and the line says how short it is.
    fireEvent.click(screen.getByRole('button', { name: 'Add 2' }))
    expect(quantities()).toEqual([['Quantity of Sample Pred 10 Tablet', '12']])
    expect(screen.getByText('prescribed 5, only 2 more in stock').className).toContain('due')
    cleanup()

    show([visitCard({ medicines: taperRows([batch(301, 10, '2027-02-28')]) })])
    fireEvent.click(await screen.findByRole('button', { name: 'Add 10' }))
    expect(screen.getByText('All in stock is on the bill')).toBeTruthy()
    expect(screen.queryByRole('button', { name: /^Add \d/ })).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'Add all in stock (2)' }))
    expect(quantities()).toEqual([['Quantity of Sample Pred 10 Tablet', '10']])
  })

  it('puts the count in place of the 1 a medicine picked by name starts at, and the bill is still made from the visit', async () => {
    show([visitCard()], [tablet([batch(101, 15, '2027-01-31'), batch(102, 40, '2027-06-30')])])
    await screen.findByRole('button', { name: 'Add 21' })

    fireEvent.change(screen.getByRole('combobox', { name: 'Search medicine' }), { target: { value: 'Sample Aml' } })
    fireEvent.click(await screen.findByText(/Batch B101/))
    expect(quantities()).toEqual([['Quantity of Sample Amlodipine 5 Tablet', '1']])

    fireEvent.click(screen.getByRole('button', { name: 'Add 21' }))
    expect(quantities()).toEqual([
      ['Quantity of Sample Amlodipine 5 Tablet', '15'],
      ['Quantity of Sample Amlodipine 5 Tablet', '6'],
    ])
    // Said on the card's row and, as how its count was worked out, on the bill's line: nothing was 'already on the bill'.
    expect(screen.getAllByText('1-1-1 for 1 week (7 days) = 21')).toHaveLength(2)
    expect(screen.queryByText(/already on the bill/)).toBeNull()
    const body = await saved()
    expect(body.consultation_id).toBe(31)
    expect(body.items.map((i) => [i.stock_batch_id, i.quantity])).toEqual([
      [101, '15'],
      [102, '6'],
    ])
  })

  it('links the bill to the visit when a line first added from the ward card takes a visit row too', async () => {
    const stock = () => tablet([batch(101, 50, '2027-01-31')])
    show([
      { id: 3, kind: 'ward', completed_at: '2026-11-23 10:00', doctor: 'Dr Sample One', note: 'Running on the ward.', medicines: [{ medicine: 'Sample Amlodipine 5 Tablet', directions: '1 tab · BD', match: stock(), new: true, quantity: null, worked_out: null }] },
      visitCard({ medicines: [{ ...visitCard().medicines[0], match: stock() }] }),
    ])

    fireEvent.click(await screen.findByRole('button', { name: 'Add' }))
    fireEvent.click(screen.getByRole('button', { name: 'Add 21' }))
    // The ward row had no count, so its 1 gives way to the visit's 21.
    expect(quantities()).toEqual([['Quantity of Sample Amlodipine 5 Tablet', '21']])
    const body = await saved()
    expect(body.consultation_id).toBe(31)
  })
})

describe('the counter, a medicine already on the bill some other way', () => {
  const discharge = (): PrescriptionForPharmacy => ({
    id: 4, kind: 'discharge', completed_at: '2026-11-20 09:00', doctor: 'Dr Sample One', note: 'To take home.',
    medicines: [{ ...visitCard().medicines[0], match: tablet([batch(101, 15, '2027-01-31'), batch(102, 40, '2027-06-30')]) }],
  })

  it('never adds a course twice from two cards without asking: the second card offers Add N more', async () => {
    // As the server sends them: the discharge card above the follow-up visit's card.
    const { container } = show([discharge(), visitCard()])
    const cards = await theCards(container, 2)

    fireEvent.click(inCard(cards[0], 'Add all in stock (1)'))
    expect(quantities()).toEqual([
      ['Quantity of Sample Amlodipine 5 Tablet', '15'],
      ['Quantity of Sample Amlodipine 5 Tablet', '6'],
    ])
    // The visit's card lists the same 21: Add all in stock adds nothing…
    fireEvent.click(inCard(cards[1], 'Add all in stock (1)'))
    expect(quantities()).toEqual([
      ['Quantity of Sample Amlodipine 5 Tablet', '15'],
      ['Quantity of Sample Amlodipine 5 Tablet', '6'],
    ])
    // …and its row says the medicine is on the bill, with its count added only on purpose.
    expect(inCard(cards[1], 'On the bill')).toBeTruthy()
    expect(inCard(cards[1], 'Add 21 more')).toBeTruthy()
    // The bill is still given from the visit's card, so it is linked to that visit.
    const body = await saved()
    expect(body.consultation_id).toBe(31)
    expect(body.items.map((i) => [i.stock_batch_id, i.quantity])).toEqual([
      [101, '15'],
      [102, '6'],
    ])
  })

  it('adds the second card’s count when asked to', async () => {
    const { container } = show([discharge(), visitCard()])
    const cards = await theCards(container, 2)
    fireEvent.click(inCard(cards[0], 'Add all in stock (1)'))
    fireEvent.click(inCard(cards[1], 'Add 21 more'))
    expect(quantities()).toEqual([
      ['Quantity of Sample Amlodipine 5 Tablet', '15'],
      ['Quantity of Sample Amlodipine 5 Tablet', '27'],
    ])
    expect((await saved()).consultation_id).toBe(31)
  })

  it('does not add to a quantity the pharmacist typed, unless asked to', async () => {
    show([visitCard()], [tablet([batch(101, 15, '2027-01-31'), batch(102, 40, '2027-06-30')])])
    await screen.findByRole('button', { name: 'Add 21' })
    fireEvent.change(screen.getByRole('combobox', { name: 'Search medicine' }), { target: { value: 'Sample Aml' } })
    fireEvent.click(await screen.findByText(/Batch B101/))
    fireEvent.change(screen.getByLabelText('Quantity of Sample Amlodipine 5 Tablet'), { target: { value: '10' } })

    fireEvent.click(screen.getByRole('button', { name: 'Add all in stock (1)' }))
    expect(quantities()).toEqual([['Quantity of Sample Amlodipine 5 Tablet', '10']])
    fireEvent.click(screen.getByRole('button', { name: 'Add 21 more' }))
    expect(quantities()).toEqual([
      ['Quantity of Sample Amlodipine 5 Tablet', '15'],
      ['Quantity of Sample Amlodipine 5 Tablet', '16'],
    ])
    expect(screen.getByText('10 already on the bill; 1-1-1 for 1 week (7 days) = 21')).toBeTruthy()
  })

  it('links the bill to the visit when the visit’s card is pressed for a quantity the pharmacist typed', async () => {
    show([visitCard()], [tablet([batch(101, 15, '2027-01-31'), batch(102, 40, '2027-06-30')])])
    await screen.findByRole('button', { name: 'Add 21' })
    fireEvent.change(screen.getByRole('combobox', { name: 'Search medicine' }), { target: { value: 'Sample Aml' } })
    fireEvent.click(await screen.findByText(/Batch B101/))
    fireEvent.change(screen.getByLabelText('Quantity of Sample Amlodipine 5 Tablet'), { target: { value: '10' } })

    fireEvent.click(screen.getByRole('button', { name: 'Add all in stock (1)' }))
    const body = await saved()
    expect(body.items.map((i) => [i.stock_batch_id, i.quantity])).toEqual([[101, '10']])
    expect(body.consultation_id).toBe(31)
  })

  it('knows the rows on the bill when the cards are read again with a row added above', async () => {
    const amlodipine = visitCard().medicines[0]
    const [taper] = taperRows([batch(301, 50, '2027-02-28')])
    let cards = [visitCard({ medicines: [taper] })]
    get.mockImplementation((url: string) => Promise.resolve({ data: url === '/patients/7' ? patient : url === '/pharmacy/prescriptions' ? cards : [] }))
    function Again() {
      const navigate = useNavigate()
      return (
        <button type="button" onClick={() => navigate('/pharmacy?patient=7')}>
          Open again
        </button>
      )
    }
    render(
      <MemoryRouter initialEntries={['/pharmacy?patient=7']}>
        <PharmacyBilling />
        <Again />
      </MemoryRouter>,
    )
    fireEvent.click(await screen.findByRole('button', { name: 'Add 10' }))
    // The doctor has since added a row above: read again, the card's first row is another medicine.
    cards = [visitCard({ medicines: [amlodipine, taper] })]
    fireEvent.click(screen.getByRole('button', { name: 'Open again' }))
    await screen.findByRole('button', { name: 'Add 21' })
    expect(screen.getAllByRole('button', { name: 'On the bill' })).toHaveLength(1)

    fireEvent.click(screen.getByRole('button', { name: 'Add all in stock (2)' }))
    expect(quantities()).toEqual([
      ['Quantity of Sample Pred 10 Tablet', '10'],
      ['Quantity of Sample Amlodipine 5 Tablet', '15'],
      ['Quantity of Sample Amlodipine 5 Tablet', '6'],
    ])
  })

  it('still knows the rows on the bill after another patient is chosen and then this one again', async () => {
    const other = { ...patient, id: 8, uhid: 'P-0008', name: 'Sample Other' } as Patient
    const admitted = [
      { admission_id: 1, ipd_no: 'IPD-1', patient, bed: 'Bed 1', doctor: 'Dr Sample One', bill_approved: false },
      { admission_id: 2, ipd_no: 'IPD-2', patient: other, bed: 'Bed 2', doctor: 'Dr Sample One', bill_approved: false },
    ]
    get.mockImplementation((url: string, config?: { params?: { patient_id?: number } }) =>
      Promise.resolve({
        data: url === '/patients/7' ? patient : url === '/pharmacy/inpatients' ? admitted : url === '/pharmacy/prescriptions' ? (config?.params?.patient_id === 7 ? [visitCard()] : []) : [],
      }),
    )
    post.mockResolvedValue({ data: { id: 40, bill_no: 'P00040', customer_name: 'Sample Patient', total: 42, ipd_transfer: 42, due: 0 } })
    const { container } = render(
      <MemoryRouter initialEntries={['/pharmacy?patient=7']}>
        <PharmacyBilling />
      </MemoryRouter>,
    )
    fireEvent.click(await screen.findByRole('button', { name: 'Add 21' }))
    fireEvent.click(screen.getByRole('button', { name: /^Inpatient/ }))
    const ward = container.querySelector('select') as HTMLSelectElement
    fireEvent.change(ward, { target: { value: '2' } })
    await waitFor(() => expect(screen.queryByRole('button', { name: 'On the bill' })).toBeNull())
    fireEvent.change(ward, { target: { value: '1' } })

    fireEvent.click(await screen.findByRole('button', { name: 'On the bill' }))
    expect(quantities()).toEqual([
      ['Quantity of Sample Amlodipine 5 Tablet', '15'],
      ['Quantity of Sample Amlodipine 5 Tablet', '6'],
    ])
    expect((await saved()).consultation_id).toBe(31)
  })
})

describe('the counter, a taper beside a ward order, and cards that change', () => {
  const stock = () => [batch(301, 50, '2027-02-28')]
  const wardCard = (): PrescriptionForPharmacy => ({
    id: 3, kind: 'ward', completed_at: '2026-11-23 10:00', doctor: 'Dr Sample One', note: 'Running on the ward.',
    medicines: [{ medicine: 'Sample Pred 10 Tablet', directions: '1 tab · BD', match: pred(stock()), new: true, quantity: null, worked_out: null }],
  })

  it('adds the whole take-home taper after a ward row with no count put its 1 on the bill', async () => {
    const takeHome: PrescriptionForPharmacy = { id: 4, kind: 'discharge', completed_at: '2026-11-24 09:00', doctor: 'Dr Sample One', note: 'To take home.', medicines: taperRows(stock()) }
    const { container } = show([wardCard(), takeHome])
    const cards = await theCards(container, 2)

    fireEvent.click(inCard(cards[0], 'Add'))
    expect(quantities()).toEqual([['Quantity of Sample Pred 10 Tablet', '1']])
    // The taper's first stage takes the place of the 1, and its second stage can still be added.
    fireEvent.click(inCard(cards[1], 'Add 10'))
    expect(quantities()).toEqual([['Quantity of Sample Pred 10 Tablet', '10']])
    expect(rowButtons(cards[1])).toEqual([['On the bill'], ['Add 5']])
    fireEvent.click(inCard(cards[1], 'Add all in stock (2)'))
    expect(quantities()).toEqual([['Quantity of Sample Pred 10 Tablet', '15']])
  })

  it('keeps a visit taper’s second stage open after the ward row is pressed, and the bill is linked to the visit', async () => {
    const { container } = show([wardCard(), visitCard({ medicines: taperRows(stock()) })])
    const cards = await theCards(container, 2)

    fireEvent.click(inCard(cards[1], 'Add 10'))
    // The ward row has no count: it only goes to the line.
    fireEvent.click(inCard(cards[0], 'On the bill'))
    expect(quantities()).toEqual([['Quantity of Sample Pred 10 Tablet', '10']])
    expect(rowButtons(cards[1])).toEqual([['On the bill'], ['Add 5']])
    fireEvent.click(inCard(cards[1], 'Add all in stock (2)'))
    expect(quantities()).toEqual([['Quantity of Sample Pred 10 Tablet', '15']])
    expect((await saved()).consultation_id).toBe(31)
  })

  it('does not add a row the doctor changed after it went on the bill, and says it changed', async () => {
    const takeHome = (days: number): PrescriptionForPharmacy => ({
      id: 4, kind: 'discharge', completed_at: '2026-11-24 09:00', doctor: 'Dr Sample One', note: 'Not approved yet: this may still change.',
      medicines: [{ medicine: 'Sample Pred 10 Tablet', directions: `1 tablet · 1-0-1 · ${days} days`, match: pred(stock()), quantity: days * 2, worked_out: `1 tablet 1-0-1 for ${days} days = ${days * 2}` }],
    })
    let cards = [takeHome(5)]
    get.mockImplementation((url: string) => Promise.resolve({ data: url === '/patients/7' ? patient : url === '/pharmacy/prescriptions' ? cards : [] }))
    function Again() {
      const navigate = useNavigate()
      return (
        <button type="button" onClick={() => navigate('/pharmacy?patient=7')}>
          Open again
        </button>
      )
    }
    render(
      <MemoryRouter initialEntries={['/pharmacy?patient=7']}>
        <PharmacyBilling />
        <Again />
      </MemoryRouter>,
    )
    fireEvent.click(await screen.findByRole('button', { name: 'Add all in stock (1)' }))
    expect(quantities()).toEqual([['Quantity of Sample Pred 10 Tablet', '10']])

    // The doctor makes it 7 days (14) before the summary is approved, and the card is read again.
    cards = [takeHome(7)]
    fireEvent.click(screen.getByRole('button', { name: 'Open again' }))
    await screen.findByText('Changed on the card after it went on the bill as “1 tablet · 1-0-1 · 5 days”. The bill has 10: check the quantity.')
    expect(screen.getByRole('button', { name: 'Add 14 more' })).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Add all in stock (1)' }))
    expect(quantities()).toEqual([['Quantity of Sample Pred 10 Tablet', '10']])
  })

  it('puts exactly the count on when two batches were picked by name, soonest expiry first', async () => {
    const two = () => tablet([batch(101, 15, '2027-01-31'), batch(102, 40, '2027-06-30')])
    show([visitCard()], [two()])
    await screen.findByRole('button', { name: 'Add 21' })
    await pickByName('Sample Aml', /Batch B102/)
    await pickByName('Sample Aml', /Batch B101/)
    expect(quantities()).toEqual([
      ['Quantity of Sample Amlodipine 5 Tablet', '1'],
      ['Quantity of Sample Amlodipine 5 Tablet', '1'],
    ])

    fireEvent.click(screen.getByRole('button', { name: 'Add 21' }))
    // 15 from B101, which expires first, and 6 from B102: 21 in all, not 22.
    expect(quantities()).toEqual([
      ['Quantity of Sample Amlodipine 5 Tablet', '6'],
      ['Quantity of Sample Amlodipine 5 Tablet', '15'],
    ])
    const body = await saved()
    expect(body.items.map((i) => [i.stock_batch_id, i.quantity])).toEqual([
      [102, '6'],
      [101, '15'],
    ])
    expect(body.consultation_id).toBe(31)
  })

  it('takes off a batch picked by name that the count does not need', async () => {
    const two = () => tablet([batch(101, 15, '2027-01-31'), batch(102, 40, '2027-06-30')])
    const ten = visitCard({ medicines: [{ ...visitCard().medicines[0], match: two(), directions: '1-0-1 · 5 days', quantity: 10, worked_out: '1-0-1 for 5 days = 10' }] })
    show([ten], [two()])
    await screen.findByRole('button', { name: 'Add 10' })
    await pickByName('Sample Aml', /Batch B101/)
    await pickByName('Sample Aml', /Batch B102/)

    fireEvent.click(screen.getByRole('button', { name: 'Add 10' }))
    expect(quantities()).toEqual([['Quantity of Sample Amlodipine 5 Tablet', '10']])
    expect((await saved()).items.map((i) => [i.stock_batch_id, i.quantity])).toEqual([[101, '10']])
  })
})

describe('the counter, the visit a bill is linked to', () => {
  /** A day some days back, as YYYY-MM-DD, and a card's time on it. */
  const daysAgo = (n: number) => {
    const d = new Date()
    d.setDate(d.getDate() - n)
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  }
  const stock = () => tablet([batch(101, 15, '2027-01-31'), batch(102, 40, '2027-06-30')])
  const amlodipine = (days: number): PrescriptionForPharmacy['medicines'][number] => ({
    medicine: 'Sample Amlodipine 5 Tablet', directions: `1-0-1 · ${days} days`, match: stock(), quantity: days * 2, worked_out: `1-0-1 for ${days} days = ${days * 2}`,
  })
  const cefixime: PrescriptionForPharmacy['medicines'][number] = { medicine: 'Sample Cefixime 200 Capsule', directions: '1 cap · BD · 5 days', match: capsule, quantity: 10, worked_out: '1 cap BD for 5 days = 10' }
  const visit = (id: number, ago: number, medicines: PrescriptionForPharmacy['medicines'], patientName = 'Dr Sample One'): PrescriptionForPharmacy => ({
    id, kind: 'visit', completed_at: `${daysAgo(ago)} 10:00`, doctor: patientName, bill: null, filled: false, medicines,
  })
  const other = { ...patient, id: 8, uhid: 'P-0008', name: 'Sample Other' } as Patient

  /** The counter opened at /pharmacy?patient=7, with a way to open another patient from the top search, as the pharmacist does. */
  function open(cardsFor: Record<number, PrescriptionForPharmacy[]>) {
    get.mockImplementation((url: string, config?: { params?: { patient_id?: number } }) =>
      Promise.resolve({
        data: url === '/patients/7' ? patient : url === '/patients/8' ? other : url === '/pharmacy/prescriptions' ? (cardsFor[config?.params?.patient_id ?? 0] ?? []) : [],
      }),
    )
    post.mockResolvedValue({ data: { id: 40, bill_no: 'P00040', customer_name: 'Sample Patient', total: 42, ipd_transfer: 0, due: 0 } })
    function Search() {
      const navigate = useNavigate()
      return (
        <>
          <button type="button" onClick={() => navigate('/pharmacy?patient=7')}>
            Open patient 7
          </button>
          <button type="button" onClick={() => navigate('/pharmacy?patient=8')}>
            Open patient 8
          </button>
        </>
      )
    }
    return render(
      <MemoryRouter initialEntries={['/pharmacy?patient=7']}>
        <PharmacyBilling />
        <Search />
      </MemoryRouter>,
    )
  }
  const notes = () => Array.from(document.querySelectorAll('tbody td small.muted.block')).map((n) => n.textContent).filter((t) => !/^of \d+$/.test(t ?? ''))

  it('“On the bill” on another card’s row only goes to the line: the bill stays with the card it was given from', async () => {
    const { container } = open({ 7: [visit(32, 1, [amlodipine(5)]), visit(31, 4, [amlodipine(5), cefixime])] })
    const cards = await theCards(container, 2)
    fireEvent.click(inCard(cards[1], 'Add all in stock (2)'))
    fireEvent.click(inCard(cards[0], 'On the bill'))
    expect(screen.getByText(`Given from the prescription of ${new Date(daysAgo(4)).getDate()}`, { exact: false })).toBeTruthy()
    expect((await saved()).consultation_id).toBe(31)
  })

  it('never links a missed bill for an earlier day to a visit made after that day', async () => {
    auth.user = { can_run_pharmacy: true, can_view_pharmacy: true, abilities: { canEnterEarlierDays: true } }
    const { container } = open({ 7: [visit(32, 1, [amlodipine(5)]), visit(31, 4, [amlodipine(5), cefixime])] })
    const cards = await theCards(container, 2)
    fireEvent.click(inCard(cards[1], 'Add all in stock (2)'))
    // The newer visit's card is pressed too: its row is on the bill already, so nothing is added.
    fireEvent.click(inCard(cards[0], 'Add all in stock (1)'))
    fireEvent.click(screen.getByRole('button', { name: 'Was this missed on an earlier day?' }))
    fireEvent.change(screen.getByLabelText('Bill date'), { target: { value: daysAgo(3) } })
    const body = (await saved()) as unknown as { consultation_id: number | null; sold_on: string }
    expect(body.sold_on).toBe(daysAgo(3))
    expect(body.consultation_id).toBe(31)
  })

  it('does not link a take-home bill to an old visit by going to the line', async () => {
    const takeHome: PrescriptionForPharmacy = { id: 4, kind: 'discharge', completed_at: `${daysAgo(2)} 09:00`, doctor: 'Dr Sample One', note: 'To take home.', medicines: [amlodipine(5)] }
    const { container } = open({ 7: [takeHome, visit(31, 18, [amlodipine(5), cefixime])] })
    const cards = await theCards(container, 2)
    fireEvent.click(inCard(cards[0], 'Add all in stock (1)'))
    fireEvent.click(inCard(cards[1], 'On the bill'))
    expect((await saved()).consultation_id).toBeNull()
  })

  it('keeps a patient’s link after another patient is chosen and then this one again', async () => {
    const { container } = open({ 7: [visit(31, 4, [amlodipine(5)])], 8: [visit(41, 3, [amlodipine(5), cefixime])] })
    fireEvent.click(inCard((await theCards(container, 1))[0], 'Add 10'))
    fireEvent.click(screen.getByRole('button', { name: 'Open patient 8' }))
    await waitFor(() => expect(container.querySelector('.rx-card')?.textContent).toContain('Sample Cefixime'))
    // Patient 8's card: the Cefixime goes on; the Amlodipine on the bill is patient 7's, and stays so.
    fireEvent.click(inCard(container.querySelector('.rx-card') as HTMLElement, 'Add all in stock (2)'))
    expect(quantities()).toEqual([
      ['Quantity of Sample Amlodipine 5 Tablet', '10'],
      ['Quantity of Sample Cefixime 200 Capsule', '10'],
    ])
    fireEvent.click(screen.getAllByRole('button', { name: 'Remove' })[1])
    fireEvent.click(screen.getByRole('button', { name: 'Open patient 7' }))
    await waitFor(() => expect(rowButtons(container.querySelector('.rx-card') as HTMLElement)).toEqual([['On the bill']]))
    const body = (await saved()) as unknown as { consultation_id: number | null; patient_id: number }
    expect([body.patient_id, body.consultation_id]).toEqual([7, 31])
  })

  it('never shows another patient’s working as this patient’s, nor claims this patient’s visit for it', async () => {
    const { container } = open({ 7: [visit(31, 4, [amlodipine(5)])], 8: [visit(50, 3, [amlodipine(3)])] })
    await theCards(container, 1)
    // The wrong patient first: patient 8's 6 tablets go on.
    fireEvent.click(screen.getByRole('button', { name: 'Open patient 8' }))
    await waitFor(() => expect(rowButtons(container.querySelector('.rx-card') as HTMLElement)).toEqual([['Add 6']]))
    fireEvent.click(inCard(container.querySelector('.rx-card') as HTMLElement, 'Add 6'))
    expect(notes()).toEqual(['1-0-1 for 3 days = 6'])

    // Then the right one: patient 7's row is on the bill some other way, and the 6 are not shown as worked out for them.
    fireEvent.click(screen.getByRole('button', { name: 'Open patient 7' }))
    await waitFor(() => expect(rowButtons(container.querySelector('.rx-card') as HTMLElement)).toEqual([['On the bill', 'Add 10 more']]))
    expect(notes()).toEqual([])
    fireEvent.click(inCard(container.querySelector('.rx-card') as HTMLElement, 'Add all in stock (1)'))
    fireEvent.click(inCard(container.querySelector('.rx-card') as HTMLElement, 'On the bill'))
    expect(notes()).toEqual([])
    expect(screen.queryByText(/Given from the prescription of/)).toBeNull()

    // Added on purpose, the line says what was there and what this card adds, and the bill is this visit's.
    fireEvent.click(inCard(container.querySelector('.rx-card') as HTMLElement, 'Add 10 more'))
    expect(quantities()).toEqual([['Quantity of Sample Amlodipine 5 Tablet', '15'], ['Quantity of Sample Amlodipine 5 Tablet', '1']])
    expect(notes()).toEqual(['6 already on the bill; 1-0-1 for 5 days = 10', 'rest of the 10 prescribed'])
    expect((await saved()).consultation_id).toBe(31)
  })

  it('says once, on the medicine’s first row, what a changed row said when it went on the bill', async () => {
    const takeHome = (days: number): PrescriptionForPharmacy => ({
      id: 4, kind: 'discharge', completed_at: `${daysAgo(2)} 09:00`, doctor: 'Dr Sample One', note: 'Not approved yet: this may still change.',
      medicines: [
        { medicine: 'Sample Pred 10 Tablet', directions: `1 tablet · 1-0-1 · ${days} days`, match: pred([batch(301, 50, '2027-02-28')]), quantity: days * 2, worked_out: `1 tablet 1-0-1 for ${days} days = ${days * 2}` },
        { medicine: 'Sample Pred 10 Tablet', directions: '1 tablet · 1-0-0 · 5 days', match: pred([batch(301, 50, '2027-02-28')]), quantity: 5, worked_out: '1 tablet 1-0-0 for 5 days = 5' },
      ],
    })
    let cards = [takeHome(5)]
    const { container } = open({})
    get.mockImplementation((url: string) => Promise.resolve({ data: url === '/patients/7' ? patient : url === '/pharmacy/prescriptions' ? cards : [] }))
    fireEvent.click(screen.getByRole('button', { name: 'Open patient 7' }))
    fireEvent.click(inCard((await theCards(container, 1))[0], 'Add 10'))

    cards = [takeHome(7)]
    fireEvent.click(screen.getByRole('button', { name: 'Open patient 7' }))
    const said = await screen.findAllByText(/^Changed on the card after it went on the bill/)
    expect(said.map((n) => n.textContent)).toEqual(['Changed on the card after it went on the bill as “1 tablet · 1-0-1 · 5 days”. The bill has 10: check the quantity.'])
    const rows = Array.from((container.querySelector('.rx-card') as HTMLElement).querySelectorAll('li'))
    expect(rows[0].textContent).toContain('Changed on the card')
    expect(rows[1].textContent).not.toContain('Changed on the card')
  })
})
