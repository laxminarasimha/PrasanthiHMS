import { useState } from 'react'
import { api, fieldErrors } from '../api'
import { useAuth } from '../auth'
import { BAR_MOST, barChoices, usualBarKeys } from '../nav'
import { NAV_ICONS } from '../navIcons'
import type { User } from '../types'

/**
 * A person arranging the bar at the foot of their own phone: which places are on it and in what order. Each change is
 * saved as it is made and the bar follows at once. It only arranges; what a person may open stays as it is.
 */
export function PhoneBarSettings() {
  const { user, refresh } = useAuth()
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  if (!user) return null

  const choices = barChoices(user)
  const arranged = (user.bar_tabs ?? []).filter((key) => choices.some((c) => c.key === key))
  const custom = arranged.length > 0
  // Until a person arranges their own, they start from the usual bar for their role.
  const keys = custom ? arranged : usualBarKeys(user)
  const left = choices.filter((c) => !keys.includes(c.key))

  const save = async (next: string[], said: string) => {
    setBusy(true)
    setNotice('')
    try {
      refresh((await api.put<User>('/me/bar', { bar: next })).data)
      setNotice(said)
    } catch (err) {
      setNotice(Object.values(fieldErrors(err))[0] ?? 'Could not save the bar. Try again.')
    } finally {
      setBusy(false)
    }
  }
  const move = (at: number, by: number) => {
    const next = [...keys]
    const [taken] = next.splice(at, 1)
    next.splice(at + by, 0, taken)
    void save(next, 'Order changed.')
  }

  return (
    <>
      <h3>The bar at the foot of my phone</h3>
      <p className="muted small">
        On a phone, these are the places on the bar at the foot of the screen, from left to right. Put the ones you use most first. Up to {BAR_MOST}; “More” is always last and opens everything else, including “My profile”, so taking a place off the bar never loses it.
      </p>
      <ol className="bar-order">
        {keys.map((key, at) => {
          const place = choices.find((c) => c.key === key)!
          return (
            <li key={key}>
              <span className="bar-place">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <path d={NAV_ICONS[key] ?? NAV_ICONS.more} />
                </svg>
                {place.label}
              </span>
              <span className="bar-acts">
                <button type="button" className="btn ghost small-btn" disabled={busy || at === 0} onClick={() => move(at, -1)} aria-label={`Move ${place.label} earlier`} title="Move earlier">
                  ↑
                </button>
                <button type="button" className="btn ghost small-btn" disabled={busy || at === keys.length - 1} onClick={() => move(at, 1)} aria-label={`Move ${place.label} later`} title="Move later">
                  ↓
                </button>
                <button type="button" className="link danger" disabled={busy || keys.length <= 1} onClick={() => void save(keys.filter((k) => k !== key), `${place.label} taken off the bar.`)}>
                  Take off
                </button>
              </span>
            </li>
          )
        })}
      </ol>
      <div className="row">
        {left.length > 0 && (
          <select
            value=""
            disabled={busy || keys.length >= BAR_MOST}
            onChange={(e) => e.target.value && void save([...keys, e.target.value], `${choices.find((c) => c.key === e.target.value)?.label} added to the bar.`)}
            aria-label="Add a place to the bar"
            className="narrow"
          >
            <option value="">{keys.length >= BAR_MOST ? 'The bar is full: take one off to add another' : 'Add a place…'}</option>
            {left.map((c) => (
              <option key={c.key} value={c.key}>
                {c.label}
              </option>
            ))}
          </select>
        )}
        {custom && (
          <button type="button" className="link" disabled={busy} onClick={() => void save([], 'Back to the usual bar.')}>
            Back to the usual bar
          </button>
        )}
      </div>
      {notice && <p className="muted small">{notice}</p>}
    </>
  )
}
