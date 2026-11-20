import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { WorkforceOverview } from './Overview'

const get = vi.fn()
vi.mock('../api', () => ({ api: { get: (...args: unknown[]) => get(...args) }, fieldErrors: () => ({}) }))
vi.mock('../auth', () => ({ useAuth: () => ({ user: { abilities: { 'wf:reports:view': true, 'wf:attendance:view': true, 'wf:self:view': true } } }) }))

const person = (id: number, name: string) => ({ id, employee_no: `E${id}`, name, designation: 'Staff Nurse', status: 'active', department: { id: 1, name: 'Nursing' }, late_minutes: 0, leave_type: null })
const board = (over: Record<string, unknown> = {}) => ({
  date: new Date().toLocaleDateString('en-CA'), is_today: true, staff: 3, in: [person(1, 'Asha Sample')], not_in: [person(2, 'Bhanu Sample')], on_leave: [], late: [], off: 1,
  missing_check_outs: [], corrections_waiting: 0, leave_waiting: 0, ...over,
})
const show = () =>
  render(
    <MemoryRouter initialEntries={['/workforce']}>
      <WorkforceOverview />
    </MemoryRouter>,
  )

afterEach(() => {
  cleanup()
  get.mockReset()
})

describe('the day overview', () => {
  it('says it is loading, and shows no figure at all, until the day arrives', async () => {
    let arrive: (value: unknown) => void = () => undefined
    get.mockReturnValue(new Promise((resolve) => (arrive = resolve)))
    show()

    expect(screen.getByText('Loading the day…')).toBeTruthy()
    // No count, no "nobody is on leave", nothing that could be read as an answer.
    expect(screen.queryByText('In')).toBeNull()
    expect(screen.queryByText('Nobody is on leave.')).toBeNull()
    expect(document.querySelectorAll('.tile').length).toBe(0)

    arrive({ data: board() })
    await waitFor(() => expect(screen.queryByText('Loading the day…')).toBeNull())
    expect(screen.getAllByText('Asha Sample').length).toBe(1)
    expect(screen.getByText('of 3 staff')).toBeTruthy()
  })

  it('says so plainly when the day could not be loaded, and shows no figures', async () => {
    get.mockRejectedValue(new Error('offline'))
    show()

    await waitFor(() => expect(screen.getByText('Could not load the day. Check the connection and try again.')).toBeTruthy())
    expect(screen.queryByText('Loading the day…')).toBeNull()
    expect(document.querySelectorAll('.tile').length).toBe(0)
  })

  it('shows honest empty lists only once the answer is in', async () => {
    get.mockResolvedValue({ data: board({ in: [], not_in: [], staff: 0, off: 0 }) })
    show()

    await waitFor(() => expect(screen.getByText('Everyone expected is in.')).toBeTruthy())
    expect(screen.getByText('Nobody is checked in right now.')).toBeTruthy()
    expect(screen.getByText('Nobody is on leave.')).toBeTruthy()
  })

  it('points at what is waiting for a decision', async () => {
    get.mockResolvedValue({ data: board({ leave_waiting: 2, corrections_waiting: 1, missing_check_outs: [{ id: 9, employee: { id: 2, name: 'Bhanu Sample' }, work_date: '2026-03-09', check_in: '2026-03-09 09:00' }] }) })
    show()

    await waitFor(() => expect(screen.getByText(/leave requests are waiting for a decision/)).toBeTruthy())
    expect(screen.getByText(/attendance correction is waiting for a decision/)).toBeTruthy()
    expect(screen.getByText(/never checked out/)).toBeTruthy()
    expect(get).toHaveBeenCalledWith('/workforce/dashboard', expect.objectContaining({ params: expect.objectContaining({ date: expect.any(String) }) }))
  })
})
