import { afterEach, describe, expect, it, vi } from 'vitest'

const real = Object.getOwnPropertyDescriptor(window, 'localStorage')

afterEach(() => {
  if (real) Object.defineProperty(window, 'localStorage', real)
  vi.resetModules()
})

describe('a browser that stops websites saving data on the phone', () => {
  it('still lets the app start, keeping what it would have saved in memory', async () => {
    Object.defineProperty(window, 'localStorage', {
      configurable: true,
      get() {
        throw new DOMException('The operation is insecure.', 'SecurityError')
      },
    })
    const { storageBlocked } = await import('./storageGuard')
    expect(storageBlocked).toBe(true)
    localStorage.setItem('hms_token', 'sample')
    expect(localStorage.getItem('hms_token')).toBe('sample')
    localStorage.removeItem('hms_token')
    expect(localStorage.getItem('hms_token')).toBeNull()
  })

  it('leaves a browser that allows saving alone', async () => {
    const { storageBlocked } = await import('./storageGuard')
    expect(storageBlocked).toBe(false)
  })
})
