import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { PushHint, PushSettings } from './PushControls'
import type { User } from '../types'

let user: User
const turnOn = vi.fn()
vi.mock('../auth', () => ({ useAuth: () => ({ user }) }))
vi.mock('../phone', () => ({ usePhone: () => false }))
vi.mock('../push', () => ({
  usePush: () => ({ state: 'off', refresh: () => undefined }),
  turnOnPush: (...args: unknown[]) => turnOn(...args),
  turnOffPush: vi.fn(),
  sendTestPush: vi.fn(),
}))

const person = (id: number, notices: string[]) => ({ id, name: `Sample Person ${id}`, role: 'nurse', notices }) as User

beforeEach(() => localStorage.clear())
afterEach(() => {
  cleanup()
  turnOn.mockReset()
})

describe('offering notifications', () => {
  it('says on the first screen what this person will be told of, and where to switch it off', () => {
    user = person(1, ['task', 'leave', 'expense'])
    render(<PushHint />)
    expect(screen.getByText('Get notified on this device')).toBeTruthy()
    expect(screen.getByText(/You will be told of tasks given to you, your leave being decided and your expenses being approved or turned down, even when the app is closed\./)).toBeTruthy()
    expect(screen.getByText(/under My profile\./)).toBeTruthy()
  })

  it('says the same on My profile', () => {
    user = person(2, ['stock_count', 'summary'])
    render(<PushSettings />)
    expect(screen.getByText(/This device can tell you of stock counts handed in for approval and discharge summaries ready for your approval, even with the app closed\./)).toBeTruthy()
    expect(screen.queryByText(/appointment/)).toBeNull()
  })

  it('keeps “Later” for the person who pressed it, not for the next person on the same device', () => {
    user = person(3, ['task'])
    const first = render(<PushHint />)
    fireEvent.click(screen.getByText('Later'))
    expect(screen.queryByText('Get notified on this device')).toBeNull()
    first.unmount()

    render(<PushHint />)
    expect(screen.queryByText('Get notified on this device')).toBeNull()
    cleanup()

    user = person(4, ['task'])
    render(<PushHint />)
    expect(screen.getByText('Get notified on this device')).toBeTruthy()
  })

  it('switches on for the person signed in, and gives the server’s own reason when it refuses', async () => {
    user = person(5, ['leave'])
    turnOn.mockRejectedValue({ response: { data: { message: 'Your login opens only your own record.' } } })
    render(<PushHint />)
    fireEvent.click(screen.getByText('Turn on'))
    await waitFor(() => expect(screen.getByText('Your login opens only your own record.')).toBeTruthy())
    expect(turnOn).toHaveBeenCalledWith(user)
  })
})
