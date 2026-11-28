import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { pushState, syncPush, turnOffPush, turnOnPush } from './push'
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

const person = (id: number, over: Partial<User> = {}) => ({ id, name: `Sample Person ${id}`, role: 'nurse', notices: ['task', 'leave'], ...over }) as User
const nurse = person(11)
const otherNurse = person(12)
/** A doctor whose details on the device are from before the server listed what each person is sent. */
const doctorFromBefore = person(21, { role: 'doctor', notices: undefined })

const joined = () => post.mock.calls.filter(([url]) => url === '/me/push').length
const released = () => post.mock.calls.filter(([url]) => url === '/me/push/release').map(([, body]) => body)
const agreedHere = () => JSON.parse(localStorage.getItem('hms_push_people') ?? 'null')

beforeEach(() => {
  localStorage.clear()
  phone.device.permission = 'granted'
  phone.device.sub = null
  get.mockResolvedValue({ data: { key: phone.key } })
  post.mockResolvedValue({ data: {} })
  remove.mockResolvedValue({ data: null })
})
afterEach(() => {
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

  it('lets go of the device for a login the hospital never notifies, and never notes it', async () => {
    phone.device.sub = phone.subscription()
    localStorage.setItem('hms_push_people', JSON.stringify([31]))
    await syncPush(person(31, { role: 'staff', notices: [] }))
    expect(joined()).toBe(0)
    expect(released()).toHaveLength(1)
  })

  it('asks nothing and sends nothing on a device that was never switched on', async () => {
    phone.device.permission = 'default'
    await syncPush(nurse)
    expect(post).not.toHaveBeenCalled()
    expect(get).not.toHaveBeenCalled()
  })
})

describe('devices that were switched on before each person was asked for themselves', () => {
  it('keeps a doctor’s notifications on without asking again', async () => {
    phone.device.sub = phone.subscription()
    expect(await pushState(doctorFromBefore)).toBe('on')
    await syncPush(doctorFromBefore)
    expect(joined()).toBe(1)
    expect(agreedHere()).toEqual([doctorFromBefore.id])
  })

  it('does not hand such a device to anyone else who signs in on it', async () => {
    phone.device.sub = phone.subscription()
    expect(await pushState(nurse)).toBe('off')
    await syncPush(nurse)
    expect(joined()).toBe(0)
    expect(released()).toHaveLength(1)
    // Nothing is decided for the doctor who switched it on: they are carried over when they next open the app.
    expect(agreedHere()).toBeNull()
  })

  it('leaves it off where the doctor had switched it off', async () => {
    phone.device.sub = phone.subscription()
    localStorage.setItem('hms_push_off', '1')
    await syncPush(doctorFromBefore)
    expect(joined()).toBe(0)
    expect(released()).toHaveLength(1)
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
