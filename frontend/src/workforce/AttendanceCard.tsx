import { useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useAuth } from '../auth'
import { clock, today } from '../format'
import { punchMyToday, useMyToday } from './myToday'

/** "23 Nov" for a spell that began on another day, such as last night's duty. */
const shortDay = (dateTime: string) => new Date(`${dateTime.slice(0, 10)}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })

/**
 * A person's own attendance for today at the top of their first screen: whether they are in, and one large
 * button to check in or out. It marks attendance exactly as "My record" does, and is shown only to someone whose login
 * is joined to a staff record, in a hospital that lets staff mark their own attendance.
 */
export function AttendanceCard() {
  const { pathname } = useLocation()
  const { user } = useAuth()
  const mine = useMyToday()
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState('')

  if (!mine?.employee || !mine.self_check_in) return null
  const open = mine.open ?? null
  const done = mine.done ?? []
  const cameAt = open?.check_in ?? ''

  const punch = async () => {
    if (!user || (open && !window.confirm(`Check out now? You checked in at ${clock(cameAt)}.`))) return
    setBusy(true)
    setFailed(await punchMyToday(user.id))
    setBusy(false)
  }

  return (
    <section className="card punch-card" aria-label="My attendance today">
      <div>
        <span className="muted small">My attendance today</span>
        {open ? (
          <strong className="pill ok">
            Checked in at {clock(cameAt)}
            {cameAt.slice(0, 10) !== today() ? `, ${shortDay(cameAt)}` : ''}
          </strong>
        ) : done.length ? (
          <strong className="pill status-reviewed">Shift completed</strong>
        ) : (
          <strong className="pill status-arrived">Not checked in</strong>
        )}
        {!open && done.length > 0 && (
          <small className="muted">
            {clock(done[0].check_in ?? '')} to {clock(done[done.length - 1].check_out ?? '')}
          </small>
        )}
        {pathname !== '/workforce/me' && (
          <Link className="small" to="/workforce/me">
            My attendance
          </Link>
        )}
      </div>
      <button type="button" className={open || done.length ? 'btn' : 'btn primary'} disabled={busy || mine.here === false} onClick={() => void punch()}>
        {busy ? 'Saving…' : open ? 'Check out' : done.length ? 'Check in again' : 'Check in'}
      </button>
      {mine.here === false && <p className="punch-away">Connect your phone to the hospital Wi-Fi to check {open ? 'out' : 'in'}. It does not work on mobile data or from outside the hospital.</p>}
      {failed && <p className="form-error">{failed}</p>}
    </section>
  )
}
