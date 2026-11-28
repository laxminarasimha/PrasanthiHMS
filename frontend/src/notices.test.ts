import { act, cleanup, renderHook } from '@testing-library/react'
import { createElement, type ReactNode } from 'react'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ARRIVED, TAPPED, getsNotices, inWords, noticesFor, ownPage, useNotice, useOpenFromNotice } from './notices'
import { setUnsaved } from './unsaved'
import type { User } from './types'

const person = (over: Partial<User> = {}) => ({ id: 7, name: 'Sample Nurse', role: 'nurse', ...over }) as User
const arrive = (kind: string) => window.dispatchEvent(new CustomEvent(ARRIVED, { detail: kind }))
const tap = (url: unknown) => window.dispatchEvent(new CustomEvent(TAPPED, { detail: url }))

afterEach(() => {
  cleanup()
  setUnsaved(null)
  vi.useRealTimers()
})

describe('who is offered notices', () => {
  it('follows what the server says this person can be sent', () => {
    expect(getsNotices(person({ notices: ['task', 'leave'] }))).toBe(true)
    expect(getsNotices(person({ role: 'doctor', notices: [] }))).toBe(false)
    expect(getsNotices(null)).toBe(false)
  })

  it('counts a doctor in, and nobody else, while the details on the device are from before the server listed them', () => {
    expect(getsNotices(person({ role: 'doctor' }))).toBe(true)
    expect(noticesFor(person({ role: 'doctor' }))).toEqual(['new appointments and patients arriving', 'lab results ready to see', 'medical representatives waiting', 'tasks given to you'])
    expect(getsNotices(person({ role: 'frontdesk' }))).toBe(false)
  })

  it('says each kind in words, leaving out the test notice and any kind it does not know', () => {
    expect(noticesFor(person({ notices: ['leave', 'test', 'something_new', 'expense', 'stock_count', 'summary'] }))).toEqual([
      'your leave being decided',
      'your expenses being approved or turned down',
      'stock counts handed in for approval',
      'discharge summaries ready for your approval',
    ])
    expect(inWords(['tasks given to you'])).toBe('tasks given to you')
    expect(inWords(['a', 'b'])).toBe('a and b')
    expect(inWords(['a', 'b', 'c'])).toBe('a, b and c')
    expect(inWords([])).toBe('')
  })
})

describe('a screen loading again when a notice arrives', () => {
  it('loads once for its own kinds, gathering those that come together, and not for others', () => {
    vi.useFakeTimers()
    const load = vi.fn()
    renderHook(() => useNotice(['appointment', 'lab', 'rep'], load))

    arrive('task')
    arrive('leave')
    vi.advanceTimersByTime(1000)
    expect(load).not.toHaveBeenCalled()

    arrive('appointment')
    arrive('lab')
    vi.advanceTimersByTime(1000)
    expect(load).toHaveBeenCalledTimes(1)

    arrive('rep')
    vi.advanceTimersByTime(1000)
    expect(load).toHaveBeenCalledTimes(2)
  })

  it('loads for any kind when asked to, and stops listening when the screen closes', () => {
    vi.useFakeTimers()
    const load = vi.fn()
    const screen = renderHook(() => useNotice('any', load))
    arrive('expense')
    vi.advanceTimersByTime(1000)
    expect(load).toHaveBeenCalledTimes(1)

    screen.unmount()
    arrive('task')
    vi.advanceTimersByTime(1000)
    expect(load).toHaveBeenCalledTimes(1)
  })
})

describe('a notice tapped while the app is open', () => {
  const inRouter = ({ children }: { children: ReactNode }) => createElement(MemoryRouter, { initialEntries: ['/doctor'] }, children)
  const openApp = () =>
    renderHook(
      () => {
        useOpenFromNotice()
        return useLocation()
      },
      { wrapper: inRouter },
    )

  it('goes to the page it is about', () => {
    const app = openApp()
    act(() => tap('/ipd/12?tab=summary'))
    expect(app.result.current.pathname + app.result.current.search).toBe('/ipd/12?tab=summary')
  })

  it('never goes anywhere outside the app', () => {
    const app = openApp()
    for (const url of ['//elsewhere.example/x', '/\\elsewhere.example', 'https://elsewhere.example/', 'todo', 42, null]) act(() => tap(url))
    expect(app.result.current.pathname).toBe('/doctor')
    expect(ownPage('/todo')).toBe(true)
    expect(ownPage('//elsewhere.example')).toBe(false)
  })

  it('asks first when something typed is not saved, and staying keeps the person where they are', () => {
    const app = openApp()
    setUnsaved('The visit notes are not saved yet. Leave without saving them?')
    const ask = vi.spyOn(window, 'confirm').mockReturnValue(false)
    act(() => tap('/todo'))
    expect(ask).toHaveBeenCalledTimes(1)
    expect(app.result.current.pathname).toBe('/doctor')

    ask.mockReturnValue(true)
    act(() => tap('/todo'))
    expect(app.result.current.pathname).toBe('/todo')
  })
})
