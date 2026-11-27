import { useEffect, useState } from 'react'

/**
 * Putting the app on a phone's home screen. A page open in the browser always shows the browser's address bar; only
 * the app opened from its own icon is full screen. Android's browser says when it is ready to install and lets the
 * page ask with one tap; an iPhone has no such way, so there the person is told the two taps to make.
 */
type InstallEvent = Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }> }

let offer: InstallEvent | null = null
const CHANGED = 'hms:install'

// Listened for from the first moment: the browser may say it is ready before any screen is drawn.
window.addEventListener('beforeinstallprompt', (e) => {
  // The browser's own small strip is held back; the app shows its own, in its own words.
  e.preventDefault()
  offer = e as InstallEvent
  window.dispatchEvent(new Event(CHANGED))
})
window.addEventListener('appinstalled', () => {
  offer = null
  window.dispatchEvent(new Event(CHANGED))
})

/** Whether this is the installed app, opened from its icon, and not a page in the browser. */
export const runningInstalled = () => window.matchMedia('(display-mode: standalone)').matches || (navigator as { standalone?: boolean }).standalone === true

export const onIphone = () => /iphone|ipad|ipod/i.test(navigator.userAgent)

/** Where "Later" on the offer to install is remembered, and for how long it holds. */
export const INSTALL_LATER = 'hms_install_later'
const WEEK = 7 * 24 * 60 * 60 * 1000

/** Whether the person said "Later" to installing within the last week. */
export const installPutOff = () => {
  try {
    return Date.now() - Number(localStorage.getItem(INSTALL_LATER) ?? 0) < WEEK
  } catch {
    return false
  }
}

/** `ready`: the browser will install at one tap. `install()` asks it to, and says whether the person agreed. */
export function useInstall(): { ready: boolean; install: () => Promise<boolean> } {
  const [ready, setReady] = useState(offer !== null)
  useEffect(() => {
    const changed = () => setReady(offer !== null)
    window.addEventListener(CHANGED, changed)
    return () => window.removeEventListener(CHANGED, changed)
  }, [])
  const install = async () => {
    const asked = offer
    if (!asked) return false
    await asked.prompt()
    const { outcome } = await asked.userChoice
    // The browser's offer can be used once.
    offer = null
    setReady(false)
    return outcome === 'accepted'
  }
  return { ready, install }
}
