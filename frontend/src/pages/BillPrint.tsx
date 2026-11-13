import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../api'
import { ClinicFooter, ClinicHeader } from '../components/ClinicHeader'
import { useAuth } from '../auth'
import { longDate, modeLabel, rupees } from '../format'
import type { Bill } from '../types'

/** A plain receipt page meant for the printer; opens the print dialog once loaded. */
export function BillPrint() {
  const { id } = useParams()
  const { user } = useAuth()
  const [bill, setBill] = useState<Bill | null>(null)

  useEffect(() => {
    api.get<Bill>(`/bills/${id}`).then((res) => {
      setBill(res.data)
      setTimeout(() => window.print(), 300)
    })
  }, [id])

  if (!bill) return <p className="loading">Loading bill…</p>

  return (
    <div className="receipt">
      <ClinicHeader>
        <h2>Bill / Receipt</h2>
      </ClinicHeader>

      <div className="receipt-meta">
        <div>
          <strong>{bill.patient.name}</strong>
          <br />
          Patient ID: {bill.patient.uhid} · {bill.patient.age}y / {bill.patient.gender.charAt(0).toUpperCase()}
          <br />
          Mobile: {bill.patient.mobile}
        </div>
        <div className="right">
          Bill no: <strong>{bill.bill_no}</strong>
          <br />
          Date: {longDate(bill.created_at)}
          {bill.appointment && (
            <>
              <br />
              Doctor: {bill.appointment.doctor.name}
            </>
          )}
        </div>
      </div>

      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Service</th>
            <th className="num">Qty</th>
            <th className="num">Price</th>
            <th className="num">GST</th>
            <th className="num">Amount</th>
          </tr>
        </thead>
        <tbody>
          {bill.items.map((item, i) => (
            <tr key={item.id}>
              <td>{i + 1}</td>
              <td>{item.description}</td>
              <td className="num">{item.quantity}</td>
              <td className="num">{rupees(item.unit_price)}</td>
              <td className="num">{rupees(item.tax)}</td>
              <td className="num">{rupees(item.amount + item.tax)}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <dl className="totals">
        <dt>Subtotal</dt>
        <dd>{rupees(bill.subtotal)}</dd>
        {bill.tax > 0 && (
          <>
            <dt>GST</dt>
            <dd>{rupees(bill.tax)}</dd>
          </>
        )}
        {bill.discount > 0 && (
          <>
            <dt>Discount</dt>
            <dd>−{rupees(bill.discount)}</dd>
          </>
        )}
        <dt className="strong">Total</dt>
        <dd className="strong">{rupees(bill.total)}</dd>
        <dt>Paid</dt>
        <dd>{rupees(bill.paid)}</dd>
        <dt className="strong">Balance due</dt>
        <dd className="strong">{rupees(bill.due)}</dd>
      </dl>

      {bill.payments.length > 0 && (
        <p className="receipt-payments">
          {bill.payments
            .map((p) => `${p.type === 'refund' ? 'Refund' : 'Paid'} ${rupees(p.amount)} by ${modeLabel(p.mode)}${p.reference ? ` (${p.reference})` : ''}`)
            .join(' · ')}
        </p>
      )}

      <footer>Billed by {user?.name}. This is a computer-generated receipt.</footer>
      <ClinicFooter />
    </div>
  )
}
