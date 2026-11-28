import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api, getToken } from './api'

// A pretend browser on which notifications were switched on: the app's background worker and the browser's
// arrangement with its notification service. What happens is written down in order.
const steps: string[] = []
const device = { sub: null as null | { endpoint: string; unsubscribe: () => Promise<boolean> } }
const reg = { pushManager: { getSubscription: async () => device.sub } }

/** Every request the app sends, all refused by the server with this answer. */
const asked: string[] = []
const refusing =
  (status: number) =>
  async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
    asked.push(`${config.method} ${config.url}`)
    throw new AxiosError('Refused', AxiosError.ERR_BAD_REQUEST, config, null, { status, statusText: '', data: { message: 'Unauthenticated.' }, headers: {}, config })
  }
const usual = api.defaults.adapter

beforeEach(() => {
  steps.length = 0
  asked.length = 0
  localStorage.setItem('hms_token', 'sample-token')
  device.sub = {
    endpoint: 'https://fcm.googleapis.com/fcm/send/sample-device',
    unsubscribe: async () => {
      steps.push('notifications ended on this device')
      device.sub = null
      return true
    },
  }
  Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: { getRegistration: async () => reg, ready: Promise.resolve(reg), addEventListener: () => undefined } })
  for (const where of [globalThis, window] as Record<string, unknown>[]) {
    where.PushManager = class {}
    where.Notification = { permission: 'granted' }
  }
  vi.stubGlobal('location', { ...window.location, assign: vi.fn((url: string) => steps.push(`went to ${url}`)) })
})
afterEach(() => {
  api.defaults.adapter = usual
  vi.unstubAllGlobals()
  localStorage.clear()
})

describe('being signed out from elsewhere, or after a long time unused', () => {
  it('stops this device showing the person’s notifications before the sign-in page opens, without asking the server', async () => {
    api.defaults.adapter = refusing(401)
    await expect(api.get('/todo/count')).rejects.toBeTruthy()
    await vi.waitFor(() => expect(steps).toEqual(['notifications ended on this device', 'went to /login']))
    // Only the request that was refused: nothing was sent to the server about the device.
    expect(asked).toEqual(['get /todo/count'])
    expect(getToken()).toBeNull()
  })

  it('goes to the sign-in page all the same on a browser that never had notifications', async () => {
    device.sub = null
    api.defaults.adapter = refusing(401)
    await expect(api.get('/me')).rejects.toBeTruthy()
    await vi.waitFor(() => expect(steps).toEqual(['went to /login']))
  })

  it('leaves the device alone when the server only says one thing is not allowed', async () => {
    api.defaults.adapter = refusing(403)
    await expect(api.get('/reports')).rejects.toBeTruthy()
    await new Promise((done) => setTimeout(done, 10))
    expect(steps).toEqual([])
    expect(device.sub).not.toBeNull()
    expect(getToken()).toBe('sample-token')
  })
})
