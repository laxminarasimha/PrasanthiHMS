import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { today } from '../format'
import type { Appointment, QueueEntry } from '../types'
import { Calendar } from './Calendar'
import { DoctorQueue } from './DoctorQueue'
import { FrontDesk } from './FrontDesk'

const get = vi.fn()
vi.mock('../api', () => ({
  api: { get: (...args: unknown[]) => get(...args), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  fieldErrors: () => ({}),
}))
vi.mock('../auth', () => ({ useAuth: () => ({ user: { role: 'frontdesk', can_front_desk: true, can_ipd: true } }) }))

const doctor = { id: 2, name: 'Dr Sample Rao' }
const patient = (id: number, name: string) => ({ id, uhid: `PH00000${id}`, name, gender: 'female', age: 56, age_label: '56y', title: null, mobile: '9000000001' })
const booking = (id: number, name: string, via: string | null, time: string): Appointment => ({
  id, scheduled_at: `${today()} ${time}`, duration_minutes: 15, status: 'booked', token_no: id, notes: via ? 'Sample fever for three days' : null, booked_via: via,
  patient: patient(id, name), doctor, service: null, bills: [],
})
const day = [booking(1, 'Sample Mother', 'app', '10:45'), booking(2, 'Sample Walk In', null, '11:00')]

/** Answers each address the screen asks for; anything not named comes back empty. */
const answer = (answers: Record<string, unknown>) => get.mockImplementation((url: string) => Promise.resolve({ data: url in answers ? answers[url] : [] }))
const show = (page: ReactNode) => render(<MemoryRouter>{page}</MemoryRouter>)
const rowOf = (name: string) => screen.getByText(name).closest('tr') as HTMLElement

afterEach(() => {
  cleanup()
  get.mockReset()
})

describe('a visit booked from the patient app', () => {
  it('is marked under its time on the front desk day list', async () => {
    answer({ '/appointments': { data: day, counts: { booked: 2 } }, '/doctors': [doctor] })
    show(<FrontDesk />)

    await waitFor(() => expect(screen.getByText('Sample Mother')).toBeTruthy())
    const time = within(rowOf('Sample Mother')).getByText('Token 1').closest('td') as HTMLElement
    expect(within(time).getByTitle('Booked from the patient app').textContent).toBe('App')
    expect(within(rowOf('Sample Walk In')).queryByTitle('Booked from the patient app')).toBeNull()
    // Never the note the patient typed.
    expect(screen.queryByText(/Sample fever/)).toBeNull()
  })

  it('is marked on its calendar chip, which the legend explains', async () => {
    answer({
      '/appointments/calendar': { settings: { slot_minutes: 15, slot_limit: 0, morning_start: '10:30', morning_end: '13:30', evening_start: '17:00', evening_end: '20:00' }, blocks: [], data: day },
      '/doctors': [doctor],
    })
    show(<Calendar />)

    await waitFor(() => expect(screen.getByText('Sample Mother')).toBeTruthy())
    const chip = screen.getByText('Sample Mother').closest('button') as HTMLElement
    expect(chip.getAttribute('title')).toBe('10:45 AM · Sample Mother · Booked · booked from the patient app')
    expect(within(chip).getByText('App').getAttribute('title')).toBe('Booked from the patient app')
    const other = screen.getByText('Sample Walk In').closest('button') as HTMLElement
    expect(other.getAttribute('title')).toBe('11:00 AM · Sample Walk In · Booked')
    expect(within(other).queryByText('App')).toBeNull()
    expect(screen.getByText('Booked from the patient app', { selector: '.app-key' })).toBeTruthy()
    expect(chip.getAttribute('title')).not.toContain('Sample fever')
  })

  it('is marked under its time in the doctor’s queue', async () => {
    const entry = (a: Appointment): QueueEntry => ({
      id: a.id, scheduled_at: a.scheduled_at, booked_via: a.booked_via, arrived_at: null, status: a.status, patient: { ...a.patient, blood_group: null, allergies: null },
      doctor, service: null, consultation: null, token_no: a.token_no, vitals_ready: false, visits: 0, last_visit: null,
    })
    answer({ '/doctor/queue': day.map(entry), '/doctors': [doctor] })
    show(<DoctorQueue />)

    await waitFor(() => expect(screen.getByText('Sample Mother')).toBeTruthy())
    const time = within(rowOf('Sample Mother')).getByText('Token 1').closest('td') as HTMLElement
    expect(within(time).getByTitle('Booked from the patient app').textContent).toBe('App')
    expect(within(rowOf('Sample Walk In')).queryByTitle('Booked from the patient app')).toBeNull()
  })
})
