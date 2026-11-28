import { useState } from 'react'
import type { AxiosError } from 'axios'
import { useAuth } from '../auth'
import { installPutOff, onIphone, runningInstalled } from '../install'
import { inWords, noticesFor } from '../notices'
import { usePhone } from '../phone'
import { sendTestPush, turnOffPush, turnOnPush, usePush, type PushState } from '../push'
import type { User } from '../types'

/** "Later" is kept for each person, so one person putting it off on a shared device does not put it off for the next. */
const laterKey = (id: number) => `hms_push_later:${id}`
const WEEK = 7 * 24 * 60 * 60 * 1000
const putOff = (id: number) => {
  try {
    return Date.now() - Number(localStorage.getItem(laterKey(id)) ?? 0) < WEEK
  } catch {
    return false
  }
}

/** What this person is told of, in words, for the strip and My profile alike. */
const toldOf = (user: User | null) => inWords(noticesFor(user)) || 'news from the hospital'

/** Why they cannot be switched on from here, in the person's own terms. */
function whyNot(state: PushState): string {
  if (state === 'install-first') return 'On an iPhone, notifications work only from the app on your home screen. In Safari, tap the Share button, then “Add to Home Screen”. Open the app from its new icon and switch notifications on there.'
  if (state === 'blocked') {
    return onIphone()
      ? 'Notifications from this app are blocked on this phone. Open the phone’s Settings → Notifications → Prasanthi, allow them, then come back here.'
      : 'Notifications from this app are blocked in this browser. Tap the lock or ⓘ beside the address at the top, set Notifications to “Allow”, then reload this page.'
  }
  return 'This browser cannot show notifications. On a phone, use Chrome (Android) or the app added to the home screen (iPhone).'
}

/** Switching on, with what went wrong said plainly. Shared by the strip on the first screen and the account page. */
function useSwitchOn(user: User | null): { busy: boolean; note: string; setNote: (note: string) => void; switchOn: () => Promise<PushState | null> } {
  const [busy, setBusy] = useState(false)
  const [note, setNote] = useState('')
  const switchOn = async () => {
    if (!user) return null
    setBusy(true)
    setNote('')
    try {
      const now = await turnOnPush(user)
      if (now !== 'on') setNote(now === 'off' ? 'Notifications were not allowed. Press the button again and choose “Allow”.' : whyNot(now))
      return now
    } catch (err) {
      // The server's own words when it refused; otherwise the line is the likely cause.
      setNote((err as AxiosError<{ message?: string }>).response?.data?.message ?? 'Could not switch notifications on. Check the internet connection and try again.')
      return null
    } finally {
      setBusy(false)
    }
  }
  return { busy, note, setNote, switchOn }
}

/**
 * On a person's first screen: a strip offering notifications on this device, saying what they would be told of,
 * until they are on or the person says "Later" (asked again after a week). One thing is asked at a time: on a phone
 * still in the browser the strip above it offers to install the app, and this one waits until that is done or put off.
 */
export function PushHint() {
  const { user } = useAuth()
  const { state } = usePush(user)
  const { busy, note, switchOn } = useSwitchOn(user)
  const [later, setLater] = useState(() => (user ? putOff(user.id) : true))
  const phone = usePhone()

  if (!user || later || state !== 'off' || (phone && !runningInstalled() && !installPutOff())) return null

  const notNow = () => {
    setLater(true)
    try {
      localStorage.setItem(laterKey(user.id), String(Date.now()))
    } catch {
      // Without storage it is only hidden until the page is loaded again.
    }
  }

  return (
    <div className="install-hint no-print">
      <div>
        <strong>Get notified on this device</strong>
        <span>You will be told of {toldOf(user)}, even when the app is closed. You can switch it off any time under My profile.</span>
        {note && <span className="install-steps">{note}</span>}
      </div>
      <div className="install-actions">
        <button type="button" className="btn primary" disabled={busy} onClick={() => void switchOn()}>
          {busy ? 'Switching on…' : 'Turn on'}
        </button>
        <button type="button" className="link" onClick={notNow}>
          Later
        </button>
      </div>
    </div>
  )
}

/** On the account page: whether this device is notified for this person, switching it on or off, and sending one to see it arrive. */
export function PushSettings() {
  const { user } = useAuth()
  const { state } = usePush(user)
  const { busy, note, setNote, switchOn } = useSwitchOn(user)
  const [working, setWorking] = useState(false)

  const switchOff = async () => {
    if (!user) return
    setWorking(true)
    await turnOffPush(user)
    setNote('Switched off on this device.')
    setWorking(false)
  }
  const test = async () => {
    setWorking(true)
    setNote(await sendTestPush())
    setWorking(false)
  }

  return (
    <>
      <h3>Notifications</h3>
      <p className="muted small">
        This device can tell you of {toldOf(user)}, even with the app closed. Each person switches this on for themselves, on each phone or computer they use. Signing out stops yours on this device until you sign in here again.
      </p>
      {state === null ? (
        <p className="muted small">Checking this device…</p>
      ) : state === 'on' ? (
        <div className="row wrap">
          <span>
            <strong>On</strong> for this device.
          </span>
          <button type="button" className="btn" disabled={working} onClick={() => void test()}>
            Send a test
          </button>
          <button type="button" className="link danger" disabled={working} onClick={() => void switchOff()}>
            Switch off
          </button>
        </div>
      ) : state === 'off' ? (
        <div className="row wrap">
          <span>
            <strong>Off</strong> for this device.
          </span>
          <button type="button" className="btn primary" disabled={busy} onClick={() => void switchOn()}>
            {busy ? 'Switching on…' : 'Turn on notifications'}
          </button>
        </div>
      ) : (
        <p className="muted small">{whyNot(state)}</p>
      )}
      {note && <p className="muted small" role="status">{note}</p>}
    </>
  )
}
