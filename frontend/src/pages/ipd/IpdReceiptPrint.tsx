import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../../api'
import { ClinicFooter, ClinicHeader } from '../../components/ClinicHeader'
import { fullName, modeLabel, rupees } from '../../format'
import type { Admission } from '../../types'

const TITLE = { advance: 'Advance receipt', payment: 'Payment receipt', refund: 'Refund voucher' } as const

/** A receipt for one advance, payment or refund on an IPD case; opens the print dialog once loaded. */
export function IpdReceiptPrint() {
  const { id, paymentId } = useParams()
  const [a, setA] = useState<Admission | null>(null)

  useEffect(() => {
    api.get<Admission>(`/ipd/admissions/${id}`).then((res) => {
      setA(res.data)
      setTimeout(() => window.print(), 500)
    })
  }, [id])

  if (!a) return <p className="loading">Loading receipt…</p>
  const payment = a.payments?.find((p) => String(p.id) === paymentId)
  if (!payment) return <p className="loading">This payment is no longer on the case.</p>

  return (
    <div className="receipt prescription">
      <ClinicHeader>
        <h2>{TITLE[payment.type]}</h2>
      </ClinicHeader>

      <div className="receipt-meta">
        <div>
          <strong>{fullName(a.patient)}</strong>
          <br />
          {a.patient.age_label} / {a.patient.gender.charAt(0).toUpperCase()} · Patient ID: {a.patient.uhid}
          <br />
          Mobile: {a.patient.mobile}
        </div>
        <div className="right">
          IPD no: <strong>{a.ipd_no}</strong>
          <br />
          Receipt no: {a.ipd_no}-{payment.id}
          <br />
          {/* Stored in world time, so it is turned into the hospital's own time here. */}
          Date: {new Date(payment.created_at).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' })}
        </div>
      </div>

      <dl className="totals">
        <dt className="strong">{payment.type === 'refund' ? 'Refunded' : 'Received'}</dt>
        <dd className="strong">{rupees(payment.amount)}</dd>
        <dt>By</dt>
        <dd>
          {modeLabel(payment.mode)}
          {payment.reference ? ` · ${payment.reference}` : ''}
        </dd>
        {payment.remarks && (
          <>
            <dt>Remarks</dt>
            <dd>{payment.remarks}</dd>
          </>
        )}
        <dt>Total received on this case so far</dt>
        <dd>{rupees(a.paid)}</dd>
      </dl>

      <div className="signature">
        <strong>{payment.receiver?.name ?? 'Cashier'}</strong>
      </div>
      <ClinicFooter />
    </div>
  )
}
