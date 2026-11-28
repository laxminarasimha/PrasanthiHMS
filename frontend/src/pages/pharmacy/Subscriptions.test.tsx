import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { PharmacySubscriptions } from './Subscriptions'

const get = vi.fn()
vi.mock('../../api', () => ({
  api: { get: (...args: unknown[]) => get(...args), post: vi.fn() },
  fieldErrors: () => ({}),
}))
// Who is looking: changed by each test before the page is drawn.
let user: Record<string, unknown> = {}
vi.mock('../../auth', () => ({ useAuth: () => ({ user }) }))

const listing = {
  subscriptions: [
    {
      id: 3, title: 'Sample sugar medicines', items: [{ medicine_id: null, name: 'Sample tablet', quantity: 30 }], day_of_month: 23, status: 'active', next_due_on: '2026-11-23',
      last_prepared_on: null, notes: null, patient: { id: 1, uhid: 'PH000001', name: 'Sample Patient', mobile: '9000000001', age_label: '56y', gender: 'female' }, preparer: null,
    },
  ],
  counts: { due: 1, active: 1, paused: 0 },
}
const show = () =>
  render(
    <MemoryRouter>
      <PharmacySubscriptions />
    </MemoryRouter>,
  )

afterEach(() => {
  cleanup()
  get.mockReset()
})

describe('the monthly medicines screen', () => {
  it('lets someone who only looks at the pharmacy read the lists, without the button to mark a pack made', async () => {
    user = { can_view_pharmacy: true, can_run_pharmacy: false }
    get.mockResolvedValue({ data: listing })
    show()

    await waitFor(() => expect(screen.getByText('Sample sugar medicines')).toBeTruthy())
    expect(screen.queryByText('Pack made')).toBeNull()
  })

  it('gives the pharmacy the button to mark the pack made', async () => {
    user = { can_view_pharmacy: true, can_run_pharmacy: true }
    get.mockResolvedValue({ data: listing })
    show()

    await waitFor(() => expect(screen.getByText('Pack made')).toBeTruthy())
  })
})
