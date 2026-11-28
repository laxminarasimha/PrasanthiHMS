import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { rupees } from '../format'
import { DoctorLink } from './DoctorLink'

type Day = { day: string; billed: number; collected: number; paid_out: number }
type Owed = { key: string; label: string; owed: number; count: number }
type DoctorRow = { id: number; name: string; booked: number; seen: number; billed: number; inpatients: number }
type Owner = { today: string; from: string; to: string; trend: Day[]; owed: Owed[]; owed_total: number; doctors: DoctorRow[] }

const weekday = (day: string) => new Date(`${day}T00:00:00`).toLocaleDateString('en-IN', { weekday: 'short' })
/** "23 Nov": these are the latest two weeks, so the year goes without saying. */
const dayMonth = (day: string) => new Date(`${day}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })

/**
 * The owner's three questions, under the organisation report: how the last two weeks went day by day, what
 * patients still owe the hospital today, and what each doctor did in the dates chosen above. The phone app
 * shows the same figures.
 */
export function OwnerFigures({ from, to }: { from: string; to: string }) {
  const [owner, setOwner] = useState<Owner | null>(null)
  // What a doctor is paid is for the admin alone.
  const isAdmin = useAuth().user?.role === 'admin'

  useEffect(() => {
    if (from > to) return
    let current = true
    api
      .get<Owner>('/reports/owner', { params: { from, to, days: 14 } })
      .then((res) => current && setOwner(res.data))
      .catch(() => undefined)
    return () => {
      current = false
    }
  }, [from, to])

  if (!owner) return null

  // Each day's bar is its money collected against the best day of the fourteen.
  const best = Math.max(1, ...owner.trend.map((d) => d.collected))
  const owing = owner.owed.filter((o) => o.owed > 0)
  const reportLink = (report: string) => `/reports?tab=detailed&report=${report}&from=${from}&to=${to}`

  return (
    <>
      <div className="print-together">
        <h3>The last {owner.trend.length} days</h3>
        <div className="table-wrap">
          <table className="compact day-trend">
            <thead>
              <tr>
                <th>Day</th>
                <th className="num">Billed</th>
                <th className="num">Collected</th>
                <th aria-hidden="true" />
                <th className="num">Paid out</th>
                <th className="num">Collected less paid out</th>
              </tr>
            </thead>
            <tbody>
              {[...owner.trend].reverse().map((d) => {
                const left = Math.round((d.collected - d.paid_out) * 100) / 100
                return (
                  <tr key={d.day} className="static">
                    <td className="trend-day">
                      <Link to={`/reports?tab=detailed&report=collections&from=${d.day}&to=${d.day}`} title="Every payment taken that day">
                        {d.day === owner.today ? <strong>Today</strong> : dayMonth(d.day)}
                      </Link>{' '}
                      <small className="muted">{weekday(d.day)}</small>
                    </td>
                    <td className="num">{rupees(d.billed)}</td>
                    <td className="num strong">{rupees(d.collected)}</td>
                    <td className="trend-cell" aria-hidden="true">
                      {d.collected > 0 && <span className="trend-bar" style={{ width: `${Math.round((d.collected / best) * 100)}%` }} />}
                    </td>
                    <td className="num">{rupees(d.paid_out)}</td>
                    <td className={`num ${left < 0 ? 'due' : ''}`}>{rupees(left)}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
        <p className="muted small">These fourteen days are always the latest ones, whatever dates are chosen above. The bar compares each day's collection with the best of them.</p>
      </div>

      <div className="print-together">
        <h3>Still owed to the hospital today</h3>
        <div className="tiles small">
          <div className="tile">
            <span>All that is owed</span>
            <strong className={owner.owed_total > 0 ? 'due' : 'settled'}>{rupees(owner.owed_total)}</strong>
          </div>
          {owing.map((o) => (
            <div className="tile" key={o.key}>
              <span>{o.key.startsWith('ipd') ? <Link to="/ipd">{o.label}</Link> : o.label}</span>
              <strong>{rupees(o.owed)}</strong>
              <small className="muted">
                {o.count} {o.key.startsWith('ipd') ? (o.count === 1 ? 'case' : 'cases') : o.count === 1 ? 'bill' : 'bills'}
              </small>
            </div>
          ))}
        </div>
        {owing.length === 0 && <p className="muted small">Nothing is owed on any bill.</p>}
      </div>

      <div className="print-together">
        <h3>By doctor in these dates</h3>
        <div className="table-wrap">
          <table className="compact">
            <thead>
              <tr>
                <th>Doctor</th>
                <th className="num">Appointments</th>
                <th className="num">Seen</th>
                <th className="num">Billed on their visits</th>
                <th className="num">Inpatients now</th>
              </tr>
            </thead>
            <tbody>
              {owner.doctors.map((d) => (
                <tr key={d.id} className="static">
                  <td>
                    <DoctorLink id={d.id}>{d.name}</DoctorLink>
                  </td>
                  <td className="num">{d.booked}</td>
                  <td className="num">{d.seen}</td>
                  <td className="num">{rupees(d.billed)}</td>
                  <td className="num">{d.inpatients}</td>
                </tr>
              ))}
              {owner.doctors.length === 0 && (
                <tr className="empty">
                  <td colSpan={5}>No doctor had patients in these dates.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <p className="muted small no-print">
          For the money: <Link to={reportLink('doctor-collections')}>billing and collections by doctor</Link>
          {isAdmin && (
            <>
              {' '}
              · <Link to={reportLink('doctor-payout')}>each doctor's share of collections</Link>
            </>
          )}
        </p>
      </div>
    </>
  )
}
