import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { longDate } from '../format'
import type { Visit } from '../types'

/** The prescription as it is handed to the patient; opens the print dialog once loaded. */
export function PrescriptionPrint() {
  const { id } = useParams()
  const { user } = useAuth()
  const [visit, setVisit] = useState<Visit | null>(null)

  useEffect(() => {
    api.get<Visit>(`/appointments/${id}/consultation`).then((res) => {
      setVisit(res.data)
      setTimeout(() => window.print(), 300)
    })
  }, [id])

  if (!visit) return <p className="loading">Loading prescription…</p>
  const c = visit.consultation
  if (!c) return <p className="loading">Nothing has been recorded for this visit yet.</p>

  const vitals = [
    c.weight_kg && `Weight ${c.weight_kg} kg`,
    c.height_cm && `Height ${c.height_cm} cm`,
    c.bp_systolic && `BP ${c.bp_systolic}/${c.bp_diastolic} mmHg`,
    c.pulse && `Pulse ${c.pulse}/min`,
    c.temperature_f && `Temp ${c.temperature_f} °F`,
    c.spo2 && `SpO₂ ${c.spo2}%`,
  ].filter(Boolean)

  return (
    <div className="receipt prescription">
      <header>
        <h1>{user?.branch?.name ?? 'Prasanthi Hospitals'}</h1>
        {user?.branch?.city && <p>{user.branch.city}</p>}
      </header>

      <div className="receipt-meta">
        <div>
          <strong>{visit.patient.name}</strong>
          <br />
          {visit.patient.age}y / {visit.patient.gender.charAt(0).toUpperCase()} · Patient ID: {visit.patient.uhid}
          <br />
          Mobile: {visit.patient.mobile}
        </div>
        <div className="right">
          <strong>{visit.doctor.name}</strong>
          {visit.doctor.department && (
            <>
              <br />
              {visit.doctor.department}
            </>
          )}
          <br />
          Date: {longDate(c.completed_at ?? visit.appointment.scheduled_at)}
        </div>
      </div>

      {vitals.length > 0 && <p className="rx-vitals">{vitals.join(' · ')}</p>}
      {c.complaints && <section><h3>Complaints</h3><p>{c.complaints}</p></section>}
      {c.findings && <section><h3>Findings</h3><p>{c.findings}</p></section>}
      {c.diagnosis && <section><h3>Diagnosis</h3><p>{c.diagnosis}</p></section>}

      {c.medicines.length > 0 && (
        <section>
          <h3>℞ Medicines</h3>
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Medicine</th>
                <th>Dose</th>
                <th>Frequency</th>
                <th>When</th>
                <th>Duration</th>
              </tr>
            </thead>
            <tbody>
              {c.medicines.map((m, i) => (
                <tr key={m.id}>
                  <td>{i + 1}</td>
                  <td>
                    <strong>{m.medicine}</strong>
                    {m.instructions && <small className="block">{m.instructions}</small>}
                  </td>
                  <td>{m.dose}</td>
                  <td>{m.frequency}</td>
                  <td>{m.timing}</td>
                  <td>{m.duration}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}

      {c.tests_advised && <section><h3>Tests advised</h3><p>{c.tests_advised}</p></section>}
      {c.advice && <section><h3>Advice</h3><p>{c.advice}</p></section>}
      {c.follow_up_on && <section><h3>Follow-up</h3><p>{longDate(c.follow_up_on)}</p></section>}

      <div className="signature">
        <strong>{visit.doctor.signature_text || visit.doctor.name}</strong>
      </div>
    </div>
  )
}
