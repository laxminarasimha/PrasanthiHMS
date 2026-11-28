import { useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { useAuth } from '../auth'
import { clock } from '../format'
import { checkInDue, loadMyToday, punchMyToday, putCheckInAway, useCheckInPutAway, useMyToday } from './myToday'

const greeting = () => {
  const hour = new Date().getHours()
  return hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening'
}
/** "Asha" from "Dr. Asha Sample": the name a person is greeted by. */
const firstName = (name: string) => name.replace(/^(dr|mr|mrs|ms)\.?\s+/i, '').split(/\s+/)[0] ?? name

/**
 * The call-out on opening the app. Someone who has not checked in today is asked to, before anything else, with one
 * large button that marks them as in the hospital. "Not now" puts it away until the app is opened again; the check-in
 * card on their first screen stays. It is shown only where the hospital lets staff mark their own attendance and the
 * login belongs to a member of staff, and never to someone already in or whose day is done. Whether it was put away is
 * kept with today's attendance, so what waits for it hears at once.
 */
export function CheckInPrompt() {
  const { user } = useAuth()
  const mine = useMyToday()
  const putAwayToday = useCheckInPutAway(user?.id)
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState('')
  // Just checked in from here: the window says so for a moment before it goes.
  const [justIn, setJustIn] = useState(false)

  // It stays up while the check-in is on its way and for the moment it says "you are checked in": the answer arrives
  // before this window hears of it, and must not close it in between.
  const showing = Boolean(user) && (justIn || busy || (checkInDue(mine) && !putAwayToday))

  const putAway = () => {
    if (user) putCheckInAway(user.id)
    setFailed('')
  }
  useEffect(() => {
    if (!showing) return
    const key = (e: KeyboardEvent) => e.key === 'Escape' && !busy && putAway()
    window.addEventListener('keydown', key)
    return () => window.removeEventListener('keydown', key)
  })
  useEffect(() => {
    if (!justIn) return
    const timer = window.setTimeout(() => setJustIn(false), 2200)
    return () => window.clearTimeout(timer)
  }, [justIn])

  if (!showing || !user) return null

  const checkIn = async () => {
    setBusy(true)
    setFailed('')
    const wrong = await punchMyToday(user.id)
    if (!wrong) {
      putAway()
      setJustIn(true)
    }
    setFailed(wrong)
    setBusy(false)
  }
  const away = mine?.here === false

  return createPortal(
    <div className="modal-back check-in-back" onMouseDown={(e) => e.target === e.currentTarget && !busy && !justIn && putAway()}>
      <div className="modal popup slim check-in-prompt" role="dialog" aria-modal="true" aria-labelledby="check-in-title">
        {justIn ? (
          <>
            <span className="check-in-mark done" aria-hidden="true">
              ✓
            </span>
            <h2 id="check-in-title">You are checked in</h2>
            <p>Marked as in the hospital{mine?.open?.check_in ? ` at ${clock(mine.open.check_in)}` : ''}.</p>
          </>
        ) : (
          <>
            <span className="check-in-mark" aria-hidden="true">
              <svg viewBox="0 0 24 24">
                <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3.5 2" />
              </svg>
            </span>
            <h2 id="check-in-title">
              {greeting()}, {firstName(user.name)}
            </h2>
            <p>You have not checked in yet today. Press the button to mark that you are in the hospital.</p>
            {away ? (
              <>
                <p className="punch-away">Connect your phone to the hospital Wi-Fi first. Check-in does not work on mobile data or from outside the hospital.</p>
                <button type="button" className="btn primary block" autoFocus onClick={() => void loadMyToday(user.id)}>
                  I am on the Wi-Fi now: try again
                </button>
              </>
            ) : (
              <button type="button" className="btn primary block" autoFocus disabled={busy} onClick={() => void checkIn()}>
                {busy ? 'Checking in…' : 'Check in now'}
              </button>
            )}
            {failed && <p className="form-error">{failed}</p>}
            <button type="button" className="link" disabled={busy} onClick={putAway}>
              Not now
            </button>
          </>
        )}
      </div>
    </div>,
    document.body,
  )
}
