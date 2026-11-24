import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors } from '../api'
import { PersonLink } from './PersonLink'
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
  /** Doctors on the staff list (a consultant, or someone written as "Dr") who cannot be chosen for an appointment or an admission yet. */
  consultants_not_doctors?: Person[]
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
  const toDoctors = async (people: Person[]) => {
    for (const p of people) await api.put(`/staff/doctor-for/${p.id}`, { doctor: true })
    return `${people.length} put on the doctors' list. Open each in Staff to add the qualification printed under their name.`
  }
  const screen = (id: number, shared: boolean) => api.post(`/workforce/staff-check/shared-screen/${id}`, { shared }).then(() => (shared ? 'Marked as a shared screen.' : 'It is a person’s login again.'))

  // The starting PINs just made for everyone who had no login: shown once, to print or copy and hand out.
  const [sheet, setSheet] = useState<{ given: { name: string; employee_no: string; mobile: string; starting_pin: string }[]; skipped: { name: string; employee_no: string; why: string }[] } | null>(null)
  const giveAll = async () => {
    if (!check || !window.confirm(`Give a login to all ${check.without_login.length} staff who have none? Each will sign in with their mobile number and a starting PIN, and will see only their own attendance, leave and payslips.`)) return
    setBusy(true)
    setFailed('')
    try {
      setSheet((await api.post('/staff/for-all')).data)
      load()
    } catch (err) {
      setFailed(Object.values(fieldErrors(err))[0] ?? 'That did not work. Try again.')
    } finally {
      setBusy(false)
    }
  }

  const certain = check?.unjoined.filter((l) => l.match) ?? []
  const rest = check?.unjoined.filter((l) => !l.match) ?? []
  const inOrder = check && check.unjoined.length === 0 && check.differences.length === 0 && check.shared_numbers.length === 0 && (check.consultants_not_doctors?.length ?? 0) === 0

  if (sheet) {
    return (
      <>
        <WorkforceTabs />
        <section className="card staff-check pin-sheet">
          <div className="toolbar no-print">
            <div className="grow">
              <h1>Starting PINs</h1>
              <span className="muted">Shown only this once. Print or copy this list now, hand each person their PIN, then destroy the list.</span>
            </div>
            <button className="btn" onClick={() => window.print()}>
              Print
            </button>
            <button className="btn primary" onClick={() => window.confirm('Close the list? The PINs cannot be shown again; a forgotten one is replaced from the person’s page.') && setSheet(null)}>
              Done
            </button>
          </div>
          <p>Each person signs in with their mobile number and the starting PIN beside it, and then chooses a PIN of their own.</p>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Employee number</th>
                  <th>Signs in with mobile</th>
                  <th>Starting PIN</th>
                </tr>
              </thead>
              <tbody>
                {sheet.given.map((g) => (
                  <tr key={g.employee_no}>
                    <td>
                      <strong>{g.name}</strong>
                    </td>
                    <td>{g.employee_no}</td>
                    <td className="mono">{g.mobile}</td>
                    <td className="mono pin">{g.starting_pin}</td>
                  </tr>
                ))}
                {sheet.given.length === 0 && (
                  <tr className="empty">
                    <td colSpan={4}>No login was given.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
          {sheet.skipped.length > 0 && (
            <div className="no-print">
              <h2>Left out</h2>
              <p className="muted">Correct the mobile number on the person's page in Staff, then give the login from there.</p>
              <ul className="plain-list">
                {sheet.skipped.map((k) => (
                  <li key={k.employee_no}>
                    <strong>{k.name}</strong> <span className="muted">{k.employee_no}</span>: {k.why}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </section>
      </>
    )
  }

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
                            <Link className="link" to={`/workforce/staff?login=${l.id}`} title="Its role, password and whether it is open">
                              Open login
                            </Link>
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
                            <strong>
                              <PersonLink id={d.employee_id}>{d.name}</PersonLink>
                            </strong>
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
                      <Link className="link" to={`/workforce/staff?login=${l.id}`}>
                        Open login
                      </Link>{' '}
                      <button className="link" disabled={busy} onClick={() => void act(() => screen(l.id, false))}>
                        It is a person after all
                      </button>
                    </li>
                  ))}
                </ul>
              </>
            )}

            {(check.consultants_not_doctors?.length ?? 0) > 0 && (
              <>
                <h2>
                  Doctors not on the doctors' list <span className="pill status-arrived">{check.consultants_not_doctors!.length}</span>
                </h2>
                <p className="muted">They are on the staff list as a consultant, or written as "Dr", but cannot be chosen for an appointment, an admission or a consultant's visit until they are on the hospital's list of doctors. They do not need a login for it. A consultant added from now on is put on the list as they are added.</p>
                <div className="row gap-bottom">
                  <button className="btn primary" disabled={busy} onClick={() => void act(() => toDoctors(check.consultants_not_doctors!), `Put these ${check.consultants_not_doctors!.length} on the doctors' list?`)}>
                    Put all {check.consultants_not_doctors!.length} on the doctors' list
                  </button>
                </div>
                <ul className="plain-list people">
                  {check.consultants_not_doctors!.map((p) => (
                    <li key={p.id}>
                      <PersonLink id={p.id}>{p.name}</PersonLink> <span className="muted">· {p.employee_no}{p.designation ? ` · ${p.designation}` : ''}</span>{' '}
                      <button className="link" disabled={busy} onClick={() => void act(() => toDoctors([p]))}>
                        Put on the list
                      </button>
                    </li>
                  ))}
                </ul>
              </>
            )}

            {check.without_login.length > 0 && (
              <>
                <h2>
                  Staff who do not sign in <span className="pill status-booked">{check.without_login.length}</span>
                </h2>
                <p className="muted">Nothing is wrong with this. To let them check in from their own phone, give each a login that opens only their own attendance, leave and payslips: one by one from their page in Staff, or all together here.</p>
                <div className="row gap-bottom">
                  <button className="btn" disabled={busy} onClick={() => void giveAll()}>
                    Give all {check.without_login.length} a login
                  </button>
                </div>
                <ul className="plain-list people">
                  {check.without_login.map((p) => (
                    <li key={p.id}>
                      <PersonLink id={p.id}>{p.name}</PersonLink> <span className="muted">· {p.employee_no}{p.designation ? ` · ${p.designation}` : ''} · {p.phone ?? 'no mobile number'}</span>
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
