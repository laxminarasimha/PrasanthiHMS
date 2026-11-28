/**
 * Some phones' browsers are set to stop websites saving anything on the phone. Reading the saved sign-in then
 * fails, and without this the app stopped before drawing anything: a blank white page.
 *
 * When the browser refuses, the app keeps what it would have saved in memory instead, for as long as the page is
 * open. Everything works; the person only has to sign in again each time they open the site, and the sign-in
 * page says why and how to change the setting. Imported first, before anything else in the app reads storage.
 */

function usable(kind: 'localStorage' | 'sessionStorage'): boolean {
  try {
    const store = window[kind]
    const key = '__hms_check__'
    store.setItem(key, '1')
    store.removeItem(key)
    return true
  } catch {
    return false
  }
}

/** A stand-in that keeps everything in memory, with the same calls as the browser's own. */
function inMemory(): Storage {
  const items = new Map<string, string>()
  return {
    get length() {
      return items.size
    },
    clear: () => items.clear(),
    getItem: (key: string) => (items.has(key) ? (items.get(key) as string) : null),
    key: (index: number) => Array.from(items.keys())[index] ?? null,
    removeItem: (key: string) => {
      items.delete(key)
    },
    setItem: (key: string, value: string) => {
      items.set(key, String(value))
    },
  }
}

function guard(kind: 'localStorage' | 'sessionStorage'): boolean {
  if (usable(kind)) return false
  try {
    Object.defineProperty(window, kind, { value: inMemory(), configurable: true })
  } catch {
    // Nothing more can be done; the app's own reads are wrapped where it matters most.
  }
  return true
}

/** Whether this browser refuses to let the site keep anything on the phone. */
export const storageBlocked = guard('localStorage')
guard('sessionStorage')
