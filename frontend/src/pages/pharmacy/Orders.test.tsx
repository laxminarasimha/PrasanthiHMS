import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { PrescriptionOrder } from '../../types'
import { PharmacyOrders } from './Orders'

const get = vi.fn()
vi.mock('../../api', () => ({ api: { get: (...args: unknown[]) => get(...args) }, fieldErrors: () => ({}) }))
vi.mock('../../auth', () => ({ useAuth: () => ({ user: { can_run_pharmacy: true, can_view_pharmacy: true } }) }))

// Made-up patients and doctor.
const order = (id: number, bill: PrescriptionOrder['bill'], filled: boolean): PrescriptionOrder => ({
  id,
  completed_at: '2026-11-24 10:30',
  patient: { id, uhid: `P-000${id}`, name: `Sample Patient ${id}`, gender: 'female', age: 40, age_label: '40y', title: null, mobile: '9000000001' },
  doctor: 'Dr Sample One',
  medicines: [{ id, medicine: 'Sample Amlodipine 5 Tablet', dose: null, frequency: '1-0-1', duration: '5 days' }],
  bill,
  filled,
})

afterEach(() => {
  cleanup()
  get.mockReset()
})

describe('the Prescriptions page', () => {
  it('tells a prescription filled from its card from one with only a later bill for the patient', async () => {
    get.mockResolvedValue({ data: [order(1, { id: 12, bill_no: 'P00012' }, true), order(2, { id: 13, bill_no: 'P00013' }, false), order(3, null, false)] })
    const { container } = render(
      <MemoryRouter>
        <PharmacyOrders />
      </MemoryRouter>,
    )

    await waitFor(() => expect(screen.getByText('Waiting')).toBeTruthy())
    expect([...container.querySelectorAll('td .pill')].map((p) => p.textContent)).toEqual(['Filled · P00012', 'Billed since · P00013', 'Waiting'])
    // The bill's number opens it among the pharmacy's bills.
    expect(screen.getByRole('link', { name: 'P00012' }).getAttribute('href')).toBe('/pharmacy/bills?open=12')
    expect(screen.getByText('1 waiting · 2 billed')).toBeTruthy()
  })
})
