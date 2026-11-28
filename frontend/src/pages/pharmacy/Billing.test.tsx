import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Patient, PrescriptionForPharmacy, SellableMedicine } from '../../types'
import { PharmacyBilling } from './Billing'

const get = vi.fn()
const post = vi.fn()
vi.mock('../../api', () => ({
  api: { get: (...args: unknown[]) => get(...args), post: (...args: unknown[]) => post(...args), put: vi.fn() },
  fieldErrors: () => ({}),
}))
vi.mock('../../auth', () => ({ useAuth: () => ({ user: { can_run_pharmacy: true, can_view_pharmacy: true } }) }))

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

/** The counter opened for the patient, as the To do list and the Prescriptions page open it, with these cards. */
const show = (cards: PrescriptionForPharmacy[]) => {
  get.mockImplementation((url: string) => Promise.resolve({ data: url === '/patients/7' ? patient : url === '/pharmacy/prescriptions' ? cards : [] }))
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

afterEach(() => {
  cleanup()
  get.mockReset()
  post.mockReset()
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
