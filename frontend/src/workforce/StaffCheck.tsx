import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors } from '../api'
import { WorkforceTabs } from './WorkforceTabs'

type Person = { id: number; name: string; employee_no: string; phone: string | null; designation: string | null }
type Login = { id: number; name: string; role: string; phone: string | null; email: string | null; is_active: boolean }
type Check = {
  unjoined: (Login & { match: (Person & { by: 'mobile' | 'email' }) | null })[]
  switched_off: number
  differences: { employee_id: number; name: string; employee_no: string; field: 'name' | 'phone' | 'gender'; staff: string; login: string }[]
  shared_numbers: { mobile: string; logins: Login[] }[]
  shared_screens: Login[]
  without_login: Person[]
  totals: { staff: number; logins: number; joined: number }
}

const FIELD = { name: 'Name', phone: 'Mobile', gender: 'Gender' }
const signsIn = (l: Login) => [l.phone, l.email].filter(Boolean).join(' · ') || '—'

/**
 * The staff check. A person and their login are one record; this page lists every place where the hospital's logins
 * and its staff records do not yet agree, and each line carries the buttons that put it right. Nothing is changed
 * until a button is pressed.
 */
export function StaffCheck() {
  const [check, setCheck] = useState<Check | null>(null)
  const [failed, setFailed] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  // The person chosen by hand for a login with no certain match.
  const [chosen, setChosen] = useState<Record<number, string>>({})

  const load = useCallback(() => {
    api
      .get<Check>('/workforce/staff-check')
      .then((res) => {
        setCheck(res.data)
        setFailed('')
      })
      .catch(() => setFailed('Could not load the staff check. Check the connection and try again.'))
  }, [])
  useEffect(load, [load])

  /** Does one thing, says what was done, and reads the list afresh. */
  const act = async (work: () => Promise<string>, ask?: string) => {
    if (ask && !window.confirm(ask)) return
    setBusy(true)
    setNotice('')
    setFailed('')
    try {
      setNotice(await work())
      load()
    } catch (err) {
      setFailed(Object.values(fieldErrors(err))[0] ?? 'That did not save. Try again.')
    } finally {
      setBusy(false)
    }
  }
  const join = (pairs: { user_id: number; employee_id: number }[]) => api.post<{ joined: number }>('/workforce/staff-check/join', { pairs }).then((res) => `${res.data.joined} joined.`)
  const create = (ids: number[]) => api.post<{ made: number }>('/workforce/staff-check/create', { user_ids: ids }).then((res) => `${res.data.made} staff record${res.data.made === 1 ? '' : 's'} made. Open each in Staff to add the designation and joining date.`)
  const settle = (employee_id: number, field: string, keep: 'staff' | 'login') => api.post('/workforce/staff-check/settle', { employee_id, field, keep }).then(() => 'Both now say the same.')
  const screen = (id: number, shared: boolean) => api.post(`/workforce/staff-check/shared-screen/${id}`, { shared }).then(() => (shared ? 'Marked as a shared screen.' : 'It is a person’s login again.'))

  const certain = check?.unjoined.filter((l) => l.match) ?? []
  const rest = check?.unjoined.filter((l) => !l.match) ?? []
  const inOrder = check && check.unjoined.length === 0 && check.differences.length === 0 && check.shared_numbers.length === 0

  return (
    <>
      <WorkforceTabs />
      <section className="card staff-check">
        <div className="toolbar">
          <div className="grow">
            <h1>Staff check</h1>
            <span className="muted">Every login should belong to one member of staff. This page shows where that is not yet so.</span>
          </div>
          <Link className="btn ghost" to="/workforce/staff">
            Back to Staff
          </Link>
        </div>
        {notice && <p className="notice">{notice}</p>}
        {failed && <div className="form-error">{failed}</div>}
        {!check && !failed && <p className="loading">Checking…</p>}

        {check && (
          <>
            <div className="tiles small">
              <div className="tile">
                <span>On the staff</span>
                <strong>{check.totals.staff}</strong>
              </div>
              <div className="tile">
                <span>Logins in use</span>
                <strong>{check.totals.logins}</strong>
              </div>
              <div className="tile">
                <span>Joined to a person</span>
                <strong>{check.totals.joined}</strong>
              </div>
              <div className="tile">
                <span>Staff with no login</span>
                <strong>{check.without_login.length}</strong>
              </div>
            </div>

            {inOrder && <p className="notice">Everything is in order: every login in use belongs to one member of staff, and their details agree.</p>}

            {check.unjoined.length > 0 && (
              <>
                <h2>
                  Logins that belong to no member of staff <span className="pill status-arrived">{check.unjoined.length}</span>
                </h2>
                <p className="muted">Join each login to the person it belongs to. If that person is not on the staff list yet, a staff record can be made from the login. Neither side's details are changed by joining.</p>
                <div className="row gap-bottom">
                  {certain.length > 0 && (
                    <button className="btn primary" disabled={busy} onClick={() => void act(() => join(certain.map((l) => ({ user_id: l.id, employee_id: l.match!.id }))), `Join these ${certain.length} logins to the staff shown beside them?`)}>
                      Join all {certain.length} certain matches
                    </button>
                  )}
                  {rest.length > 0 && (
                    <button className="btn" disabled={busy} onClick={() => void act(() => create(rest.map((l) => l.id)), `Make a staff record for each of the ${rest.length} logins that have no match? Each takes the login's name, mobile and gender, and nothing else.`)}>
                      Make staff records for the {rest.length} with no match
                    </button>
                  )}
                </div>
                <div className="table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th>Login</th>
                        <th>Signs in with</th>
                        <th>The person it belongs to</th>
                        <th />
                      </tr>
                    </thead>
                    <tbody>
                      {check.unjoined.map((l) => (
                        <tr key={l.id}>
                          <td className="fit-wrap">
                            <strong>{l.name}</strong>
                            <small className="muted block">{l.role}</small>
                          </td>
                          <td className="wrap">{signsIn(l)}</td>
                          <td className="wrap">
                            {l.match ? (
                              <>
                                <strong>{l.match.name}</strong> <span className="muted">{l.match.employee_no}</span>
                                <small className="muted block">Same {l.match.by === 'mobile' ? 'mobile number' : 'email'}</small>
                              </>
                            ) : (
                              <select value={chosen[l.id] ?? ''} onChange={(e) => setChosen({ ...chosen, [l.id]: e.target.value })} aria-label={`The person ${l.name} is`}>
                                <option value="">No certain match: choose…</option>
                                {check.without_login.map((p) => (
                                  <option key={p.id} value={p.id}>
                                    {p.name} · {p.employee_no}
                                    {p.phone ? ` · ${p.phone}` : ''}
                                  </option>
                                ))}
                              </select>
                            )}
                          </td>
                          <td className="actions">
                            {(l.match || chosen[l.id]) && (
                              <button className="btn small-btn primary" disabled={busy} onClick={() => void act(() => join([{ user_id: l.id, employee_id: l.match?.id ?? Number(chosen[l.id]) }]))}>
                                Join
                              </button>
                            )}
                            {!l.match && !chosen[l.id] && (
                              <button className="btn small-btn" disabled={busy} onClick={() => void act(() => create([l.id]))}>
                                Make a staff record
                              </button>
                            )}
                            <button className="link" disabled={busy} title="A login for a screen on the wall, such as the waiting-room board, which is not a member of staff" onClick={() => void act(() => screen(l.id, true), `Mark “${l.name}” as a shared screen, not a member of staff?`)}>
                              Not a person
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}

            {check.differences.length > 0 && (
              <>
                <h2>
                  Details that differ <span className="pill status-arrived">{check.differences.length}</span>
                </h2>
                <p className="muted">These people are joined to their login, but the two do not say the same. Choose which is right; the other is changed to match.</p>
                <div className="table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th>Person</th>
                        <th>What differs</th>
                        <th>On the staff record</th>
                        <th>On the login</th>
                      </tr>
                    </thead>
                    <tbody>
                      {check.differences.map((d) => (
                        <tr key={`${d.employee_id}-${d.field}`}>
                          <td className="fit-wrap">
                            <strong>{d.name}</strong>
                            <small className="muted block">{d.employee_no}</small>
                          </td>
                          <td>{FIELD[d.field]}</td>
                          <td className="wrap">
                            {d.staff}{' '}
                            <button className="link" disabled={busy} onClick={() => void act(() => settle(d.employee_id, d.field, 'staff'))}>
                              Use this
                            </button>
                          </td>
                          <td className="wrap">
                            {d.login}{' '}
                            <button className="link" disabled={busy} onClick={() => void act(() => settle(d.employee_id, d.field, 'login'))}>
                              Use this
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}

            {check.shared_numbers.length > 0 && (
              <>
                <h2>
                  Mobile numbers on more than one login <span className="pill off">{check.shared_numbers.length}</span>
                </h2>
                <p className="muted">A mobile number opens one login only: the first that took it. The others sign in with their email until their own number is on their login. Correct the number on the person's page in Staff.</p>
                <ul className="plain-list">
                  {check.shared_numbers.map((n) => (
                    <li key={n.mobile}>
                      <span className="mono">{n.mobile}</span>: {n.logins.map((l) => `${l.name} (${l.role})`).join(', ')}
                    </li>
                  ))}
                </ul>
              </>
            )}

            {check.shared_screens.length > 0 && (
              <>
                <h2>Shared screens</h2>
                <p className="muted">Logins for a screen on the wall. They are not staff: no attendance, no pay.</p>
                <ul className="plain-list">
                  {check.shared_screens.map((l) => (
                    <li key={l.id}>
                      {l.name} <span className="muted">· {l.role}</span>{' '}
                      <button className="link" disabled={busy} onClick={() => void act(() => screen(l.id, false))}>
                        It is a person after all
                      </button>
                    </li>
                  ))}
                </ul>
              </>
            )}

            {check.switched_off > 0 && (
              <p className="muted small">
                {check.switched_off} login{check.switched_off === 1 ? ' that is' : 's that are'} switched off and belong to no member of staff {check.switched_off === 1 ? 'is' : 'are'} left as {check.switched_off === 1 ? 'it is' : 'they are'}.
              </p>
            )}
          </>
        )}
      </section>
    </>
  )
}
