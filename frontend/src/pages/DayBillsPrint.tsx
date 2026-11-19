import { Fragment, useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../api'
import { PaperBar, PaperFooter, PaperHeader } from '../components/PrintPaper'
import { longDate, modeLabel, rupees, stampTime } from '../format'
import { useAutoPrint, usePrintPaper } from '../printPaper'
import type { BillKind } from '../types'

type DayBill = {
  id: number
  bill_no: string
  kind: BillKind
  order_no: string | null
  created_at: string
  doctor: string | null
  discount: number
  total: number
  paid: number
  due: number
  cancelled_at: string | null
  items: { id: number; description: string; quantity: number; unit_price: number; amount: number }[]
  payments: { type: 'payment' | 'refund'; mode: string; amount: number }[]
}
type Day = { date: string; patient: { id: number; uhid: string; title: string | null; name: string; gender: string; age_label: string; mobile: string }; bills: DayBill[]; total: number; paid: number; due: number }

const KIND: Record<BillKind, string> = { appointment: 'Consultation and services', lab: 'Lab tests', other: 'Other services' }

/**
 * Everything billed to one patient on one day, on one sheet: each bill with its lines, then what it all comes to.
 * For the patient who was billed several times in a visit and wants a single paper to take away.
 */
export function DayBillsPrint() {
  const { id, date } = useParams()
  const [day, setDay] = useState<Day | null>(null)
  const paper = usePrintPaper()

  useEffect(() => {
    api.get<Day>('/front-desk/day-bills', { params: { patient_id: id, date } }).then((res) => setDay(res.data))
  }, [id, date])
  // A cancelled bill is a record of a mistake, not something to hand to the patient.
  const bills = day?.bills.filter((b) => !b.cancelled_at) ?? []
  useAutoPrint(Boolean(day) && bills.length > 0 && paper.ready)

  if (!day) return <p className="loading">Loading the bills…</p>
  if (bills.length === 0) return <p className="loading">Nothing was billed to this patient on {longDate(day.date)}.</p>

  // How the money came in over all the bills, by mode, with refunds taken off.
  const byMode = new Map<string, number>()
  for (const p of bills.flatMap((b) => b.payments)) byMode.set(p.mode, (byMode.get(p.mode) ?? 0) + (p.type === 'refund' ? -p.amount : p.amount))
  const modes = [...byMode.entries()].filter(([mode, amount]) => mode !== 'ipd' && Math.abs(amount) > 0.004)
  const name = `${day.patient.title ? `${day.patient.title} ` : ''}${day.patient.name}`

  return (
    <div className="receipt">
      <PaperBar paper={paper} />
      <PaperHeader paper={paper}>
        <h2>Bills for the day</h2>
      </PaperHeader>

      <div className="receipt-meta">
        <div>
          <strong>{name}</strong>
          <br />
          Patient ID: {day.patient.uhid} · {day.patient.age_label} / {day.patient.gender.charAt(0).toUpperCase()}
          <br />
          Mobile: {day.patient.mobile}
        </div>
        <div className="right">
          Date: <strong>{longDate(day.date)}</strong>
          <br />
          {bills.length} {bills.length === 1 ? 'bill' : 'bills'}: {bills.map((b) => b.bill_no).join(', ')}
        </div>
      </div>

      <table>
        <thead>
          <tr>
            <th>Service</th>
            <th className="num">Qty</th>
            <th className="num">Price</th>
            <th className="num">Amount</th>
          </tr>
        </thead>
        <tbody>
          {bills.map((b) => (
            <Fragment key={b.id}>
              <tr className="bill-group">
                <td colSpan={4}>
                  <strong>{KIND[b.kind]}</strong> · {b.bill_no}
                  {b.order_no ? ` · ${b.order_no}` : ''} · {stampTime(b.created_at)}
                  {b.doctor ? ` · ${b.doctor}` : ''}
                </td>
              </tr>
              {b.items.map((item) => (
                <tr key={item.id}>
                  <td>{item.description}</td>
                  <td className="num">{item.quantity}</td>
                  <td className="num">{rupees(item.unit_price)}</td>
                  <td className="num">{rupees(item.amount)}</td>
                </tr>
              ))}
              <tr className="bill-group-foot">
                <td colSpan={3} className="num">
                  {b.discount > 0 ? `Discount ${rupees(b.discount)} · ` : ''}
                  {b.bill_no} total
                </td>
                <td className="num">
                  <strong>{rupees(b.total)}</strong>
                </td>
              </tr>
            </Fragment>
          ))}
        </tbody>
      </table>

      <dl className="totals">
        <dt className="strong">Total for the day</dt>
        <dd className="strong">{rupees(day.total)}</dd>
        <dt>Paid</dt>
        <dd>{rupees(day.paid)}</dd>
        <dt className="strong">Balance due</dt>
        <dd className="strong">{rupees(day.due)}</dd>
      </dl>

      {modes.length > 0 && <p className="receipt-payments">{modes.map(([mode, amount]) => `${amount < 0 ? 'Refunded' : 'Paid'} ${rupees(Math.abs(amount))} by ${modeLabel(mode)}`).join(' · ')}</p>}

      <PaperFooter paper={paper} />
    </div>
  )
}
