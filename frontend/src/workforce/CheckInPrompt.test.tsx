import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { CheckInPrompt } from './CheckInPrompt'

const get = vi.fn()
const post = vi.fn()
let userId = 1
vi.mock('../api', () => ({ api: { get: (...args: unknown[]) => get(...args), post: (...args: unknown[]) => post(...args) }, fieldErrors: (err: { message?: string }) => ({ _: err.message ?? '' }) }))
vi.mock('../auth', () => ({ useAuth: () => ({ user: { id: userId, name: 'Dr. Asha Sample', abilities: { 'wf:self:view': true } } }) }))

const day = (over: Record<string, unknown> = {}) => ({ data: { employee: { id: 5, name: 'Asha Sample' }, self_check_in: true, here: true, open: null, done: [], ...over } })
const spell = { id: 9, employee_id: 5, work_date: '2026-11-25', check_in: '2026-11-25 09:05', check_out: null, state: 'open' }

beforeEach(() => {
  // A different person each time, so one test's answer is never the next one's.
  userId += 1
  sessionStorage.clear()
})
afterEach(() => {
  cleanup()
  get.mockReset()
  post.mockReset()
})

describe('the check-in call-out on opening the app', () => {
  it('asks someone who has not checked in, by name, and checks them in at one press', async () => {
    get.mockResolvedValueOnce(day()).mockResolvedValue(day({ open: spell }))
    post.mockResolvedValue({ data: spell })
    render(<CheckInPrompt />)

    await screen.findByText(/, Asha$/)
    fireEvent.click(screen.getByRole('button', { name: 'Check in now' }))
    await screen.findByText('You are checked in')
    expect(post).toHaveBeenCalledWith('/workforce/me/punch')
    expect(screen.getByText('Marked as in the hospital at 9:05 AM.')).toBeTruthy()
  })

  it('is put away by “Not now” and does not come back while the app stays open', async () => {
    get.mockResolvedValue(day())
    const first = render(<CheckInPrompt />)
    fireEvent.click(await screen.findByRole('button', { name: 'Not now' }))
    expect(screen.queryByRole('dialog')).toBeNull()
    first.unmount()

    render(<CheckInPrompt />)
    await waitFor(() => expect(get).toHaveBeenCalledTimes(2))
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(post).not.toHaveBeenCalled()
  })

  it('does not ask someone already in, done for the day, with no staff record, or where it is switched off', async () => {
    for (const answer of [day({ open: spell }), day({ done: [{ ...spell, check_out: '2026-11-25 17:00', state: 'completed' }] }), { data: { employee: null } }, day({ self_check_in: false })]) {
      userId += 1
      get.mockResolvedValue(answer)
      const shown = render(<CheckInPrompt />)
      await waitFor(() => expect(get).toHaveBeenCalled())
      await new Promise((resolve) => setTimeout(resolve, 0))
      expect(screen.queryByRole('dialog')).toBeNull()
      shown.unmount()
      get.mockReset()
    }
  })

  it('says to join the hospital Wi-Fi when check-in is kept to it, and says what went wrong when the server refuses', async () => {
    get.mockResolvedValueOnce(day({ here: false })).mockResolvedValue(day())
    post.mockRejectedValue(new Error('Marking your own attendance is not switched on.'))
    render(<CheckInPrompt />)

    await screen.findByText(/Connect your phone to the hospital Wi-Fi first/)
    expect(screen.queryByRole('button', { name: 'Check in now' })).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: /try again/ }))
    fireEvent.click(await screen.findByRole('button', { name: 'Check in now' }))
    await screen.findByText('Marking your own attendance is not switched on.')
    expect(screen.getByRole('dialog')).toBeTruthy()
  })
})
