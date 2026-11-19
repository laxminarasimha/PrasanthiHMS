import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { clock, longDate, today } from '../format'
import { useChoiceInAddress } from '../rangeInAddress'
import { inr } from './pay'
import type { StaffBrief } from './shared'
import { WorkforceTabs } from './WorkforceTabs'

type Person = StaffBrief & { late_minutes: number; leave_type: string | null }
type Board = {
  pay: { unpaid_salaries?: { slips: number; net: string }; runs_in_hand?: number; advances?: { count: number; owed: string; waiting: number } } | null
  date: string
  is_today: boolean
  staff: number
  in: Person[]
  not_in: Person[]
  on_leave: Person[]
  late: Person[]
  off: number
  missing_check_outs: { id: number; employee: { id: number; name: string }; work_date: string; check_in: string | null }[]
  corrections_waiting: number | null
  leave_waiting: number | null
}

/** The day at a glance: who is in, who is not, who is on leave or late, and what is waiting for a decision. */
export function WorkforceOverview() {
  const [date, setDate] = useChoiceInAddress<string>('date', today())
  const [board, setBoard] = useState<Board | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    let current = true
    api
      .get<Board>('/workforce/dashboard', { params: { date } })
      .then((res) => {
        if (!current) return
        setBoard(res.data)
        setError('')
      })
      .catch(() => current && setError('Could not load the day. Check the connection and try again.'))
    return () => {
      current = false
    }
  }, [date])
  // Figures for a different day than the one chosen are never shown as if they were that day's.
  const shown = board?.date === date ? board : null

  const list = (title: string, people: Person[], empty: string, extra?: (p: Person) => string) => (
    <section className="wf-list">
      <h3>
        {title} <span className="count">{people.length}</span>
      </h3>
      {people.length === 0 ? (
        <p className="muted small">{empty}</p>
      ) : (
        <ul>
          {people.map((p) => (
            <li key={p.id}>
              <span>
                {p.name}
                <small className="muted block">{[p.designation, p.department?.name].filter(Boolean).join(' · ') || p.employee_no}</small>
              </span>
              {extra && <small className="muted">{extra(p)}</small>}
            </li>
          ))}
        </ul>
      )}
    </section>
  )

  return (
    <>
      <WorkforceTabs />
      <section className="card">
        <div className="toolbar">
          <h1>Staff today</h1>
          <input type="date" className="date" value={date} max={today()} onChange={(e) => setDate(e.target.value || today())} aria-label="Day" />
          {date !== today() && (
            <button className="btn ghost" onClick={() => setDate(today())}>
              Today
            </button>
          )}
        </div>
        {error && <div className="form-error">{error}</div>}
        {!shown && !error && <p className="loading">Loading the day…</p>}
        {shown && (
          <>
            <div className="tiles">
              <div className="tile">
                <span>In</span>
                <strong className="settled">{shown.in.length}</strong>
                <small className="muted">of {shown.staff} staff</small>
              </div>
              <div className="tile">
                <span>{shown.is_today ? 'Not in yet' : 'Absent'}</span>
                <strong className={shown.not_in.length > 0 ? 'due' : ''}>{shown.not_in.length}</strong>
              </div>
              <div className="tile">
                <span>On leave</span>
                <strong>{shown.on_leave.length}</strong>
              </div>
              <div className="tile">
                <span>Came late</span>
                <strong>{shown.late.length}</strong>
              </div>
              <div className="tile">
                <span>Weekly off or holiday</span>
                <strong>{shown.off}</strong>
              </div>
            </div>

            {shown.pay && (
              <div className="tiles small">
                {shown.pay.unpaid_salaries && (
                  <Link className="tile" to="/workforce/payroll">
                    <span>Salaries finalised, not yet paid</span>
                    <strong className={shown.pay.unpaid_salaries.slips > 0 ? 'due' : ''}>{inr(shown.pay.unpaid_salaries.net)}</strong>
                    <small className="muted">
                      {shown.pay.unpaid_salaries.slips} {shown.pay.unpaid_salaries.slips === 1 ? 'payslip' : 'payslips'}
                      {shown.pay.runs_in_hand ? ` · ${shown.pay.runs_in_hand} payroll ${shown.pay.runs_in_hand === 1 ? 'run' : 'runs'} in hand` : ''}
                    </small>
                  </Link>
                )}
                {shown.pay.advances && (
                  <Link className="tile" to="/workforce/advances">
                    <span>Owed by staff on advances and loans</span>
                    <strong>{inr(shown.pay.advances.owed)}</strong>
                    <small className="muted">
                      on {shown.pay.advances.count}
                      {shown.pay.advances.waiting ? ` · ${shown.pay.advances.waiting} waiting for a decision` : ''}
                    </small>
                  </Link>
                )}
              </div>
            )}

            {(shown.leave_waiting || shown.corrections_waiting || shown.missing_check_outs.length > 0) && (
              <ul className="todo">
                {Boolean(shown.leave_waiting) && (
                  <li className="warn">
                    <Link to="/workforce/leave">
                      <strong>{shown.leave_waiting}</strong> leave {shown.leave_waiting === 1 ? 'request is' : 'requests are'} waiting for a decision.
                    </Link>
                  </li>
                )}
                {Boolean(shown.corrections_waiting) && (
                  <li className="warn">
                    <Link to="/workforce/attendance?view=corrections">
                      <strong>{shown.corrections_waiting}</strong> attendance {shown.corrections_waiting === 1 ? 'correction is' : 'corrections are'} waiting for a decision.
                    </Link>
                  </li>
                )}
                {shown.missing_check_outs.map((m) => (
                  <li key={m.id} className="note">
                    <Link to={`/workforce/attendance?date=${m.work_date}`}>
                      <strong>{m.employee.name}</strong> was checked in on {longDate(m.work_date)}
                      {m.check_in ? ` at ${clock(m.check_in)}` : ''} and never checked out. Put the time right.
                    </Link>
                  </li>
                ))}
              </ul>
            )}

            <div className="wf-lists">
              {list(shown.is_today ? 'Not in yet' : 'Absent', shown.not_in, 'Everyone expected is in.')}
              {list('Came late', shown.late, 'Nobody was late.', (p) => `${p.late_minutes} min late`)}
              {list('On leave', shown.on_leave, 'Nobody is on leave.', (p) => p.leave_type ?? '')}
              {list('In', shown.in, 'Nobody has been marked in.')}
            </div>
          </>
        )}
      </section>
    </>
  )
}
