import { cleanup, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { Layout } from './Layout'
import type { User } from '../types'
import type { MyToday } from '../workforce/myToday'

let user: User
let mine: MyToday | null = null
const syncPush = vi.fn()
vi.mock('../auth', () => ({ useAuth: () => ({ user, signOut: vi.fn() }) }))
vi.mock('../api', () => ({ onLoadTrouble: () => () => undefined }))
vi.mock('../printPaper', () => ({ keepPrintPrefsFresh: vi.fn() }))
vi.mock('../todoCount', () => ({ useTodoCount: () => 0 }))
vi.mock('../push', () => ({ syncPush: (...args: unknown[]) => syncPush(...args) }))
vi.mock('../workforce/myToday', () => ({ useMyToday: () => mine }))
vi.mock('./PushControls', () => ({ PushHint: () => <p>Offer of notifications</p> }))
vi.mock('./InstallHint', () => ({ InstallHint: () => null }))
vi.mock('./PatientSearch', () => ({ PatientSearch: () => null }))
vi.mock('./BottomBar', () => ({ BottomBar: () => null }))
vi.mock('./Brand', () => ({ BrandMark: () => null }))
vi.mock('../workforce/AttendanceCard', () => ({ AttendanceCard: () => null }))
vi.mock('../workforce/CheckInPrompt', () => ({ CheckInPrompt: () => null }))

const nurse = (over: Partial<User> = {}) => ({ id: 4, name: 'Sample Nurse', role: 'nurse', role_label: 'Nurse', branch: null, can_front_desk: true, notices: ['task'], abilities: { 'wf:self:view': true }, ...over }) as User
const day = (over: Partial<MyToday> = {}): MyToday => ({ employee: { id: 8, name: 'Sample Nurse' }, self_check_in: true, regular: true, here: true, open: null, done: [], ...over })
const firstScreen = () =>
  render(
    <MemoryRouter initialEntries={['/front-desk']}>
      <Routes>
        <Route element={<Layout />}>
          <Route path="/front-desk" element={<p>Front desk</p>} />
        </Route>
      </Routes>
    </MemoryRouter>,
  )
const offered = () => screen.queryByText('Offer of notifications') !== null

afterEach(() => {
  cleanup()
  syncPush.mockReset()
  mine = null
})

describe('the offer of notifications on the first screen', () => {
  it('waits while the person is being asked to check in, and comes once they are in', () => {
    user = nurse()
    mine = day()
    firstScreen()
    expect(offered()).toBe(false)
    cleanup()

    mine = day({ open: { id: 1, employee_id: 8, work_date: '2026-11-28', check_in: '2026-11-28 09:00', check_out: null, state: 'open' } as MyToday['open'] })
    firstScreen()
    expect(offered()).toBe(true)
  })

  it('waits until the day is known, and is not held for someone who is not asked to check in', () => {
    user = nurse()
    mine = null
    firstScreen()
    expect(offered()).toBe(false)
    cleanup()

    mine = day({ regular: false })
    firstScreen()
    expect(offered()).toBe(true)
    cleanup()

    user = nurse({ abilities: {} })
    mine = null
    firstScreen()
    expect(offered()).toBe(true)
  })

  it('is never made to a login the hospital does not notify, and the device is looked at for whoever signs in', () => {
    user = nurse({ notices: [], abilities: {} })
    firstScreen()
    expect(offered()).toBe(false)
    expect(syncPush).toHaveBeenCalledWith(user)
  })
})
