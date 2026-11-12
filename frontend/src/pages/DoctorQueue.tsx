import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { DoctorTabs } from '../components/DoctorTabs'
import { STATUS_LABELS, clock, longDate, today } from '../format'
import type { Doctor, QueueEntry } from '../types'

/** Minutes since the patient arrived, shown only while they are still waiting. */
function waiting(entry: QueueEntry, now: number) {
  if (entry.status !== 'arrived' || !entry.arrived_at) return '—'
  const minutes = Math.max(0, Math.round((now - new Date(entry.arrived_at.replace(' ', 'T')).getTime()) / 60000))
  return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)}h ${minutes % 60}m`
}

export function DoctorQueue() {
  const { user } = useAuth()
  const isDoctor = user?.role === 'doctor'
  const [date, setDate] = useState(today())
  const [doctorId, setDoctorId] = useState('')
  const [doctors, setDoctors] = useState<Doctor[]>([])
  const [queue, setQueue] = useState<QueueEntry[] | null>(null)
  const [now, setNow] = useState(() => Date.now())
  const [search, setSearch] = useState('')

  const load = useCallback(() => {
    api.get<QueueEntry[]>('/doctor/queue', { params: { date, doctor_id: doctorId || undefined } }).then((res) => {
      setQueue(res.data)
      setNow(Date.now())
    })
  }, [date, doctorId])

  // Refresh every half minute so new arrivals from the front desk show up on their own.
  useEffect(() => {
    load()
    const timer = setInterval(load, 30000)
    return () => clearInterval(timer)
  }, [load])

  useEffect(() => {
    if (!isDoctor) api.get<Doctor[]>('/doctors').then((res) => setDoctors(res.data))
  }, [isDoctor])

  const pending = queue?.filter((q) => q.status !== 'reviewed').length ?? 0
  const done = queue?.filter((q) => q.status === 'reviewed').length ?? 0
  const term = search.trim().toLowerCase()
  const shown = queue?.filter((q) => !term || q.patient.name.toLowerCase().includes(term) || q.patient.uhid.toLowerCase().includes(term) || q.patient.mobile.startsWith(term))
  // How the day splits by reason for the visit, e.g. "Consultation 9 · Review 2".
  const purposes = Object.entries(
    (queue ?? []).reduce<Record<string, number>>((all, q) => ({ ...all, [q.service?.name ?? 'No service']: (all[q.service?.name ?? 'No service'] ?? 0) + 1 }), {}),
  )

  return (
    <>
    <DoctorTabs />
    <section className="card">
      <div className="toolbar">
        <h1>{isDoctor ? 'My patients' : 'Doctor queue'}</h1>
        <input type="date" className="date" value={date} onChange={(e) => setDate(e.target.value || today())} aria-label="Day" />
        {date !== today() && (
          <button className="btn ghost" onClick={() => setDate(today())}>
            Today
          </button>
        )}
        {!isDoctor && doctors.length > 1 && (
          <select className="narrow" value={doctorId} onChange={(e) => setDoctorId(e.target.value)} aria-label="Doctor">
            <option value="">All doctors</option>
            {doctors.map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </select>
        )}
        <input className="search" type="search" placeholder="Search by name, mobile or ID" value={search} onChange={(e) => setSearch(e.target.value)} />
        <span className="muted">
          Pending: <strong>{pending}</strong> · Completed: <strong>{done}</strong>
          {purposes.length > 0 && <small className="block">{purposes.map(([name, count]) => `${name} ${count}`).join(' · ')}</small>}
        </span>
      </div>

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Time</th>
              <th>Patient</th>
              {!isDoctor && <th>Doctor</th>}
              <th>Purpose</th>
              <th>Last visit</th>
              <th className="num">Visits</th>
              <th>Waiting</th>
              <th>Status</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {shown?.map((q) => (
              <tr key={q.id} className={`static ${q.status === 'booked' ? 'not-here' : ''}`}>
                <td className="mono">
                  {clock(q.scheduled_at)}
                  {q.token_no && <small className="muted block">Token {q.token_no}</small>}
                </td>
                <td>
                  {/* The name opens the pad too, so a phone needs no sideways scrolling to reach the button. */}
                  <Link className="link" to={`/visits/${q.id}`}>
                    {q.patient.name}
                  </Link>
                  <small className="muted block">
                    {q.patient.uhid} · {q.patient.age}y / {q.patient.gender.charAt(0).toUpperCase()}
                  </small>
                </td>
                {!isDoctor && <td>{q.doctor.name}</td>}
                <td>{q.service?.name ?? '—'}</td>
                <td>{q.last_visit ? longDate(q.last_visit) : 'First visit'}</td>
                <td className="num">{q.visits}</td>
                <td>{waiting(q, now)}</td>
                <td>
                  <span className={`pill status-${q.status}`}>{STATUS_LABELS[q.status]}</span>
                  {q.vitals_ready && q.status !== 'reviewed' && <small className="settled block">Vitals taken</small>}
                </td>
                <td className="num">
                  <Link className={q.status === 'reviewed' ? 'btn ghost' : 'btn primary'} to={`/visits/${q.id}`}>
                    {q.status === 'reviewed' ? 'View' : q.consultation ? 'Continue' : 'Visit pad'}
                  </Link>
                </td>
              </tr>
            ))}
            {queue && queue.length > 0 && shown?.length === 0 && (
              <tr className="empty">
                <td colSpan={isDoctor ? 8 : 9}>No patient matches that search.</td>
              </tr>
            )}
            {queue && queue.length === 0 && (
              <tr className="empty">
                <td colSpan={isDoctor ? 8 : 9}>No patients for this day yet. Appointments booked at the front desk appear here.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </section>
    </>
  )
}
