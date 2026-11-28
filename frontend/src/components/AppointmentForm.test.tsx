import { cleanup, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AppointmentForm } from './AppointmentForm'

// The hospital's billing choices are not needed here, so their answer never comes.
vi.mock('../api', () => ({ api: { get: () => new Promise(() => undefined), post: vi.fn(), put: vi.fn(), delete: vi.fn() }, fieldErrors: () => ({}) }))
vi.mock('../auth', () => ({ useAuth: () => ({ user: { role: 'frontdesk', can_front_desk: true } }) }))

afterEach(cleanup)

describe('booking an appointment', () => {
  it('says under the notes that the patient may read them in the patient app', () => {
    render(
      <MemoryRouter>
        <AppointmentForm date="2026-11-24" doctors={[{ id: 2, name: 'Dr Sample Rao', department: null }]} services={[]} onClose={() => undefined} onBooked={() => undefined} />
      </MemoryRouter>,
    )
    const help = screen.getByText('This note shows in the patient app, to the patient and to any family member who has added them there.')
    // Right under the notes box, whoever the patient is and however the visit is booked.
    expect(screen.getByLabelText('Notes (optional)').closest('label')?.nextElementSibling).toBe(help)
  })
})
