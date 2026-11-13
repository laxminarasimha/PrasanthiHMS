import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../../api'
import { ClinicFooter, ClinicHeader } from '../../components/ClinicHeader'
import { useSignature } from '../../components/useSignature'
import { ADMISSION_STATUS, clock, longDate, fullName } from '../../format'
import type { Admission, DischargeSummary } from '../../types'
import { AFTER_PARTS, SUMMARY_PARTS } from './IpdSummary'

/** The discharge summary as handed to the patient; opens the print dialog once loaded. */
export function IpdSummaryPrint() {
  const { id } = useParams()
  const [a, setA] = useState<Admission | null>(null)
  const [s, setS] = useState<DischargeSummary | null>(null)

  useEffect(() => {
    Promise.all([api.get<Admission>(`/ipd/admissions/${id}`), api.get<DischargeSummary>(`/ipd/admissions/${id}/summary`)]).then(([admission, summary]) => {
      setA(admission.data)
      setS(summary.data)
      // A moment for the signature picture to arrive before the print dialog opens.
      setTimeout(() => window.print(), 800)
    })
  }, [id])
  const signer = s?.approver ?? a?.doctor
  const signature = useSignature(signer?.id)

  if (!a || !s) return <p className="loading">Loading summary…</p>
  if (!s.id) return <p className="loading">The discharge summary has not been written yet.</p>

  return (
    <div className="receipt prescription">
      <ClinicHeader>
        <h2>Discharge summary{s.approved_at ? '' : ' (draft, not yet approved)'}</h2>
      </ClinicHeader>

      <div className="receipt-meta">
        <div>
          <strong>{fullName(a.patient)}</strong>
          <br />
          {a.patient.age_label} / {a.patient.gender.charAt(0).toUpperCase()} · Patient ID: {a.patient.uhid}
          <br />
          Mobile: {a.patient.mobile}
          {a.patient.address && (
            <>
              <br />
              {a.patient.address}
              {a.patient.city ? `, ${a.patient.city}` : ''}
            </>
          )}
        </div>
        <div className="right">
          IPD no: <strong>{a.ipd_no}</strong>
          <br />
          Doctor: {a.doctor.name}
          <br />
          Admitted: {longDate(a.admitted_at)} {clock(a.admitted_at)}
          <br />
          {a.discharged_at ? `${ADMISSION_STATUS[a.status]}: ${longDate(a.discharged_at)} ${clock(a.discharged_at)}` : 'Still admitted'}
        </div>
      </div>

      {SUMMARY_PARTS.filter((part) => s[part.key]).map((part) => (
        <section key={part.key}>
          <h3>{part.label}</h3>
          <p className="report-text">{s[part.key] as string}</p>
        </section>
      ))}

      {(s.medicines?.length ?? 0) > 0 && (
        <section>
          <h3>Medicines on discharge</h3>
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
              {s.medicines!.map((m, i) => (
                <tr key={i}>
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

      {s.advice && (
        <section>
          <h3>Advice on discharge</h3>
          <p className="report-text">{s.advice}</p>
        </section>
      )}
      {AFTER_PARTS.filter((part) => s[part.key]).map((part) => (
        <section key={part.key}>
          <h3>{part.label}</h3>
          <p className="report-text">{s[part.key] as string}</p>
        </section>
      ))}
      {s.follow_up_on && (
        <section>
          <h3>Review on</h3>
          <p>{longDate(s.follow_up_on)}</p>
        </section>
      )}

      <div className="signature">
        {signature && <img src={signature} alt="" />}
        <strong>{signer?.signature_text || signer?.name}</strong>
      </div>
      <ClinicFooter />
    </div>
  )
}
