import { Popup } from '../components/Popup'
import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { TextField } from '../components/Field'
import { clock, longDate, today } from '../format'
import { Link } from 'react-router-dom'
import { useChoiceInAddress } from '../rangeInAddress'
import { useNotice } from '../notices'
import { LeaveForm } from './Leave'
import { shortTime } from './dutyWords'
import { ADVANCE_CLASS, ADVANCE_LABEL, PAYMENT_CLASS, PAYMENT_LABEL, inr, type Advance } from './pay'
import { DAY_CLASS, DAY_LABEL, DAY_LETTER, PART_LABEL, REQUEST_CLASS, REQUEST_LABEL, addMonths, days as dayCount, hours, monthName, thisMonth, type Correction, type Day, type LeaveRequest, type LeaveType, type Spell, type Totals } from './shared'
import { WorkforceTabs } from './WorkforceTabs'

/** With no staff record joined to the login, only `employee: null` comes back. */
type Mine = {
  employee: { id: number; name: string; employee_no: string; designation: string | null; department: { name: string } | null; shift: { name: string; starts_at: string; ends_at: string } | null; joined_on: string | null } | null
  month: string
  days: Day[]
  totals: Totals
  records: Spell[]
  /** The duty they are on now; a night duty that began yesterday is still this one until it is over. */
  open: Spell | null
  /** An earlier duty that was never checked out. */
  forgotten?: Spell | null
  self_check_in: boolean
  leave_year: number
  leave_types: LeaveType[]
  leave: LeaveRequest[]
  corrections: Correction[]
  payslips: { id: number; period: string; period_name: string; slip_no: string | null; net: string; payment_status: string; paid_on: string | null }[]
  advances: (Advance & { next: { period: string; amount: string } | null })[]
  duties?: { date: string; off: boolean; shift: string | null; starts_at: string | null; ends_at: string | null; rostered: boolean }[]
}

/** A person's own attendance, leave and requests. Nothing here shows anyone else's record. */
export function MyRecord() {
  const [month, setMonth] = useChoiceInAddress<string>('month', thisMonth())
  const [mine, setMine] = useState<Mine | null>(null)
  const [failed, setFailed] = useState('')
  const [notice, setNotice] = useState('')
  const [panel, setPanel] = useState<'leave' | 'correction' | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    api
      .get<Mine>('/workforce/me', { params: { month } })
      .then((res) => {
        setMine(res.data)
        setFailed('')
      })
      .catch(() => setFailed('Could not load your record. Check the connection and try again.'))
  }, [month])
  useEffect(load, [load])
  // Leave decided while the record is open: it shows at once. A request being written stays as it is.
  useNotice(['leave'], load)
  const shown = mine && (mine.employee === null || mine.month === month) ? mine : null

  const punch = async () => {
    setBusy(true)
    setNotice('')
    try {
      const res = await api.post<Spell>('/workforce/me/punch')
      setNotice(res.data.state === 'open' ? `You are checked in at ${clock(res.data.check_in ?? '')}.` : `You are checked out at ${clock(res.data.check_out ?? '')}.`)
      load()
    } catch (err) {
      setNotice(Object.values(fieldErrors(err))[0] ?? 'That did not save. Try again.')
    } finally {
      setBusy(false)
    }
  }
  const withdraw = async (r: LeaveRequest) => {
    if (!window.confirm('Withdraw this leave request?')) return
    try {
      await api.post(`/workforce/me/leave/${r.id}/cancel`)
      setNotice('The leave request was withdrawn.')
      load()
    } catch (err) {
      setNotice(Object.values(fieldErrors(err))[0] ?? 'That did not save. Try again.')
    }
  }
  // A login with no staff record joined to it gets only that fact back, with nothing else to read.
  const todays = shown?.employee ? shown.days.find((d) => d.date === today()) : undefined
  const saved = (message: string) => {
    setPanel(null)
    setNotice(message)
    load()
  }

  return (
    <>
      <WorkforceTabs />
      {failed && <div className="form-error">{failed}</div>}
      {!shown && !failed && <p className="loading">Loading your record…</p>}
      {shown && shown.employee === null && (
        <section className="card">
          <h1>My record</h1>
          <p className="muted">Your login is not joined to a staff record yet, so there is nothing to show here. Ask whoever keeps the staff directory to join them.</p>
        </section>
      )}
      {shown?.employee && (
        <div className="split">
          <section className="card">
            <div className="toolbar">
              <div className="grow">
                <h1>{shown.employee.name}</h1>
                <span className="muted">
                  {[shown.employee.employee_no, shown.employee.designation, shown.employee.department?.name, shown.employee.shift ? `${shown.employee.shift.name} ${shown.employee.shift.starts_at}–${shown.employee.shift.ends_at}` : ''].filter(Boolean).join(' · ')}
                </span>
              </div>
              <button className="btn ghost" onClick={() => setPanel('correction')}>
                Ask for a correction
              </button>
              <button className="btn primary" onClick={() => setPanel('leave')}>
                Ask for leave
              </button>
            </div>
            {notice && <p className="notice">{notice}</p>}

            {month === thisMonth() && todays && (
              <div className="wf-today">
                <div>
                  <span className="muted small">Today</span>
                  <strong>
                    {/* Still on last night's duty: nothing is marked for today yet, but they are in. */}
                    {shown.open && shown.open.work_date !== today() ? <span className="pill ok">On duty</span> : <span className={`pill ${DAY_CLASS[todays.status] ?? ''}`}>{DAY_LABEL[todays.status] ?? '—'}</span>}
                  </strong>
                  <small className="muted">
                    {shown.open ? `In since ${clock(shown.open.check_in ?? '')}${shown.open.work_date !== today() ? ` on ${longDate(shown.open.work_date)}; this duty counts for that day` : ''}` : todays.worked_minutes ? `Worked ${hours(todays.worked_minutes)}` : ''}
                    {todays.late_minutes > 0 ? ` · ${todays.late_minutes} min late` : ''}
                  </small>
                  {shown.forgotten && <small className="due">Your check-out on {longDate(shown.forgotten.work_date)} was not recorded. Ask for a correction, or ask the office to put it right.</small>}
                </div>
                {shown.self_check_in && (
                  <button className={shown.open ? 'btn' : 'btn primary'} disabled={busy} onClick={() => void punch()}>
                    {busy ? 'Saving…' : shown.open ? 'Check out' : 'Check in'}
                  </button>
                )}
              </div>
            )}

            <div className="toolbar minor">
              <button className="btn ghost" onClick={() => setMonth(addMonths(month, -1))} aria-label="The month before">
                ‹
              </button>
              <strong>{monthName(month)}</strong>
              <button className="btn ghost" onClick={() => setMonth(addMonths(month, 1))} disabled={month >= thisMonth()} aria-label="The month after">
                ›
              </button>
            </div>
            <div className="tiles small">
              <div className="tile">
                <span>Present</span>
                <strong>{dayCount(shown.totals.present)}</strong>
              </div>
              <div className="tile">
                <span>Leave</span>
                <strong>{dayCount(shown.totals.leave)}</strong>
              </div>
              <div className="tile">
                <span>Absent</span>
                <strong className={shown.totals.absent > 0 ? 'due' : ''}>{dayCount(shown.totals.absent)}</strong>
              </div>
              <div className="tile">
                <span>Late</span>
                <strong>{dayCount(shown.totals.late_days)}</strong>
              </div>
              <div className="tile">
                <span>Worked</span>
                <strong>{hours(shown.totals.worked_minutes) || '0h'}</strong>
              </div>
            </div>
            <div className="wf-mine-days" role="list" aria-label="Each day of the month">
              {shown.days.map((d) => (
                <div key={d.date} role="listitem" className={`wf-box ${d.status}`} title={`${longDate(d.date)}: ${DAY_LABEL[d.status] ?? ''}${d.late_minutes ? `, ${d.late_minutes} min late` : ''}${d.holiday ? `, ${d.holiday}` : ''}${d.leave_type ? `, ${d.leave_type}` : ''}`}>
                  <small>{Number(d.date.slice(8))}</small>
                  {DAY_LETTER[d.status] ?? ''}
                  {d.late_minutes > 0 && <i aria-hidden="true" />}
                </div>
              ))}
            </div>
            <p className="muted small wf-key">P present · ½ half day · L leave · A absent · O weekly off · H holiday. A dot marks a day you came late.</p>

            <div className="day-section">
              <h3>My leave</h3>
              <div className="tiles small">
                {shown.leave_types.map((t) => (
                  <div className="tile" key={t.id}>
                    <span>{t.name}</span>
                    <strong>{t.is_paid ? `${t.left ?? 0} left` : 'Without pay'}</strong>
                  </div>
                ))}
              </div>
              {shown.leave_types.length === 0 && <p className="muted">No kinds of leave have been set up yet.</p>}
              <ul className="wf-requests">
                {shown.leave.map((r) => (
                  <li key={r.id}>
                    <span>
                      <strong>{r.type?.name}</strong> · {r.from_date === r.to_date ? longDate(r.from_date) : `${longDate(r.from_date)} to ${longDate(r.to_date)}`}
                      {r.from_part !== 'full' ? ` (${PART_LABEL[r.from_part].toLowerCase()})` : ''} · {dayCount(r.days)}
                      {(r.reason || r.decision_note) && <small className="muted block">{[r.reason, r.decision_note ? `Decision: ${r.decision_note}` : ''].filter(Boolean).join(' · ')}</small>}
                    </span>
                    <span className="row">
                      <span className={`pill ${REQUEST_CLASS[r.status]}`}>{REQUEST_LABEL[r.status]}</span>
                      {(r.status === 'pending' || (r.status === 'approved' && r.from_date > today())) && (
                        <button className="link" onClick={() => void withdraw(r)}>
                          Withdraw
                        </button>
                      )}
                    </span>
                  </li>
                ))}
                {shown.leave.length === 0 && <li className="muted">You have not asked for any leave.</li>}
              </ul>
            </div>

            {(shown.duties?.length ?? 0) > 0 && (
              <div className="day-section">
                <h3>My duties, the next two weeks</h3>
                <div className="wf-duties">
                  {shown.duties!.map((d) => (
                    <div key={d.date} className={`wf-duty ${d.off ? 'off' : d.starts_at && d.ends_at && d.ends_at <= d.starts_at ? 'night' : 'day'}`}>
                      <small>{new Date(`${d.date}T00:00:00`).toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' })}</small>
                      <span className="wf-duty-text">{d.off ? 'Day off' : d.starts_at ? `${d.shift ?? ''} ${shortTime(d.starts_at)}–${shortTime(d.ends_at!)}` : 'No shift set'}</span>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {shown.payslips.length > 0 && (
              <div className="day-section">
                <h3>My payslips</h3>
                <ul className="wf-requests">
                  {shown.payslips.map((p) => (
                    <li key={p.id}>
                      <span>
                        <Link to={`/workforce/me/payslips/${p.id}/print`} target="_blank">
                          {p.period_name}
                        </Link>
                        <small className="muted block">{[p.slip_no, `Net pay ${inr(p.net)}`, p.paid_on ? `paid on ${longDate(p.paid_on)}` : ''].filter(Boolean).join(' · ')}</small>
                      </span>
                      <span className={`pill ${PAYMENT_CLASS[p.payment_status]}`}>{PAYMENT_LABEL[p.payment_status]}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {shown.advances.length > 0 && (
              <div className="day-section">
                <h3>My advances and loans</h3>
                <ul className="wf-requests">
                  {shown.advances.map((a) => (
                    <li key={a.id}>
                      <span>
                        {a.kind === 'loan' ? 'Loan' : 'Advance'} {a.advance_no} · {inr(a.amount)}
                        <small className="muted block">
                          {a.owed !== null ? `${inr(a.owed)} still owed` : a.reason}
                          {a.next ? ` · ${inr(a.next.amount)} from the salary for ${monthName(a.next.period)}` : ''}
                        </small>
                      </span>
                      <span className={`pill ${ADVANCE_CLASS[a.status]}`}>{ADVANCE_LABEL[a.status]}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {shown.corrections.length > 0 && (
              <div className="day-section">
                <h3>Corrections I asked for</h3>
                <ul className="wf-requests">
                  {shown.corrections.map((c) => (
                    <li key={c.id}>
                      <span>
                        {longDate(c.work_date)} · {c.after.check_in ? clock(c.after.check_in) : '—'} to {c.after.check_out ? clock(c.after.check_out) : '—'}
                        <small className="muted block">{[c.reason, c.decision_note ? `Decision: ${c.decision_note}` : ''].filter(Boolean).join(' · ')}</small>
                      </span>
                      <span className={`pill ${REQUEST_CLASS[c.status]}`}>{REQUEST_LABEL[c.status]}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </section>

          {panel === 'leave' && <LeaveForm own types={shown.leave_types} onClose={() => setPanel(null)} onSaved={saved} />}
          {panel === 'correction' && <CorrectionForm records={shown.records} onClose={() => setPanel(null)} onSaved={saved} />}
        </div>
      )}
    </>
  )
}

/** A person asks for their own attendance to be put right. Someone else decides it. */
function CorrectionForm({ records, onClose, onSaved }: { records: Spell[]; onClose: () => void; onSaved: (message: string) => void }) {
  const [date, setDate] = useState(today())
  const existing = records.filter((r) => r.work_date === date).pop() ?? null
  const local = (value: string | null | undefined) => (value ? value.slice(0, 16).replace(' ', 'T') : '')
  const [checkIn, setCheckIn] = useState('')
  const [checkOut, setCheckOut] = useState('')
  const [reason, setReason] = useState('')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const inValue = checkIn || local(existing?.check_in) || `${date}T09:00`
  const outValue = checkOut || local(existing?.check_out)

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    try {
      await api.post('/workforce/me/corrections', { attendance_id: existing?.id, work_date: date, check_in: inValue.replace('T', ' '), check_out: outValue ? outValue.replace('T', ' ') : null, reason })
      onSaved('Your correction was sent. Nothing changes until someone approves it.')
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }
  const other = Object.entries(errors).filter(([key]) => !['check_in', 'check_out', 'reason', 'work_date'].includes(key))

  return (
    <Popup title={'Ask for a correction'} onClose={onClose} onSubmit={save} size="slim">
      <TextField
        label="Which day"
        required
        type="date"
        value={date}
        max={today()}
        onChange={(v) => {
          setDate(v || today())
          setCheckIn('')
          setCheckOut('')
        }}
        error={errors.work_date}
      />
      <p className="muted small">{existing ? `Your saved entry for that day: ${existing.check_in ? clock(existing.check_in) : '—'} to ${existing.check_out ? clock(existing.check_out) : 'not checked out'}.` : 'There is no entry for that day; this asks for one to be added.'}</p>
      <TextField label="It should be: checked in" required type="datetime-local" value={inValue} onChange={setCheckIn} error={errors.check_in} />
      <TextField label="Checked out" type="datetime-local" value={outValue} onChange={setCheckOut} error={errors.check_out} />
      <TextField label="What is wrong, and why" required value={reason} onChange={setReason} error={errors.reason} />
      {other.length > 0 && (
        <div className="form-error">
          {other.map(([key, message]) => (
            <div key={key}>{message}</div>
          ))}
        </div>
      )}
      <div className="panel-foot">
        <button className="btn primary" disabled={saving || !reason.trim()}>
          {saving ? 'Sending…' : 'Send for approval'}
        </button>
      </div>
    </Popup>
  )
}
