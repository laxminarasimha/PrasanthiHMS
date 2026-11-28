import { cleanup, render, screen } from '@testing-library/react'
import { createElement } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { pushState, syncPush, turnOffPush, turnOnPush } from './push'
import { PushHint } from './components/PushControls'
import { ARRIVED, TAPPED } from './notices'
import type { User } from './types'

// A pretend browser that can be notified: its notification service, the app's background worker and the permission.
const phone = vi.hoisted(() => {
  const keyBytes = new Uint8Array(65).fill(4)
  const key = btoa(String.fromCharCode(...keyBytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
  const heard: ((event: { data: unknown }) => void)[] = []
  let made = 0
  const device = {
    permission: 'granted' as NotificationPermission,
    sub: null as null | { endpoint: string; options: { applicationServerKey: ArrayBuffer }; toJSON: () => object; unsubscribe: () => Promise<boolean> },
  }
  const subscription = () => {
    made += 1
    const sub = {
      endpoint: `https://fcm.googleapis.com/fcm/send/sample-device-${made}`,
      options: { applicationServerKey: keyBytes.buffer },
      toJSON: () => ({ endpoint: sub.endpoint, keys: { p256dh: 'sample-key', auth: 'sample-secret' } }),
      unsubscribe: async () => {
        device.sub = null
        return true
      },
    }
    return sub
  }
  const reg = {
    pushManager: {
      getSubscription: async () => device.sub,
      subscribe: async () => (device.sub = subscription()),
    },
  }
  Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: { ready: Promise.resolve(reg), addEventListener: (_type: string, fn: (event: { data: unknown }) => void) => heard.push(fn) } })
  const permission = { get permission() { return device.permission }, requestPermission: async () => device.permission }
  for (const where of [globalThis, window] as Record<string, unknown>[]) {
    where.PushManager = class {}
    where.Notification = permission
  }
  return { key, device, subscription, heard }
})

const get = vi.fn()
const post = vi.fn()
const remove = vi.fn()
vi.mock('./api', () => ({ api: { get: (...args: unknown[]) => get(...args), post: (...args: unknown[]) => post(...args), delete: (...args: unknown[]) => remove(...args) } }))
// For the strip on the first screen: who is signed in, on a computer rather than a phone.
let signedIn: User | null = null
vi.mock('./auth', () => ({ useAuth: () => ({ user: signedIn }) }))
vi.mock('./phone', () => ({ usePhone: () => false }))

const person = (id: number, over: Partial<User> = {}) => ({ id, name: `Sample Person ${id}`, role: 'nurse', notices: ['task', 'leave'], ...over }) as User
const nurse = person(11)
const otherNurse = person(12)
/** A doctor whose details on the device are from before the server listed what each person is sent. */
const doctorFromBefore = person(21, { role: 'doctor', notices: undefined })
const otherDoctor = person(22, { role: 'doctor', notices: ['appointment', 'lab', 'task'] })
/** A login that opens only the person's own record. The server never lets it ask anything about notifications. */
const ownRecord = person(31, { role: 'staff', notices: [] })

const joined = () => post.mock.calls.filter(([url]) => url === '/me/push').length
const released = () => post.mock.calls.filter(([url]) => url === '/me/push/release').map(([, body]) => body)
const askedHeld = () => post.mock.calls.filter(([url]) => url === '/me/push/held').map(([, body]) => body)
const agreedHere = () => JSON.parse(localStorage.getItem('hms_push_people') ?? 'null')
/** The server's answers: whether it has this browser noted as the signed-in person's, and whether it lets go of the device. */
const server = ({ holds = false, releases = true }: { holds?: boolean; releases?: boolean }) =>
  post.mockImplementation((url: string) => {
    if (url === '/me/push/held') return Promise.resolve({ data: { held: holds } })
    if (url === '/me/push/release' && !releases) return Promise.reject({ response: { status: 403, data: { message: 'Your login opens only your own record.' } } })
    return Promise.resolve({ data: {} })
  })

beforeEach(() => {
  localStorage.clear()
  phone.device.permission = 'granted'
  phone.device.sub = null
  signedIn = null
  get.mockResolvedValue({ data: { key: phone.key } })
  post.mockResolvedValue({ data: {} })
  remove.mockResolvedValue({ data: null })
})
afterEach(() => {
  cleanup()
  get.mockReset()
  post.mockReset()
  remove.mockReset()
})

describe('notifications on a device used by more than one person', () => {
  it('notes the device again for someone who switched them on here, each time they open the app', async () => {
    expect(await turnOnPush(nurse)).toBe('on')
    expect(joined()).toBe(1)
    expect(agreedHere()).toEqual([nurse.id])

    await syncPush(nurse)
    expect(joined()).toBe(2)
    expect(await pushState(nurse)).toBe('on')
  })

  it('does not sign up the next person to use it, and stops the last person’s notifications coming here', async () => {
    await turnOnPush(nurse)
    const device = phone.device.sub!
    post.mockClear()

    await syncPush(otherNurse)
    expect(joined()).toBe(0)
    expect(released()).toEqual([{ endpoint: device.endpoint }])
    // The device keeps its arrangement, for when the nurse who switched it on signs in again.
    expect(phone.device.sub).toBe(device)
    expect(await pushState(otherNurse)).toBe('off')

    await syncPush(nurse)
    expect(joined()).toBe(1)
  })

  it('switches off for that person only; another who switched on here keeps theirs', async () => {
    await turnOnPush(nurse)
    await turnOnPush(otherNurse)
    await turnOffPush(nurse)
    expect(remove).toHaveBeenCalledTimes(1)
    expect(agreedHere()).toEqual([otherNurse.id])
    post.mockClear()

    await syncPush(nurse)
    expect(joined()).toBe(0)
    await syncPush(otherNurse)
    expect(joined()).toBe(1)
  })

  it('lets go of the device on the device itself for an own-record login, which the server refuses, and never notes it', async () => {
    await turnOnPush(nurse)
    const device = phone.device.sub!
    server({ releases: false })
    post.mockClear()

    await syncPush(ownRecord)
    // Asking the server to let go would only be refused, so it is not asked; the nurse's notices stop showing here all the same.
    expect(post).not.toHaveBeenCalled()
    expect(phone.device.sub).toBeNull()
    expect(agreedHere()).toEqual([nurse.id])
    expect(await pushState(ownRecord)).toBe('off')

    // The nurse who switched them on here is joined again when she next signs in, with a new arrangement and no question.
    const asked = vi.spyOn(Notification, 'requestPermission')
    await syncPush(nurse)
    expect(asked).not.toHaveBeenCalled()
    expect(joined()).toBe(1)
    expect(phone.device.sub).not.toBeNull()
    expect(phone.device.sub!.endpoint).not.toBe(device.endpoint)
    expect(await pushState(nurse)).toBe('on')
  })

  it('ends the arrangement on the device as well when the server refuses to let go of it, or does not answer', async () => {
    for (const answer of [{ response: { status: 403 } }, new Error('Network Error')]) {
      await turnOnPush(nurse)
      post.mockClear()
      post.mockImplementation((url: string) => (url === '/me/push/release' ? Promise.reject(answer) : Promise.resolve({ data: {} })))
      await syncPush(person(13, { role: 'frontdesk', notices: [] }))
      expect(released()).toHaveLength(1)
      expect(joined()).toBe(0)
      expect(phone.device.sub).toBeNull()
    }
  })

  it('asks nothing and sends nothing on a device that was never switched on', async () => {
    phone.device.permission = 'default'
    await syncPush(nurse)
    expect(post).not.toHaveBeenCalled()
    expect(get).not.toHaveBeenCalled()
  })
})

describe('devices that were switched on before each person was asked for themselves', () => {
  it('keeps a doctor’s notifications on without asking again, once the server confirms the device is theirs', async () => {
    const device = (phone.device.sub = phone.subscription())
    server({ holds: true })
    expect(await pushState(doctorFromBefore)).toBe('on')
    await syncPush(doctorFromBefore)
    expect(joined()).toBe(1)
    expect(agreedHere()).toEqual([doctorFromBefore.id])
    // Asked once, about this browser only.
    expect(askedHeld()).toEqual([{ endpoint: device.endpoint }])
  })

  it('does not carry over another doctor who signs in on such a device', async () => {
    const device = (phone.device.sub = phone.subscription())
    server({ holds: false })
    await syncPush(otherDoctor)
    expect(askedHeld()).toEqual([{ endpoint: device.endpoint }])
    expect(joined()).toBe(0)
    expect(released()).toEqual([{ endpoint: device.endpoint }])
    expect(agreedHere()).toBeNull()
    expect(await pushState(otherDoctor)).toBe('off')
  })

  it('does not hand such a device to anyone else who signs in on it', async () => {
    phone.device.sub = phone.subscription()
    expect(await pushState(nurse)).toBe('off')
    await syncPush(nurse)
    expect(joined()).toBe(0)
    expect(askedHeld()).toHaveLength(0)
    expect(released()).toHaveLength(1)
    // Nothing is written down for the doctor who switched it on. The server has let go of the device, so they are offered
    // notifications again at their next sign-in, as any doctor on a device that is no longer theirs is.
    expect(agreedHere()).toBeNull()
  })

  it('leaves it off where the doctor had switched it off', async () => {
    phone.device.sub = phone.subscription()
    localStorage.setItem('hms_push_off', '1')
    await syncPush(doctorFromBefore)
    expect(joined()).toBe(0)
    expect(askedHeld()).toHaveLength(0)
    expect(released()).toHaveLength(1)
  })

  it('asks a doctor once who had signed out there, which ended the arrangement, instead of carrying them over', async () => {
    signedIn = doctorFromBefore
    await syncPush(doctorFromBefore)
    expect(post).not.toHaveBeenCalled()
    expect(agreedHere()).toBeNull()
    expect(await pushState(doctorFromBefore)).toBe('off')
    // So the strip on their first screen offers notifications; turning them on needs one tap and no question from the browser.
    render(createElement(PushHint))
    expect(await screen.findByText('Get notified on this device')).toBeTruthy()
  })
})

describe('what the background worker passes to the open app', () => {
  it('turns an arriving notice and a tapped one into events the screens listen for', () => {
    const kinds: unknown[] = []
    const pages: unknown[] = []
    const onArrive = (e: Event) => kinds.push((e as CustomEvent).detail)
    const onTap = (e: Event) => pages.push((e as CustomEvent).detail)
    window.addEventListener(ARRIVED, onArrive)
    window.addEventListener(TAPPED, onTap)
    for (const tell of phone.heard) {
      tell({ data: { type: 'hms-push', kind: 'leave' } })
      tell({ data: { type: 'hms-open', url: '/workforce/me' } })
      tell({ data: { type: 'hms-open', url: 5 } })
    }
    window.removeEventListener(ARRIVED, onArrive)
    window.removeEventListener(TAPPED, onTap)
    expect(kinds).toEqual(['leave'])
    expect(pages).toEqual(['/workforce/me'])
  })
})
