import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
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

  return (
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
        <span className="muted grow">
          Pending: <strong>{pending}</strong> · Completed: <strong>{done}</strong>
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
            {queue?.map((q) => (
              <tr key={q.id} className="static">
                <td className="mono">{clock(q.scheduled_at)}</td>
                <td>
                  <strong>{q.patient.name}</strong>
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
                </td>
                <td className="num">
                  <Link className={q.status === 'reviewed' ? 'btn ghost' : 'btn primary'} to={`/visits/${q.id}`}>
                    {q.status === 'reviewed' ? 'View' : q.consultation ? 'Continue' : 'Visit pad'}
                  </Link>
                </td>
              </tr>
            ))}
            {queue && queue.length === 0 && (
              <tr className="empty">
                <td colSpan={isDoctor ? 8 : 9}>No patients for this day yet. Appointments booked at the front desk appear here.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </section>
  )
}
