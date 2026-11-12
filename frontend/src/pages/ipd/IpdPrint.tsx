import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../../api'
import { useAuth } from '../../auth'
import { ADMISSION_STATUS, clock, longDate, modeLabel, rupees } from '../../format'
import type { Admission } from '../../types'

/** Inpatient bill with the discharge summary; opens the print dialog once loaded. */
export function IpdPrint() {
  const { id } = useParams()
  const { user } = useAuth()
  const [a, setA] = useState<Admission | null>(null)

  useEffect(() => {
    api.get<Admission>(`/ipd/admissions/${id}`).then((res) => {
      setA(res.data)
      setTimeout(() => window.print(), 300)
    })
  }, [id])

  if (!a) return <p className="loading">Loading bill…</p>

  return (
    <div className="receipt prescription">
      <header>
        <h1>{user?.branch?.name ?? 'Prasanthi Hospitals'}</h1>
        {user?.branch?.city && <p>{user.branch.city}</p>}
        <h2>Inpatient {a.status === 'admitted' ? 'interim bill' : 'final bill'}</h2>
      </header>

      <div className="receipt-meta">
        <div>
          <strong>{a.patient.name}</strong>
          <br />
          {a.patient.age}y / {a.patient.gender.charAt(0).toUpperCase()} · Patient ID: {a.patient.uhid}
          <br />
          Mobile: {a.patient.mobile}
          <br />
          Doctor: {a.doctor.name}
        </div>
        <div className="right">
          IPD no: <strong>{a.ipd_no}</strong>
          <br />
          Admitted: {longDate(a.admitted_at)} {clock(a.admitted_at)}
          <br />
          {a.discharged_at ? `${ADMISSION_STATUS[a.status]}: ${longDate(a.discharged_at)} ${clock(a.discharged_at)}` : 'Still admitted'}
          {a.bed && (
            <>
              <br />
              {a.bed.ward}, bed {a.bed.bed_no}
            </>
          )}
        </div>
      </div>

      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Charge</th>
            <th className="num">Qty</th>
            <th className="num">Rate</th>
            <th className="num">Amount</th>
          </tr>
        </thead>
        <tbody>
          {a.charges?.map((c) => (
            <tr key={c.id}>
              <td>{longDate(c.charged_on)}</td>
              <td>{c.description}</td>
              <td className="num">{c.quantity}</td>
              <td className="num">{rupees(c.unit_price)}</td>
              <td className="num">{rupees(c.amount)}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <dl className="totals">
        <dt>Total charges</dt>
        <dd>{rupees(a.total)}</dd>
        {a.discount > 0 && (
          <>
            <dt>Discount</dt>
            <dd>−{rupees(a.discount)}</dd>
          </>
        )}
        <dt className="strong">Net bill</dt>
        <dd className="strong">{rupees(a.net)}</dd>
        <dt>Received</dt>
        <dd>{rupees(a.paid)}</dd>
        <dt className="strong">{a.due < 0 ? 'Advance to refund' : 'Balance due'}</dt>
        <dd className="strong">{rupees(Math.abs(a.due))}</dd>
      </dl>

      {(a.payments?.length ?? 0) > 0 && (
        <p className="receipt-payments">
          {a.payments!.map((p) => `${p.type === 'refund' ? 'Refund' : p.type === 'advance' ? 'Advance' : 'Paid'} ${rupees(p.amount)} by ${modeLabel(p.mode)}`).join(' · ')}
        </p>
      )}

      {a.discharge_summary && (
        <section>
          <h3>Discharge summary</h3>
          <p>{a.discharge_summary}</p>
        </section>
      )}

      <div className="signature">
        <strong>{a.doctor.signature_text || a.doctor.name}</strong>
      </div>
    </div>
  )
}
