import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Appointment } from '../types'
import { EditAppointmentPanel } from './FrontDeskPanels'

vi.mock('../api', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }, fieldErrors: () => ({}) }))
vi.mock('../auth', () => ({ useAuth: () => ({ user: { can_front_desk: true } }) }))

const visit = (over: Partial<Appointment> = {}): Appointment => ({
  id: 31, scheduled_at: '2026-11-24 10:45', duration_minutes: 15, status: 'booked', token_no: 4, notes: 'Sample fever for three days', booked_via: 'app',
  patient: { id: 1, uhid: 'PH000001', name: 'Sample Mother', gender: 'female', age: 56, age_label: '56y', title: null, mobile: '9000000001' },
  doctor: { id: 2, name: 'Dr Sample Rao' }, service: null, bills: [],
  ...over,
})
const edit = (appointment: Appointment) =>
  render(<EditAppointmentPanel appointment={appointment} doctors={[{ id: 2, name: 'Dr Sample Rao', department: null }]} services={[]} onClose={() => undefined} onSaved={() => undefined} />)

afterEach(cleanup)

describe('editing an appointment', () => {
  it('says in its heading that the patient booked it on the app, and that the patient wrote the note and reads it', () => {
    edit(visit())
    const heading = screen.getByText(/^Sample Mother · token/)
    expect(heading.textContent).toBe('Sample Mother · token 4 App')
    expect(heading.querySelector('[title="Booked from the patient app"]')).not.toBeNull()
    expect((screen.getByLabelText('Notes') as HTMLInputElement).value).toBe('Sample fever for three days')
    expect(screen.getByText('The patient typed this note in the app, and sees what is written here.')).toBeTruthy()
  })

  it('still warns that the patient reads the note when they wrote none', () => {
    edit(visit({ notes: null }))
    expect(screen.getByText('The patient sees this note in the app.')).toBeTruthy()
  })

  it('says nothing of the app for a visit booked at the hospital', () => {
    edit(visit({ booked_via: null, notes: 'Sample desk note' }))
    expect(screen.getByText(/^Sample Mother · token/).textContent).toBe('Sample Mother · token 4 ')
    expect(screen.queryByTitle('Booked from the patient app')).toBeNull()
    expect(screen.queryByText(/sees what is written here|sees this note in the app/)).toBeNull()
  })
})
