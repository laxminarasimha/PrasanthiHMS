import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors } from '../api'
import { clock, today } from '../format'
import type { Spell } from './shared'

type Today = { employee: { id: number; name: string } | null; self_check_in?: boolean; open?: Spell | null; done?: Spell[] }

/** "23 Nov" for a spell that began on another day, such as last night's duty. */
const shortDay = (dateTime: string) => new Date(`${dateTime.slice(0, 10)}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })

/**
 * A person's own attendance for today at the top of their first screen on a phone: whether they are in, and one large
 * button to check in or out. It marks attendance exactly as "My record" does, and is shown only to someone whose login
 * is joined to a staff record, in a hospital that lets staff mark their own attendance.
 */
export function AttendanceCard() {
  const [mine, setMine] = useState<Today | null>(null)
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState('')

  const load = useCallback(() => {
    api
      .get<Today>('/workforce/me/today')
      .then((res) => setMine(res.data))
      .catch(() => undefined)
  }, [])
  useEffect(load, [load])

  if (!mine?.employee || !mine.self_check_in) return null
  const open = mine.open ?? null
  const done = mine.done ?? []
  const cameAt = open?.check_in ?? ''

  const punch = async () => {
    if (open && !window.confirm(`Check out now? You checked in at ${clock(cameAt)}.`)) return
    setBusy(true)
    setFailed('')
    try {
      await api.post('/workforce/me/punch')
      load()
    } catch (err) {
      setFailed(Object.values(fieldErrors(err))[0] ?? 'That did not save. Try again.')
    } finally {
      setBusy(false)
    }
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
        <Link className="small" to="/workforce/me">
          My attendance
        </Link>
      </div>
      <button type="button" className={open || done.length ? 'btn' : 'btn primary'} disabled={busy} onClick={() => void punch()}>
        {busy ? 'Saving…' : open ? 'Check out' : done.length ? 'Check in again' : 'Check in'}
      </button>
      {failed && <p className="form-error">{failed}</p>}
    </section>
  )
}
