import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../api'
import { ClinicFooter, ClinicHeader } from '../components/ClinicHeader'
import { fullName, longDate } from '../format'
import type { FormRecord } from '../types'

/** A filled-in form as it is filed or handed over; opens the print dialog once loaded. */
export function FormPrint() {
  const { id } = useParams()
  const [record, setRecord] = useState<FormRecord | null>(null)

  useEffect(() => {
    api.get<FormRecord>(`/forms/${id}`).then((res) => {
      setRecord(res.data)
      setTimeout(() => window.print(), 500)
    })
  }, [id])

  if (!record) return <p className="loading">Loading form…</p>
  const values = record.values ?? {}
  const signatures = record.fields.filter((f) => f.type === 'signature')

  return (
    <div className="receipt prescription">
      <ClinicHeader>
        <h2>{record.name}</h2>
      </ClinicHeader>

      <div className="receipt-meta">
        <div>
          <strong>{record.patient ? fullName(record.patient) : ''}</strong>
          <br />
          {record.patient?.age_label} / {record.patient?.gender.charAt(0).toUpperCase()} · Patient ID: {record.patient?.uhid}
        </div>
        <div className="right">
          {record.admission && (
            <>
              IPD no: <strong>{record.admission.ipd_no}</strong>
              <br />
              Doctor: {record.admission.doctor?.name}
              <br />
            </>
          )}
          Date: {longDate(record.created_at)}
        </div>
      </div>

      {record.fields
        .filter((f) => f.type !== 'signature')
        .map((f) =>
          f.type === 'heading' ? (
            <h3 key={f.id} className="form-heading">
              {f.label}
            </h3>
          ) : (
            <p key={f.id} className="form-answer">
              <b>{f.label}:</b> {f.type === 'checkbox' ? (values[f.id] ? 'Yes' : 'No') : f.type === 'date' && values[f.id] ? longDate(String(values[f.id])) : (values[f.id] ?? '')}
            </p>
          ),
        )}

      {signatures.length > 0 && (
        <div className="lab-signatures">
          {signatures.map((f) => (
            <div key={f.id}>
              <span className="sign-line" />
              <strong>{f.label}</strong>
            </div>
          ))}
        </div>
      )}
      <ClinicFooter />
    </div>
  )
}
