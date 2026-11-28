/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const SCRIPT = readFileSync(join(__dirname, '..', 'public', 'boot-check.js'), 'utf8')

// A real page runs the check once; here each test runs it afresh, so what one test's check listens for is taken
// away again before the next.
const added: [EventTarget, string, EventListenerOrEventListenerObject, boolean | AddEventListenerOptions | undefined][] = []

function runCheck() {
  for (const target of [window, document] as EventTarget[]) {
    const own = target.addEventListener.bind(target)
    vi.spyOn(target, 'addEventListener').mockImplementation((type, listener, options) => {
      if (listener) added.push([target, type, listener, options])
      own(type, listener, options)
    })
  }
  // As the browser would: a plain script, run once, before the app.
  new Function(SCRIPT)()
}

beforeEach(() => {
  vi.useFakeTimers()
  document.body.innerHTML = '<div id="root"></div>'
  // jsdom does not say whether it can run the app's kind of script; a real phone's browser does.
  if (!('noModule' in HTMLScriptElement.prototype)) Object.defineProperty(HTMLScriptElement.prototype, 'noModule', { value: false, configurable: true })
})

afterEach(() => {
  for (const [target, type, listener, options] of added.splice(0)) target.removeEventListener(type, listener, options)
  vi.useRealTimers()
  document.body.innerHTML = ''
})

describe('the check before the app starts', () => {
  it('tells a phone whose Chrome cannot read the app to update Chrome, instead of a blank page', () => {
    runCheck()
    window.dispatchEvent(new ErrorEvent('error', { message: "Uncaught SyntaxError: Unexpected token '?'" }))
    vi.advanceTimersByTime(2000)
    const alert = document.querySelector('[role="alert"]')
    expect(alert?.textContent).toContain('could not start on this phone')
    expect(alert?.textContent).toContain('update Google Chrome')
    expect(alert?.textContent).toContain('Unexpected token')
  })

  it('does not blame the age of a new Chrome', () => {
    vi.spyOn(navigator, 'userAgent', 'get').mockReturnValue('Mozilla/5.0 (Linux; Android 13; V2111) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36')
    runCheck()
    window.dispatchEvent(new ErrorEvent('error', { message: "Uncaught SyntaxError: Unexpected token '?'" }))
    vi.advanceTimersByTime(2000)
    const alert = document.querySelector('[role="alert"]')
    expect(alert?.textContent).toContain('Something stopped the app from starting')
    expect(alert?.textContent).toContain('Chrome 120 · Android 13')
  })

  it('tells an old Chrome to update even before anything fails', () => {
    vi.spyOn(navigator, 'userAgent', 'get').mockReturnValue('Mozilla/5.0 (Linux; Android 8.1.0; vivo 1820) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/62.0.3202.84 Mobile Safari/537.36')
    runCheck()
    window.dispatchEvent(new Event('load'))
    vi.advanceTimersByTime(5000)
    expect(document.querySelector('[role="alert"]')?.textContent).toContain('update Google Chrome')
  })

  it('says something stopped the start, with a way to reset, when the page stays empty after loading', () => {
    runCheck()
    window.dispatchEvent(new Event('load'))
    vi.advanceTimersByTime(5000)
    const alert = document.querySelector('[role="alert"]')
    expect(alert?.textContent).toContain('Something stopped the app from starting')
    expect(alert?.textContent).toContain('Reset the app on this phone')
  })

  it('stays quiet when the app has drawn its first screen', () => {
    runCheck()
    document.getElementById('root')!.innerHTML = '<p>Sign in</p>'
    window.dispatchEvent(new Event('load'))
    window.dispatchEvent(new ErrorEvent('error', { message: 'Some later problem' }))
    vi.advanceTimersByTime(10000)
    expect(document.querySelector('[role="alert"]')).toBeNull()
  })
})
