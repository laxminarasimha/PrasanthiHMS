import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { DateRange } from '../components/DateRange'
import { DoctorTabs } from '../components/DoctorTabs'
import { clock, longDate, monthStart, today } from '../format'
import type { ConsultRow } from '../types'

/** Every completed consultation in a date range. */
export function DoctorConsults() {
  const { user } = useAuth()
  const isDoctor = user?.role === 'doctor'
  const [from, setFrom] = useState(monthStart())
  const [to, setTo] = useState(today())
  const [query, setQuery] = useState('')
  const [rows, setRows] = useState<ConsultRow[] | null>(null)

  useEffect(() => {
    const timer = setTimeout(() => {
      api.get<ConsultRow[]>('/doctor/consults', { params: { from, to, q: query.trim() || undefined } }).then((res) => setRows(res.data))
    }, 250)
    return () => clearTimeout(timer)
  }, [from, to, query])

  return (
    <>
      <DoctorTabs />
      <section className="card">
        <div className="toolbar">
          <h1>Consults</h1>
          <DateRange
            from={from}
            to={to}
            onChange={(f, t) => {
              setFrom(f)
              setTo(t)
            }}
          />
          <input className="search" type="search" placeholder="Patient name, mobile or ID" value={query} onChange={(e) => setQuery(e.target.value)} />
          <span className="muted">
            <strong>{rows?.length ?? 0}</strong> consult{rows?.length === 1 ? '' : 's'}
          </span>
        </div>
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Patient</th>
                {!isDoctor && <th>Doctor</th>}
                <th>Diagnosis</th>
                <th>Next visit</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {rows?.map((c) => (
                <tr key={c.id} className="static">
                  <td>
                    {longDate(c.completed_at)}
                    <small className="muted block">{clock(c.completed_at)}</small>
                  </td>
                  <td>
                    <Link to={`/patients/${c.patient.id}`}>
                      <strong>{c.patient.name}</strong>
                    </Link>
                    <small className="muted block">
                      {c.patient.uhid} · {c.patient.age_label} / {c.patient.gender.charAt(0).toUpperCase()} · {c.patient.mobile}
                    </small>
                  </td>
                  {!isDoctor && <td>{c.doctor.name}</td>}
                  <td className="wrap">{c.diagnosis?.split('\n').join(', ') || <span className="muted">—</span>}</td>
                  <td>{c.follow_up_on ? longDate(c.follow_up_on) : '—'}</td>
                  <td className="num">
                    <div className="row end">
                      <Link className="btn ghost" to={`/visits/${c.appointment_id}`}>
                        Open
                      </Link>
                      <a className="btn ghost" href={`/visits/${c.appointment_id}/print`} target="_blank" rel="noreferrer">
                        Print
                      </a>
                    </div>
                  </td>
                </tr>
              ))}
              {rows && rows.length === 0 && (
                <tr className="empty">
                  <td colSpan={isDoctor ? 5 : 6}>No completed consultations in these dates.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
    </>
  )
}
