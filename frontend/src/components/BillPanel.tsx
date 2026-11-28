import { useEffect, useState, type FormEvent } from 'react'
import { DiscountWhy } from './DiscountWhy'
import { noWhy, whyFields } from './discountFields'
import { useAuth } from '../auth'
import { api, fieldErrors } from '../api'
import { PAYMENT_MODES, modeLabel, rupees, stampDate, today } from '../format'
import type { Bill, Payment, Service } from '../types'
import { DepositBox } from './DepositBox'
import { SelectField, TextField } from './Field'
import { useBillingPreferences } from './useBillingPreferences'
import { PatientLink } from './PatientLink'
import { EarlierDate } from './EarlierDate'
import { dayOf, earlier } from '../earlierDay'
import { LabOrderLink } from './RecordLinks'

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
  const [why, setWhy] = useState(noWhy)
  const [refundMode, setRefundMode] = useState('cash')
  const [payType, setPayType] = useState<'payment' | 'refund'>('payment')
  const [amount, setAmount] = useState('')
  const prefs = useBillingPreferences()
  const [chosenMode, setMode] = useState<string | null>(null)
  const mode = chosenMode ?? prefs.default_payment_mode
  const [reference, setReference] = useState('')
  const [paidOn, setPaidOn] = useState(today)
  // The payment whose mode is being corrected, with what is typed for it.
  const [fixing, setFixing] = useState<Payment | null>(null)
  const [fix, setFix] = useState({ mode: 'cash', reference: '', reason: '' })
  const [notice, setNotice] = useState('')
  // Cancelling a bill made by mistake: why, and how the money already paid goes back.
  const [cancelling, setCancelling] = useState({ reason: '', mode: 'cash' })

  const apply = (next: Bill) => {
    setBill(next)
    setDiscount(String(next.discount))
    setWhy(noWhy)
    setAmount(next.due > 0 ? String(next.due) : '')
    setPayType('payment')
    setError('')
    setFixing(null)
  }

  useEffect(() => {
    api.get<Bill>(`/bills/${billId}`).then((res) => apply(res.data))
  }, [billId])

  // Runs one change against the API, then shows the updated bill or the reason it was refused.
  /** True while something is being saved, so the money buttons cannot be pressed a second time meanwhile. */
  const [busy, setBusy] = useState(false)
  const run = async (request: Promise<{ data: Bill }>) => {
    setBusy(true)
    try {
      apply((await request).data)
      onChange()
      return true
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
      return false
    } finally {
      setBusy(false)
    }
  }

  const pickService = (value: string) => {
    const service = services.find((s) => String(s.id) === value)
    setLine((l) => ({ ...l, service_id: value, description: service?.name ?? '', unit_price: service ? String(service.price) : l.unit_price }))
  }

  // A discount on a line is explained once per bill; a new bill discount is explained each time the amount changes.
  const lineNeedsWhy = Number(line.line_discount || 0) > 0 && !bill?.discount_reason
  const billNeedsWhy = Number(discount || 0) > 0 && Number(discount || 0) !== bill?.discount

  const addLine = async (e: FormEvent) => {
    e.preventDefault()
    const ok = await run(
      api.post(`/bills/${billId}/items`, {
        service_id: line.service_id || null,
        description: line.description || null,
        unit_price: line.unit_price === '' ? null : line.unit_price,
        quantity: line.quantity || 1,
        line_discount: line.line_discount || 0,
        ...(lineNeedsWhy ? whyFields(why) : {}),
      }),
    )
    if (ok) setLine({ service_id: '', description: '', unit_price: '', quantity: '1', line_discount: '' })
  }

  const pay = (e: FormEvent) => {
    e.preventDefault()
    run(api.post(`/bills/${billId}/payments`, { type: payType, amount, mode, reference: reference || null, paid_on: payType === 'payment' ? earlier(paidOn) : null })).then(() => {
      setReference('')
      setPaidOn(today())
    })
  }

  const fixMode = async (e: FormEvent) => {
    e.preventDefault()
    if (!fixing) return
    setNotice('')
    const ok = await run(api.put(`/bills/${billId}/payments/${fixing.id}`, { mode: fix.mode, reference: fix.reference.trim() || null, reason: fix.reason }))
    if (ok) setNotice(`Recorded as ${modeLabel(fix.mode)} now. The change is kept in the record with your reason.`)
  }
  // Staff who take money on these bills may correct how it was entered.
  const canFix = Boolean(user?.can_front_desk || user?.can_run_lab)

  if (!bill) return <aside className="card panel wide">Loading bill…</aside>

  // A cancelled bill is only a record: what it was, who cancelled it and why. Nothing on it can be changed.
  if (bill.cancelled_at) {
    return (
      <aside className="card panel wide">
        <div className="panel-head">
          <div>
            <h2>
              Bill {bill.bill_no} <span className="pill off">Cancelled</span>
            </h2>
            <span className="muted">
              <PatientLink id={bill.patient.id} bills>
                {bill.patient.name}
              </PatientLink>{' '}
              · {bill.patient.uhid} · {KIND[bill.kind]}
            </span>
          </div>
          <button type="button" className="btn ghost" onClick={onClose}>
            Close
          </button>
        </div>
        <p className="form-error">
          Cancelled on {stampDate(bill.cancelled_at)}
          {bill.canceller ? ` by ${bill.canceller.name}` : ''}: {bill.cancel_reason}
        </p>
        <table className="compact">
          <tbody>
            {bill.items.map((item) => (
              <tr key={item.id} className="static faded">
                <td>
                  <s>{item.description}</s>
                </td>
                <td className="num">{item.quantity}</td>
                <td className="num">
                  <s>{rupees(item.amount + item.tax)}</s>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        <p className="muted small">This bill counts for nothing now. Its lines are kept only as a record of what was entered.</p>
        {bill.payments.length > 0 && (
          <ul className="history">
            {bill.payments.map((p) => (
              <li key={p.id}>
                <span>
                  {p.type === 'refund' ? 'Refunded' : 'Paid'} · {modeLabel(p.mode)}
                  {p.reference ? ` · ${p.reference}` : ''}
                </span>
                <strong>{rupees(p.amount)}</strong>
              </li>
            ))}
          </ul>
        )}
      </aside>
    )
  }

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
            <PatientLink id={bill.patient.id} bills>
              {bill.patient.name}
            </PatientLink>{' '}
            · {bill.patient.uhid} · {KIND[bill.kind]}
            {bill.lab_order ? (
              <>
                {' · '}
                <LabOrderLink id={bill.lab_order.id}>{bill.lab_order.order_no}</LabOrderLink>
              </>
            ) : null}
          </span>
          {bill.entered_at && (
            <small className="muted block">
              Dated {stampDate(bill.created_at)} · entered on {stampDate(bill.entered_at)}
            </small>
          )}
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
          <button className="btn ghost" disabled={busy || (!line.service_id && !line.description.trim()) || line.unit_price === ''}>
            Add
          </button>
        </form>
      )}

      {lineNeedsWhy && <DiscountWhy id="line-why" value={why} onChange={setWhy} />}

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
              run(api.put(`/bills/${billId}`, { discount: discount || 0, ...(excess > 0 ? { refund_excess: true, refund_mode: refundMode } : {}), ...(billNeedsWhy ? whyFields(why) : {}) }))
            }}
          >
            <input className="qty" type="number" min={0} step="0.01" value={discount} onChange={(e) => setDiscount(e.target.value)} aria-label="Discount amount" />
            <button className="btn ghost" disabled={busy || Number(discount || 0) === bill.discount}>
              {excess > 0 ? `Apply and refund ${rupees(excess)}` : 'Apply'}
            </button>
          </form>
          {billNeedsWhy && <DiscountWhy id="bill-why" value={why} onChange={setWhy} />}
          {!billNeedsWhy && bill.discount_reason && (
            <small className="muted block">
              {bill.discount_reason} · allowed by {bill.discount_allowed_by}
            </small>
          )}
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
        <button type="button" className="btn ghost gap-top" disabled={busy} onClick={() => run(api.post(`/bills/${billId}/use-deposit`, { amount: Math.min(bill.deposit_balance ?? 0, bill.due) }))}>
          Pay {rupees(Math.min(bill.deposit_balance ?? 0, bill.due))} from the deposit
        </button>
      )}

      {error && !fixing && <p className="form-error">{error}</p>}

      <h3>{payType === 'payment' ? 'Collect payment' : 'Give refund'}</h3>
      <form onSubmit={pay}>
        <div className="grid three">
          <TextField label="Amount (₹)" required type="number" min={0} step="0.01" value={amount} onChange={setAmount} />
          <SelectField label="Mode" required value={mode} onChange={setMode} options={PAYMENT_MODES} />
          <TextField label="Reference" placeholder="optional" value={reference} onChange={setReference} />
        </div>
        {payType === 'payment' && <EarlierDate what="money" value={paidOn} onChange={setPaidOn} min={dayOf(bill.created_at)} />}
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
          <button className="btn primary" disabled={busy || !amount || Number(amount) <= 0}>
            {payType === 'payment' ? 'Collect payment' : 'Refund'}
          </button>
        </div>
      </form>

      {bill.payments.length > 0 && (
        <>
          <h3>Payment history</h3>
          {notice && <p className="notice">{notice}</p>}
          <ul className="history payments-list">
            {bill.payments.map((p) => (
              <li key={p.id}>
                <span>
                  {p.type === 'refund' ? 'Refund' : 'Payment'} · {modeLabel(p.mode)}
                  {p.reference ? ` · ${p.reference}` : ''}
                  <small className="muted">
                    {' '}
                    {new Date(p.created_at).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: 'numeric', minute: '2-digit' })}
                    {p.entered_at ? ` (entered on ${stampDate(p.entered_at)})` : ''}
                    {p.receiver ? ` by ${p.receiver.name}` : ''}
                  </small>
                </span>
                {canFix && PAYMENT_MODES.some((m) => m.value === p.mode) && (
                  <button
                    type="button"
                    className="link"
                    title="Entered under the wrong mode? Correct it here"
                    onClick={() => {
                      setFixing(p)
                      setFix({ mode: p.mode, reference: p.reference ?? '', reason: '' })
                      setNotice('')
                      setError('')
                    }}
                  >
                    Change mode
                  </button>
                )}
                <strong className={p.type === 'refund' ? 'due' : ''}>
                  {p.type === 'refund' ? '−' : ''}
                  {rupees(p.amount)}
                </strong>
              </li>
            ))}
          </ul>
          {fixing && (
            <form className="action-box" onSubmit={fixMode}>
              <h3>Change how this was paid</h3>
              <p className="muted small">
                {fixing.type === 'refund' ? 'Refund' : 'Payment'} of {rupees(fixing.amount)}, recorded as <strong>{modeLabel(fixing.mode)}</strong>. Only the mode changes: the amount and the day it was taken stay as they are.
              </p>
              <div className="grid">
                <SelectField label="Mode" required value={fix.mode} onChange={(v) => setFix({ ...fix, mode: v })} options={PAYMENT_MODES} />
                <TextField label="Reference" placeholder="UPI or card slip number, optional" value={fix.reference} onChange={(v) => setFix({ ...fix, reference: v })} />
                <div className="span-2">
                  <TextField label="Reason for the change" required value={fix.reason} onChange={(v) => setFix({ ...fix, reason: v })} />
                </div>
              </div>
              {error && <p className="form-error">{error}</p>}
              <div className="panel-foot row">
                <button type="button" className="btn ghost" onClick={() => setFixing(null)}>
                  Cancel
                </button>
                <button className="btn primary" disabled={fix.reason.trim().length < 3 || (fix.mode === fixing.mode && fix.reference.trim() === (fixing.reference ?? ''))}>
                  Save as {modeLabel(fix.mode)}
                </button>
              </div>
            </form>
          )}
        </>
      )}

      {user?.can_delete_bills && (
        <details className="cancel-bill">
          <summary>Cancel this bill</summary>
          <form
            onSubmit={(e) => {
              e.preventDefault()
              void run(api.post(`/bills/${billId}/cancel`, { reason: cancelling.reason, ...(bill.paid > 0 ? { refund_mode: cancelling.mode } : {}) }))
            }}
          >
            <p className="muted small">
              For a bill made by mistake. It stays on record as cancelled and counts for nothing{bill.paid > 0 ? `; the ${rupees(bill.paid)} already paid is given back` : ''}
              {isLab ? '. Its lab order is removed from the lab list' : ''}. This cannot be undone.
            </p>
            <div className={bill.paid > 0 ? 'grid' : ''}>
              <TextField label="Reason" required value={cancelling.reason} onChange={(reason) => setCancelling((c) => ({ ...c, reason }))} placeholder="for example: entered for the wrong patient" />
              {bill.paid > 0 && <SelectField label={`Refund ${rupees(bill.paid)} by`} required value={cancelling.mode} onChange={(mode) => setCancelling((c) => ({ ...c, mode }))} options={PAYMENT_MODES.filter((m) => m.value !== 'insurance')} />}
            </div>
            {error && <p className="form-error">{error}</p>}
            <div className="panel-foot">
              <button className="btn danger-btn" disabled={busy || cancelling.reason.trim().length < 3}>
                {busy ? 'Saving…' : bill.paid > 0 ? `Cancel bill and refund ${rupees(bill.paid)}` : 'Cancel bill'}
              </button>
            </div>
          </form>
        </details>
      )}

      {onAnotherBill && (
        <button type="button" className="link add-medicine" onClick={() => onAnotherBill(bill)}>
          + Add another bill for {bill.patient.name}
        </button>
      )}
    </aside>
  )
}
