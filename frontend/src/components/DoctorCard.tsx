import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { clock, longDate, rupees } from '../format'
import { PersonCard } from '../workforce/PersonCard'
import { Popup } from './Popup'

type Hours = Record<string, { from: string; to: string }[]> | null

type Card = {
  doctor: { id: number; name: string; department: string | null; phone: string | null; active: boolean; availability: Hours; hours_today: { from: string; to: string }[]; slot_minutes: number | null; fee: number | null }
  away: { from: string; to: string; start: string | null; end: string | null; reason: string | null; leave: boolean }[]
  today: { booked: number; waiting: number; with_doctor: number; seen: number; queue: { id: number; token: number | null; status: string; patient: { id: number; uhid: string; name: string } | null }[] }
  inpatients?: { id: number; ipd_no: string; patient: { id: number; uhid: string; name: string } | null; bed: string | null; since: string }[]
  results_unseen?: number
  reps_waiting?: { id: number; rep: string | null; company: string | null }[]
  money_today?: { billed: number; collected: number }
  employee_id?: number | null
}

const DAYS = [['mon', 'Mon'], ['tue', 'Tue'], ['wed', 'Wed'], ['thu', 'Thu'], ['fri', 'Fri'], ['sat', 'Sat'], ['sun', 'Sun']] as const
const time = (t: string) => clock(`2000-01-01 ${t}`)
const short = (d: string) => longDate(d).replace(/ \d{4}$/, '')

/** One doctor at a glance, in a window over whatever page their name was on. The server sends each part only to those whose work needs it. */
export function DoctorCard({ id, onClose }: { id: number; onClose: () => void }) {
  const [card, setCard] = useState<Card | null>(null)
  const [failed, setFailed] = useState('')
  const [staff, setStaff] = useState(false)
  const desk = Boolean(useAuth().user?.can_front_desk)

  useEffect(() => {
    api
      .get<Card>(`/doctors/${id}/card`)
      .then((res) => setCard(res.data))
      .catch(() => setFailed('Could not open this doctor. Check the connection and try again.'))
  }, [id])

  if (staff && card?.employee_id) return <PersonCard id={card.employee_id} onClose={onClose} />

  const d = card?.doctor
  const t = card?.today
  const hoursToday = d?.hours_today ?? []
  const week = d?.availability ? DAYS.filter(([day]) => (d.availability![day] ?? []).length > 0) : []

  return (
    <Popup
      title={d?.name ?? 'Opening…'}
      sub={d ? [d.department, d.fee !== null ? `OPD fee ${rupees(d.fee)}` : null, d.active ? null : 'No longer seeing patients'].filter(Boolean).join(' · ') : undefined}
      onClose={onClose}
      foot={
        d && (
          <>
            {card?.employee_id && (
              <button type="button" className="btn ghost" onClick={() => setStaff(true)}>
                Staff record
              </button>
            )}
            {desk && (
              <Link className="btn" to={`/front-desk/calendar?doctor=${d.id}`} onClick={onClose}>
                Calendar
              </Link>
            )}
          </>
        )
      }
    >
      {failed && <p className="form-error">{failed}</p>}
      {!card && !failed && <p className="loading">Opening…</p>}
      {d && t && (
        <div className="doctor-card">
          {d.phone && (
            <p>
              <a href={`tel:${d.phone}`}>{d.phone}</a>
            </p>
          )}

          {card.away.length > 0 && (
            <div className="doctor-away">
              {card.away.map((b, i) => (
                <p key={i}>
                  <strong>{b.leave ? 'On leave' : 'Away'}</strong> {b.from === b.to ? short(b.from) : `${short(b.from)} to ${short(b.to)}`}
                  {b.start && b.end ? `, ${time(b.start)} to ${time(b.end)}` : ''}
                  {b.reason ? <span className="muted"> · {b.reason}</span> : null}
                </p>
              ))}
            </div>
          )}

          <div className="tiles small">
            <div className="tile">
              <span>Waiting</span>
              <strong>{t.waiting}</strong>
            </div>
            <div className="tile">
              <span>With the doctor</span>
              <strong>{t.with_doctor}</strong>
            </div>
            <div className="tile">
              <span>Seen today</span>
              <strong>{t.seen}</strong>
            </div>
            <div className="tile">
              <span>Still to come</span>
              <strong>{t.booked}</strong>
            </div>
          </div>

          {t.queue.length > 0 && (
            <>
              <h3>Here now</h3>
              <ul className="plain-list">
                {t.queue.map((q) => (
                  <li key={q.id}>
                    {q.token ? <span className="mono">#{q.token} </span> : null}
                    {q.patient ? (
                      <Link to={`/patients/${q.patient.id}`} onClick={onClose}>
                        {q.patient.name}
                      </Link>
                    ) : (
                      'A patient'
                    )}
                    <small className="muted"> · {q.status === 'ongoing' ? 'with the doctor' : 'waiting'}</small>
                  </li>
                ))}
              </ul>
            </>
          )}

          {card.inpatients && card.inpatients.length > 0 && (
            <>
              <h3>Inpatients ({card.inpatients.length})</h3>
              <ul className="plain-list">
                {card.inpatients.map((a) => (
                  <li key={a.id}>
                    <Link to={`/ipd/${a.id}`} onClick={onClose}>
                      {a.patient?.name ?? a.ipd_no}
                    </Link>
                    <small className="muted">
                      {' '}
                      · {a.ipd_no}
                      {a.bed ? ` · ${a.bed}` : ''} · since {short(a.since)}
                    </small>
                  </li>
                ))}
              </ul>
            </>
          )}

          {(card.results_unseen ?? 0) > 0 && (
            <p>
              <Link to="/doctor/results" onClick={onClose}>
                {card.results_unseen} lab report{card.results_unseen === 1 ? '' : 's'} ready and not seen yet
              </Link>
            </p>
          )}

          {card.reps_waiting && card.reps_waiting.length > 0 && (
            <p>
              <Link to="/reps" onClick={onClose}>
                {card.reps_waiting.length} medical rep{card.reps_waiting.length === 1 ? '' : 's'} waiting
              </Link>
              <small className="muted"> · {card.reps_waiting.map((r) => [r.rep, r.company].filter(Boolean).join(', ')).join('; ')}</small>
            </p>
          )}

          {card.money_today && (
            <p>
              Today: <strong>{rupees(card.money_today.collected)}</strong> collected <span className="muted">of {rupees(card.money_today.billed)} billed</span>
            </p>
          )}

          <h3>Hours</h3>
          {week.length === 0 ? (
            <p className="muted">No hours set: any time can be booked.</p>
          ) : (
            <>
              <p>
                Today: {hoursToday.length ? hoursToday.map((s) => `${time(s.from)} to ${time(s.to)}`).join(', ') : <span className="muted">not seeing patients</span>}
              </p>
              <ul className="plain-list muted small">
                {week.map(([day, label]) => (
                  <li key={day}>
                    {label}: {d.availability![day].map((s) => `${time(s.from)}–${time(s.to)}`).join(', ')}
                  </li>
                ))}
              </ul>
            </>
          )}
        </div>
      )}
    </Popup>
  )
}
