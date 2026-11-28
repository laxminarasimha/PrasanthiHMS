import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { PatientOverview } from '../types'
import { PatientDashboard } from './PatientDashboard'

const get = vi.fn()
vi.mock('../api', () => ({
  api: { get: (...args: unknown[]) => get(...args), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  fieldErrors: () => ({}),
}))
// Who is looking: changed by each test before the page is drawn.
let user: Record<string, unknown> = {}
vi.mock('../auth', () => ({ useAuth: () => ({ user }) }))

const doctor = { id: 4, name: 'Dr Sample Rao' }
const page = (over: Partial<PatientOverview> = {}): PatientOverview => ({
  patient: {
    id: 1, uhid: 'PH000001', name: 'Sample Patient', gender: 'female', date_of_birth: '1970-05-01', dob_estimated: false, age: 56, age_label: '56y', mobile: '9000000001', mobile_alt: null,
    email: null, blood_group: null, preferred_language: null, address: null, city: null, pincode: null, care_of: null, occupation: null, referred_by: null, channel: null, tag: null,
    existing_id: null, created_at: '2026-01-05T10:00:00Z', allergies: null,
  },
  outstanding: 0,
  deposit_balance: 0,
  deposits: [],
  visits: [
    { id: 31, scheduled_at: '2099-01-05 10:45', status: 'booked', booked_via: 'app', doctor, service: null, consultation: null },
    { id: 30, scheduled_at: '2026-11-12 10:00', status: 'reviewed', booked_via: null, doctor, service: null, consultation: { id: 8, diagnosis: 'Sample fever', completed_at: '2026-11-12 10:20' } },
  ],
  bills: [],
  lab_orders: [],
  pharmacy_bills: [],
  admissions: [],
  attachments: [],
  ...over,
})
const summaries: NonNullable<PatientOverview['discharge_summaries']> = [
  { admission_id: 9, ipd_no: 'IP00009', status: 'discharged', admitted_at: '2026-11-14 09:00', discharged_at: '2026-11-18 11:00', doctor, approved_at: '2026-11-18 12:00', follow_up_on: '2026-11-27' },
]
const prescribed: NonNullable<PatientOverview['medicines_prescribed']> = [
  { key: 'd9', kind: 'discharge', date: '2026-11-18 11:00', doctor, admission_id: 9, ipd_no: 'IP00009', medicines: [{ medicine: 'Sample Cefixime 200', directions: '1-0-1 · 5 days', medicine_id: null }] },
  { key: 'v30', kind: 'visit', date: '2026-11-12 10:20', doctor, appointment_id: 30, medicines: [{ medicine: 'Sample Paracetamol 650', directions: '1-1-1', medicine_id: 12 }] },
]
const monthly: NonNullable<PatientOverview['monthly_medicines']> = [
  {
    id: 3, title: 'Sample sugar medicines', status: 'active', day_of_month: 23, next_due_on: '2026-11-23', due_soon: true, last_prepared_on: null, notes: null,
    items: [{ medicine_id: 12, name: 'Sample Paracetamol 650', quantity: 30 }, { medicine_id: null, name: 'Sample typed tablet', quantity: 60 }],
  },
]

const show = (data: PatientOverview) => {
  get.mockImplementation((url: string) =>
    Promise.resolve({ data: url === '/patients/1/overview' ? data : url === '/patients/1/app-account' ? { account: null } : [] }),
  )
  return render(
    <MemoryRouter initialEntries={['/patients/1']}>
      <Routes>
        <Route path="/patients/:id" element={<PatientDashboard />} />
      </Routes>
    </MemoryRouter>,
  )
}
const card = (title: string) => screen.getByRole('heading', { name: new RegExp(`^${title}`) }).closest('section') as HTMLElement

afterEach(() => {
  cleanup()
  get.mockReset()
})

describe('the patient page', () => {
  it('draws the medicines and summaries only for those the server sends them to', async () => {
    user = { can_front_desk: true, can_ipd: true }
    show(page())

    await waitFor(() => expect(screen.getByText('Sample Patient')).toBeTruthy())
    expect(screen.queryByRole('heading', { name: /^Medicines prescribed/ })).toBeNull()
    expect(screen.queryByRole('heading', { name: /^Monthly medicines/ })).toBeNull()
    expect(screen.queryByRole('heading', { name: /^Discharge summaries/ })).toBeNull()
  })

  it('gives the front desk the approved summaries to print, without what is in them', async () => {
    user = { can_front_desk: true, can_ipd: true }
    show(page({ discharge_summaries: summaries }))

    await waitFor(() => expect(screen.getByRole('heading', { name: /^Discharge summaries/ })).toBeTruthy())
    const list = card('Discharge summaries')
    expect(within(list).getByText('IP00009').closest('a')?.getAttribute('href')).toBe('/ipd/9')
    expect(within(list).getByText('Print').getAttribute('href')).toBe('/ipd/9/summary/print')
    expect(within(list).getByText(/^Approved/).textContent).toContain('18 Nov 2026')
    expect(within(list).getByText(/^Review on/).textContent).toContain('27 Nov 2026')
    expect(screen.queryByRole('heading', { name: /^Medicines prescribed/ })).toBeNull()
  })

  it('shows a doctor what was prescribed, the monthly lists and each summary with its diagnosis', async () => {
    user = { can_consult: true, can_view_clinical: true, can_ipd: true, can_run_pharmacy: true, can_view_pharmacy: true }
    show(page({ discharge_summaries: [{ ...summaries[0], diagnosis: 'Sample enteric fever' }], medicines_prescribed: prescribed, monthly_medicines: monthly }))

    await waitFor(() => expect(screen.getByRole('heading', { name: /^Medicines prescribed/ })).toBeTruthy())
    expect(within(card('Discharge summaries')).getByText('Sample enteric fever')).toBeTruthy()

    const medicines = card('Medicines prescribed')
    // A medicine the pharmacy knows by that very name opens its page; one it does not stays plain.
    expect(within(medicines).getByText('Sample Paracetamol 650').closest('a')?.getAttribute('href')).toBe('/pharmacy/medicines/12')
    expect(within(medicines).getByText('Sample Cefixime 200').closest('a')).toBeNull()
    expect(within(medicines).getByText('Prescription').getAttribute('href')).toBe('/visits/30/print')
    expect(within(medicines).getByText('Summary').getAttribute('href')).toBe('/ipd/9/summary/print')
    expect(within(medicines).getByText(/On discharge/).textContent).toBe('On discharge · IP00009')

    const lists = card('Monthly medicines')
    expect(within(lists).getByText('Due this week')).toBeTruthy()
    expect(within(lists).getByText('Every month on day 23')).toBeTruthy()
    expect(within(lists).getByText('(typed by the patient, not from our list)')).toBeTruthy()
    expect(within(lists).getByText('Sample Paracetamol 650').closest('a')?.getAttribute('href')).toBe('/pharmacy/medicines/12')
    expect(within(lists).getByText(/^Sample typed tablet/).closest('a')).toBeNull()
  })

  it('gives the pharmacy no way into a summary it is not sent', async () => {
    user = { can_run_pharmacy: true, can_view_pharmacy: true }
    show(page({ medicines_prescribed: prescribed, monthly_medicines: monthly }))

    await waitFor(() => expect(screen.getByRole('heading', { name: /^Medicines prescribed/ })).toBeTruthy())
    const medicines = card('Medicines prescribed')
    expect(within(medicines).queryByText('Summary')).toBeNull()
    // The IPD number is plain text for someone who does not work in IPD.
    expect(within(medicines).getByText('On discharge · IP00009').querySelector('a')).toBeNull()
    expect(screen.queryByRole('heading', { name: /^Discharge summaries/ })).toBeNull()
  })

  it('marks a visit the patient booked on the app in the visits and on the next appointment', async () => {
    user = { can_front_desk: true }
    show(page())

    await waitFor(() => expect(screen.getByText('Sample Patient')).toBeTruthy())
    const tags = screen.getAllByTitle('Booked from the patient app')
    expect(tags.map((t) => t.textContent)).toEqual(['App', 'App'])
    expect(tags[0].closest('.tile')?.textContent).toContain('Next appointment')
    expect(within(card('Visits')).getAllByTitle('Booked from the patient app')).toHaveLength(1)
  })
})
