import { useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../api'
import { PAYMENT_MODES, modeLabel, rupees } from '../format'
import type { Bill, Service } from '../types'
import { SelectField, TextField } from './Field'

type Props = {
  billId: number
  services: Service[]
  onClose: () => void
  /** Called after every change so the day list can refresh its amounts. */
  onChange: () => void
}

export function BillPanel({ billId, services, onClose, onChange }: Props) {
  const [bill, setBill] = useState<Bill | null>(null)
  const [error, setError] = useState('')
  const [serviceId, setServiceId] = useState('')
  const [quantity, setQuantity] = useState('1')
  const [discount, setDiscount] = useState('')
  const [payType, setPayType] = useState<'payment' | 'refund'>('payment')
  const [amount, setAmount] = useState('')
  const [mode, setMode] = useState('cash')
  const [reference, setReference] = useState('')

  const apply = (next: Bill) => {
    setBill(next)
    setDiscount(String(next.discount))
    setAmount(next.due > 0 ? String(next.due) : '')
    setPayType('payment')
    setError('')
  }

  useEffect(() => {
    api.get<Bill>(`/bills/${billId}`).then((res) => apply(res.data))
  }, [billId])

  // Runs one change against the API, then shows the updated bill or the reason it was refused.
  const run = async (request: Promise<{ data: Bill }>) => {
    try {
      apply((await request).data)
      onChange()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    }
  }

  const addItem = (e: FormEvent) => {
    e.preventDefault()
    if (!serviceId) return
    run(api.post(`/bills/${billId}/items`, { service_id: serviceId, quantity })).then(() => {
      setServiceId('')
      setQuantity('1')
    })
  }

  const pay = (e: FormEvent) => {
    e.preventDefault()
    run(api.post(`/bills/${billId}/payments`, { type: payType, amount, mode, reference: reference || null })).then(() =>
      setReference(''),
    )
  }

  if (!bill) return <aside className="card panel">Loading bill…</aside>

  return (
    <aside className="card panel">
      <div className="panel-head">
        <div>
          <h2>Bill {bill.bill_no}</h2>
          <span className="muted">
            {bill.patient.name} · {bill.patient.uhid}
          </span>
        </div>
        <div className="row">
          <a className="btn ghost" href={`/bills/${bill.id}/print`} target="_blank" rel="noreferrer">
            Print
          </a>
          <button type="button" className="btn ghost" onClick={onClose}>
            Close
          </button>
        </div>
      </div>

      <table className="compact">
        <thead>
          <tr>
            <th>Service</th>
            <th className="num">Qty</th>
            <th className="num">Price</th>
            <th className="num">Amount</th>
            <th />
          </tr>
        </thead>
        <tbody>
          {bill.items.map((item) => (
            <tr key={item.id} className="static">
              <td>
                {item.description}
                {item.gst_percent > 0 && <small className="muted"> +{item.gst_percent}% GST</small>}
              </td>
              <td className="num">{item.quantity}</td>
              <td className="num">{rupees(item.unit_price)}</td>
              <td className="num">{rupees(item.amount)}</td>
              <td className="num">
                <button className="link danger" title="Remove from bill" onClick={() => run(api.delete(`/bills/${billId}/items/${item.id}`))}>
                  Remove
                </button>
              </td>
            </tr>
          ))}
          {bill.items.length === 0 && (
            <tr className="empty">
              <td colSpan={5}>No services on this bill yet.</td>
            </tr>
          )}
        </tbody>
      </table>

      <form className="row add-row" onSubmit={addItem}>
        <select value={serviceId} onChange={(e) => setServiceId(e.target.value)} aria-label="Service to add">
          <option value="">Add a service…</option>
          {services.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name} — {rupees(s.price)}
            </option>
          ))}
        </select>
        <input className="qty" type="number" min={1} value={quantity} onChange={(e) => setQuantity(e.target.value)} aria-label="Quantity" />
        <button className="btn ghost" disabled={!serviceId}>
          Add
        </button>
      </form>

      <dl className="totals">
        <dt>Subtotal</dt>
        <dd>{rupees(bill.subtotal)}</dd>
        {bill.tax > 0 && (
          <>
            <dt>GST</dt>
            <dd>{rupees(bill.tax)}</dd>
          </>
        )}
        <dt>Discount</dt>
        <dd>
          <form
            className="row"
            onSubmit={(e) => {
              e.preventDefault()
              run(api.put(`/bills/${billId}`, { discount: discount || 0 }))
            }}
          >
            <input className="qty" type="number" min={0} step="0.01" value={discount} onChange={(e) => setDiscount(e.target.value)} aria-label="Discount amount" />
            <button className="btn ghost" disabled={Number(discount || 0) === bill.discount}>
              Apply
            </button>
          </form>
        </dd>
        <dt className="strong">Total</dt>
        <dd className="strong">{rupees(bill.total)}</dd>
        <dt>Paid</dt>
        <dd>{rupees(bill.paid)}</dd>
        <dt className="strong">Due</dt>
        <dd className={bill.due > 0 ? 'strong due' : 'strong settled'}>{rupees(bill.due)}</dd>
      </dl>

      {error && <p className="form-error">{error}</p>}

      <h3>{payType === 'payment' ? 'Collect payment' : 'Give refund'}</h3>
      <form onSubmit={pay}>
        <div className="grid">
          <TextField label="Amount (₹)" required type="number" min={0} step="0.01" value={amount} onChange={setAmount} />
          <SelectField label="Mode" required value={mode} onChange={setMode} options={PAYMENT_MODES} />
          <div className="span-2">
            <TextField label="Reference (optional)" placeholder="UPI or card transaction number" value={reference} onChange={setReference} />
          </div>
        </div>
        <div className="panel-foot between">
          <button
            type="button"
            className="link"
            onClick={() => {
              setPayType(payType === 'payment' ? 'refund' : 'payment')
              setAmount('')
            }}
          >
            {payType === 'payment' ? 'Give a refund instead' : 'Back to collecting payment'}
          </button>
          <button className="btn primary" disabled={!amount || Number(amount) <= 0}>
            {payType === 'payment' ? 'Collect payment' : 'Refund'}
          </button>
        </div>
      </form>

      {bill.payments.length > 0 && (
        <>
          <h3>Payment history</h3>
          <ul className="history">
            {bill.payments.map((p) => (
              <li key={p.id}>
                <span>
                  {p.type === 'refund' ? 'Refund' : 'Payment'} · {modeLabel(p.mode)}
                  {p.reference ? ` · ${p.reference}` : ''}
                  <small className="muted">
                    {' '}
                    {new Date(p.created_at).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: 'numeric', minute: '2-digit' })}
                    {p.receiver ? ` by ${p.receiver.name}` : ''}
                  </small>
                </span>
                <strong className={p.type === 'refund' ? 'due' : ''}>
                  {p.type === 'refund' ? '−' : ''}
                  {rupees(p.amount)}
                </strong>
              </li>
            ))}
          </ul>
        </>
      )}
    </aside>
  )
}
