import { useEffect, useState, type FormEvent } from 'react'
import { useAuth } from '../auth'
import { api, fieldErrors } from '../api'
import { PAYMENT_MODES, modeLabel, rupees } from '../format'
import type { Bill, Service } from '../types'
import { DepositBox } from './DepositBox'
import { SelectField, TextField } from './Field'
import { useBillingPreferences } from './useBillingPreferences'

type Props = {
  billId: number
  services: Service[]
  onClose: () => void
  /** Called after every change so the day list can refresh its amounts. */
  onChange: () => void
  /** Lets the front desk open another bill for the same patient and visit. */
  onAnotherBill?: (bill: Bill) => void
}

const KIND = { appointment: 'Appointment bill', lab: 'Lab bill', other: 'Other services bill' } as const

export function BillPanel({ billId, services, onClose, onChange, onAnotherBill }: Props) {
  const { user } = useAuth()
  const [bill, setBill] = useState<Bill | null>(null)
  const [error, setError] = useState('')
  const [line, setLine] = useState({ service_id: '', description: '', unit_price: '', quantity: '1', line_discount: '' })
  const [discount, setDiscount] = useState('')
  const [refundMode, setRefundMode] = useState('cash')
  const [payType, setPayType] = useState<'payment' | 'refund'>('payment')
  const [amount, setAmount] = useState('')
  const prefs = useBillingPreferences()
  const [chosenMode, setMode] = useState<string | null>(null)
  const mode = chosenMode ?? prefs.default_payment_mode
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
      return true
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
      return false
    }
  }

  const pickService = (value: string) => {
    const service = services.find((s) => String(s.id) === value)
    setLine((l) => ({ ...l, service_id: value, description: service?.name ?? '', unit_price: service ? String(service.price) : l.unit_price }))
  }

  const addLine = async (e: FormEvent) => {
    e.preventDefault()
    const ok = await run(
      api.post(`/bills/${billId}/items`, {
        service_id: line.service_id || null,
        description: line.description || null,
        unit_price: line.unit_price === '' ? null : line.unit_price,
        quantity: line.quantity || 1,
        line_discount: line.line_discount || 0,
      }),
    )
    if (ok) setLine({ service_id: '', description: '', unit_price: '', quantity: '1', line_discount: '' })
  }

  const pay = (e: FormEvent) => {
    e.preventDefault()
    run(api.post(`/bills/${billId}/payments`, { type: payType, amount, mode, reference: reference || null })).then(() => setReference(''))
  }

  if (!bill) return <aside className="card panel wide">Loading bill…</aside>

  // A discount that takes the bill below what is paid needs the difference handed back.
  const worth = bill.subtotal + bill.tax - Number(discount || 0)
  const excess = Math.round((bill.paid - worth) * 100) / 100
  const isLab = bill.kind === 'lab'

  return (
    <aside className="card panel wide">
      <div className="panel-head">
        <div>
          <h2>Bill {bill.bill_no}</h2>
          <span className="muted">
            {bill.patient.name} · {bill.patient.uhid} · {KIND[bill.kind]}
            {bill.lab_order ? ` · ${bill.lab_order.order_no}` : ''}
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
            <th className="num">Disc.</th>
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
              <td className="num">{item.discount > 0 ? rupees(item.discount) : '—'}</td>
              <td className="num">{rupees(item.amount)}</td>
              <td className="num">
                {!isLab && (
                  <button className="link danger" title="Remove from bill" onClick={() => run(api.delete(`/bills/${billId}/items/${item.id}`))}>
                    Remove
                  </button>
                )}
              </td>
            </tr>
          ))}
          {bill.items.length === 0 && (
            <tr className="empty">
              <td colSpan={6}>No services on this bill yet.</td>
            </tr>
          )}
        </tbody>
      </table>

      {isLab ? (
        <p className="muted small">Lab tests are added or changed from the Lab screen.</p>
      ) : (
        <form className="line-row" onSubmit={addLine}>
          <select value={line.service_id} onChange={(e) => pickService(e.target.value)} aria-label="Service to add">
            <option value="">Choose a service…</option>
            {services.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name} — {rupees(s.price)}
              </option>
            ))}
          </select>
          <input placeholder="or type a charge" value={line.description} onChange={(e) => setLine((l) => ({ ...l, description: e.target.value }))} aria-label="Charge description" />
          <input type="number" min={0} step="0.01" placeholder="Price ₹" value={line.unit_price} disabled={Boolean(line.service_id) && !user?.can_change_price} onChange={(e) => setLine((l) => ({ ...l, unit_price: e.target.value }))} aria-label="Price" />
          <input type="number" min={1} placeholder="Qty" value={line.quantity} onChange={(e) => setLine((l) => ({ ...l, quantity: e.target.value }))} aria-label="Quantity" />
          <input type="number" min={0} step="0.01" placeholder="Disc. ₹" value={line.line_discount} onChange={(e) => setLine((l) => ({ ...l, line_discount: e.target.value }))} aria-label="Line discount" />
          <button className="btn ghost" disabled={(!line.service_id && !line.description.trim()) || line.unit_price === ''}>
            Add
          </button>
        </form>
      )}

      <dl className="totals">
        <dt>Subtotal</dt>
        <dd>{rupees(bill.subtotal)}</dd>
        {bill.tax > 0 && (
          <>
            <dt>GST</dt>
            <dd>{rupees(bill.tax)}</dd>
          </>
        )}
        <dt>Bill discount</dt>
        <dd>
          <form
            className="row"
            onSubmit={(e) => {
              e.preventDefault()
              run(api.put(`/bills/${billId}`, { discount: discount || 0, ...(excess > 0 ? { refund_excess: true, refund_mode: refundMode } : {}) }))
            }}
          >
            <input className="qty" type="number" min={0} step="0.01" value={discount} onChange={(e) => setDiscount(e.target.value)} aria-label="Discount amount" />
            <button className="btn ghost" disabled={Number(discount || 0) === bill.discount}>
              {excess > 0 ? `Apply and refund ${rupees(excess)}` : 'Apply'}
            </button>
          </form>
          {excess > 0 && (
            <div className="row refund-by">
              <small className="muted">Refund by</small>
              <select value={refundMode} onChange={(e) => setRefundMode(e.target.value)} aria-label="Refund mode">
                {PAYMENT_MODES.map((m) => (
                  <option key={m.value} value={m.value}>
                    {m.label}
                  </option>
                ))}
              </select>
            </div>
          )}
        </dd>
        <dt className="strong">Total</dt>
        <dd className="strong">{rupees(bill.total)}</dd>
        <dt>Paid</dt>
        <dd>{rupees(bill.paid)}</dd>
        <dt className="strong">Due</dt>
        <dd className={bill.due > 0 ? 'strong due' : 'strong settled'}>{rupees(bill.due)}</dd>
      </dl>

      <DepositBox patientId={bill.patient.id} balance={bill.deposit_balance ?? 0} onChange={(balance) => setBill((b) => (b ? { ...b, deposit_balance: balance } : b))} />
      {(bill.deposit_balance ?? 0) > 0 && bill.due > 0 && (
        <button type="button" className="btn ghost gap-top" onClick={() => run(api.post(`/bills/${billId}/use-deposit`, { amount: Math.min(bill.deposit_balance ?? 0, bill.due) }))}>
          Pay {rupees(Math.min(bill.deposit_balance ?? 0, bill.due))} from the deposit
        </button>
      )}

      {error && <p className="form-error">{error}</p>}

      <h3>{payType === 'payment' ? 'Collect payment' : 'Give refund'}</h3>
      <form onSubmit={pay}>
        <div className="grid three">
          <TextField label="Amount (₹)" required type="number" min={0} step="0.01" value={amount} onChange={setAmount} />
          <SelectField label="Mode" required value={mode} onChange={setMode} options={PAYMENT_MODES} />
          <TextField label="Reference" placeholder="optional" value={reference} onChange={setReference} />
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

      {onAnotherBill && (
        <button type="button" className="link add-medicine" onClick={() => onAnotherBill(bill)}>
          + Add another bill for {bill.patient.name}
        </button>
      )}
    </aside>
  )
}
