import { useAuth } from '../auth'
import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors } from '../api'
import { Popup } from '../components/Popup'
import { clock, longDate, today } from '../format'
import { DAY_CLASS, DAY_LABEL, STATUS_LABEL, TYPE_LABEL, hours, type Employee, type Spell } from './shared'

type Card = {
  person: Employee
  can: { edit: boolean; check_in: boolean }
  today?: { status: string; shift: string | null; note: string | null; late_minutes: number; worked_minutes: number; on: Spell | null; records: Spell[] }
  month?: { name: string; present: number; leave: number; absent: number; late_days: number; worked_minutes: number }
  leave?: { left: { code: string; name: string; left: number }[]; coming: { from: string; to: string; days: number; status: string; type: string | null }[] }
  doctor?: { availability: Record<string, { from: string; to: string }[]> | null; slot_minutes: number | null; booked_today?: number }
}

const DAYS = [['mon', 'Mon'], ['tue', 'Tue'], ['wed', 'Wed'], ['thu', 'Thu'], ['fri', 'Fri'], ['sat', 'Sat'], ['sun', 'Sun']] as const
const time = (t: string) => clock(`2000-01-01 ${t}`)
const days = (n: number) => `${n} ${n === 1 ? 'day' : 'days'}`

/**
 * One member of staff at a glance, in a window over whatever page their name was on: who they are, how to reach them,
 * whether they are in today, their month, their leave, and a doctor's hours. The server sends each part only to
 * someone who may see it, and pay is never here. Closing it leaves the page behind exactly as it was.
 */
export function PersonCard({ id, onClose, onChanged }: { id: number; onClose: () => void; /** After a check-in or check-out made here, so the page behind can catch up. */ onChanged?: () => void }) {
  const managesLogins = Boolean(useAuth().user?.can_manage_staff)
  const [card, setCard] = useState<Card | null>(null)
  const [failed, setFailed] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    api
      .get<Card>(`/workforce/employees/${id}/card`)
      .then((res) => {
        setCard(res.data)
        setFailed('')
      })
      .catch(() => setFailed('Could not open this person. Check the connection and try again.'))
  }, [id])
  useEffect(load, [load])

  const p = card?.person
  const t = card?.today
  const punch = async () => {
    if (!p || !t) return
    if (t.on && !window.confirm(`Check ${p.name} out now?`)) return
    setBusy(true)
    setFailed('')
    try {
      if (t.on) await api.post(`/workforce/attendance/${t.on.id}/check-out`)
      else await api.post('/workforce/attendance/check-in', { employee_id: p.id })
      load()
      onChanged?.()
    } catch (err) {
      setFailed(Object.values(fieldErrors(err))[0] ?? 'That did not save. Try again.')
    } finally {
      setBusy(false)
    }
  }
  const first = t?.records[0]
  const last = t?.records[t.records.length - 1]
  const hoursSet = card?.doctor?.availability ? DAYS.filter(([day]) => (card.doctor!.availability![day] ?? []).length > 0) : []

  return (
    <Popup
      title={p?.name ?? 'Opening…'}
      sub={p ? [p.designation, p.department?.name, p.employee_no].filter(Boolean).join(' · ') : undefined}
      onClose={onClose}
      foot={
        p && (
          <>
            {managesLogins && p.login && p.login.signs_in !== false && (
              <Link className="btn ghost" to={`/workforce/staff?person=${p.id}&part=login`} onClick={onClose} title="Set a new password or PIN for someone who forgot theirs, or change what they may do">
                {p.login.sign_in_with === 'pin' ? 'Login and PIN' : 'Login and password'}
              </Link>
            )}
            <Link className="btn" to={`/workforce/staff?person=${p.id}`} onClick={onClose}>
              {card?.can.edit ? 'Open full record to change it' : 'Open full record'}
            </Link>
          </>
        )
      }
    >
      {failed && <div className="form-error">{failed}</div>}
      {!card && !failed && <p className="loading">Loading…</p>}
      {card && p && (
        <div className="person-card">
          <div className="row wrap">
            <span className={`pill ${p.status === 'active' ? 'ok' : p.status === 'inactive' ? 'off' : 'status-arrived'}`}>{STATUS_LABEL[p.status] ?? p.status}</span>
            <span className="pill status-booked">{TYPE_LABEL[p.employment_type] ?? p.employment_type}</span>
            {p.login ? <span className="pill status-ongoing">{p.login.signs_in === false ? 'On the doctors’ list · does not sign in' : `Signs in as ${p.login.role_label}`}</span> : <span className="pill status-booked">Does not sign in</span>}
            {p.login && !p.login.is_active && <span className="pill off">Login switched off</span>}
          </div>
          {p.regular === false && <p className="muted small">A doctor or consultant: not in the daily attendance or the payroll. They may still check in for the record.</p>}

          <dl className="person-facts">
            <dt>Mobile</dt>
            <dd>{p.phone ? <a href={`tel:${p.phone.replace(/[^0-9+]/g, '')}`}>{p.phone}</a> : <span className="muted">Not given</span>}</dd>
            {p.email && (
              <>
                <dt>Email</dt>
                <dd>
                  <a href={`mailto:${p.email}`}>{p.email}</a>
                </dd>
              </>
            )}
            {p.manager && (
              <>
                <dt>Reports to</dt>
                <dd>{p.manager.name}</dd>
              </>
            )}
            {p.shift && (
              <>
                <dt>Usual duty</dt>
                <dd>{p.shift}</dd>
              </>
            )}
            {p.joined_on && (
              <>
                <dt>Joined</dt>
                <dd>{longDate(p.joined_on)}</dd>
              </>
            )}
            {p.emergency_contact_name && (
              <>
                <dt>In an emergency</dt>
                <dd>
                  {p.emergency_contact_name}
                  {p.emergency_contact_phone ? (
                    <>
                      {' · '}
                      <a href={`tel:${p.emergency_contact_phone.replace(/[^0-9+]/g, '')}`}>{p.emergency_contact_phone}</a>
                    </>
                  ) : null}
                </dd>
              </>
            )}
          </dl>

          {t && (
            <div className="wf-today">
              <div>
                <span className="muted small">Today</span>
                <strong>{t.on ? <span className="pill ok">In the hospital</span> : t.status ? <span className={`pill ${DAY_CLASS[t.status] ?? ''}`}>{DAY_LABEL[t.status] ?? t.status}</span> : <span className="pill status-booked">Nothing marked</span>}</strong>
                <small className="muted">
                  {t.on
                    ? `Since ${clock(t.on.check_in ?? '')}${t.on.work_date !== today() ? ` on ${longDate(t.on.work_date)}` : ''}`
                    : first?.check_in
                      ? `${clock(first.check_in)} to ${last?.check_out ? clock(last.check_out) : '—'}${t.worked_minutes ? ` · ${hours(t.worked_minutes)}` : ''}`
                      : (t.note ?? t.shift ?? '')}
                  {t.late_minutes > 0 ? ` · ${t.late_minutes} min late` : ''}
                </small>
              </div>
              {card.can.check_in && (t.on || t.status !== 'on_leave') && (
                <button type="button" className={t.on ? 'btn' : 'btn primary'} disabled={busy} onClick={() => void punch()}>
                  {busy ? 'Saving…' : t.on ? 'Check out' : 'Check in'}
                </button>
              )}
            </div>
          )}

          {card.month && (
            <>
              <h3>{card.month.name}</h3>
              <div className="tiles small">
                <div className="tile">
                  <span>Present</span>
                  <strong>{days(card.month.present)}</strong>
                </div>
                <div className="tile">
                  <span>Leave</span>
                  <strong>{days(card.month.leave)}</strong>
                </div>
                {p.regular !== false && (
                  <div className="tile">
                    <span>Absent</span>
                    <strong className={card.month.absent > 0 ? 'due' : ''}>{days(card.month.absent)}</strong>
                  </div>
                )}
                <div className="tile">
                  <span>Late</span>
                  <strong>{days(card.month.late_days)}</strong>
                </div>
              </div>
            </>
          )}

          {card.leave && (card.leave.left.length > 0 || card.leave.coming.length > 0) && (
            <>
              <h3>Leave</h3>
              {card.leave.left.length > 0 && <p className="person-line">{card.leave.left.map((l) => `${l.name}: ${l.left} left`).join(' · ')}</p>}
              {card.leave.coming.map((l) => (
                <p className="person-line" key={`${l.from}-${l.to}`}>
                  <span className={`pill ${l.status === 'approved' ? 'ok' : 'status-arrived'}`}>{l.status === 'approved' ? 'Approved' : 'Waiting'}</span> {l.type ?? 'Leave'}, {longDate(l.from)}
                  {l.to !== l.from ? ` to ${longDate(l.to)}` : ''} ({days(l.days)})
                </p>
              ))}
            </>
          )}

          {card.doctor && (
            <>
              <h3>Seeing patients</h3>
              {card.doctor.booked_today !== undefined && (
                <p className="person-line">
                  <strong>{card.doctor.booked_today}</strong> {card.doctor.booked_today === 1 ? 'patient is' : 'patients are'} booked with them today.
                </p>
              )}
              {hoursSet.length > 0 ? (
                <dl className="person-facts">
                  {hoursSet.map(([day, label]) => (
                    <div key={day} className="contents">
                      <dt>{label}</dt>
                      <dd>{card.doctor!.availability![day].map((r) => `${time(r.from)} to ${time(r.to)}`).join(', ')}</dd>
                    </div>
                  ))}
                </dl>
              ) : (
                <p className="muted small">No hours of their own are set; the hospital's OPD hours apply.</p>
              )}
            </>
          )}
        </div>
      )}
    </Popup>
  )
}
