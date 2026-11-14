import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../api'
import { ClinicFooter, ClinicHeader } from '../components/ClinicHeader'
import { useSignature } from '../components/useSignature'
import { fullName, longDate } from '../format'

type Printed = {
  id: number
  kind: string
  doc_no: string
  title: string
  addressed_to: string | null
  body: string
  issued_on: string
  cancelled_at: string | null
  cancel_reason: string | null
  doctor: { id: number; name: string; department: string | null; signature_text: string | null } | null
  patient: { uhid: string; name: string; title?: string | null; gender: string; age_label: string; mobile: string; address?: string | null; city?: string | null }
}

/** A certificate, letter or consent form on the hospital's letterhead; opens the print dialog once loaded. */
export function DocumentPrint() {
  const { id } = useParams()
  const [doc, setDoc] = useState<Printed | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    api
      .get<Printed>(`/documents/${id}`)
      .then((res) => {
        setDoc(res.data)
        // A cancelled paper is shown for the record but not sent to the printer on its own.
        if (!res.data.cancelled_at) setTimeout(() => window.print(), 800)
      })
      .catch(() => setError('This document could not be opened.'))
  }, [id])
  const signature = useSignature(doc?.doctor?.id)
  const seal = useSignature(doc?.doctor?.id, 0, 'seal')

  if (error) return <p className="form-error">{error}</p>
  if (!doc) return <p className="loading">Loading document…</p>
  const p = doc.patient

  return (
    <div className={`receipt prescription letter ${doc.cancelled_at ? 'cancelled' : ''}`}>
      <ClinicHeader>
        <h2>{doc.title}</h2>
      </ClinicHeader>

      {doc.cancelled_at && <p className="cancelled-stamp">CANCELLED on {longDate(doc.cancelled_at)}: {doc.cancel_reason}</p>}

      <div className="receipt-meta">
        <div>
          {doc.addressed_to && (
            <>
              To: <strong>{doc.addressed_to}</strong>
              <br />
            </>
          )}
          Patient: <strong>{fullName(p)}</strong>
          <br />
          {p.age_label} / {p.gender.charAt(0).toUpperCase()} · Patient ID: {p.uhid}
        </div>
        <div className="right">
          No: <strong>{doc.doc_no}</strong>
          <br />
          Date: {longDate(doc.issued_on)}
        </div>
      </div>

      <div className="letter-body">{doc.body}</div>

      <div className="signature">
        {signature && <img src={signature} alt="" />}
        {seal ? <img className="seal" src={seal} alt="" /> : <strong>{doc.doctor?.signature_text || doc.doctor?.name}</strong>}
        {doc.doctor?.department && !seal && <small className="block">{doc.doctor.department}</small>}
      </div>
      <ClinicFooter />
    </div>
  )
}
