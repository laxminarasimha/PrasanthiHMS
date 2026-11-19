import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../api'
import { PAYMENT_MODES, longDate, modeLabel, rupees, settlement, stampTime } from '../format'
import type { BillKind } from '../types'
import { SelectField, TextField } from './Field'
import { PatientLink } from './PatientLink'
import { useBillingPreferences } from './useBillingPreferences'

type DayBill = {
  id: number
  bill_no: string
  kind: BillKind
  order_no: string | null
  created_at: string
  appointment_id: number | null
  doctor: string | null
  subtotal: number
  discount: number
  tax: number
  total: number
  paid: number
  due: number
  moved_to_ipd: number
  cancelled_at: string | null
  cancel_reason: string | null
  items: { id: number; description: string; quantity: number; unit_price: number; discount: number; amount: number }[]
  payments: { type: 'payment' | 'refund'; mode: string; amount: number }[]
}
type Day = { date: string; patient: { id: number; uhid: string; title: string | null; name: string; gender: string; age_label: string; mobile: string }; bills: DayBill[]; total: number; paid: number; due: number }

type Props = {
  patient: { id: number; name: string; uhid: string }
  date: string
  onClose: () => void
  /** Called after money is taken, so the day list behind can refresh its amounts. */
  onChange: () => void
  /** Opens one bill by itself: to discount it, refund it or change its lines. */
  onOpenBill: (billId: number) => void
  /** Goes on to add services or lab tests for this patient. */
  onAdd: () => void
}

const KIND: Record<BillKind, string> = { appointment: 'Consultation and services', lab: 'Lab tests', other: 'Other services' }

/**
 * Everything billed to one patient on one day, in one window: the consultation, each lot of lab tests and any
 * other services, with what each came to and what is still owed. One payment settles all of it.
 */
export function DayBills({ patient, date, onClose, onChange, onOpenBill, onAdd }: Props) {
  const prefs = useBillingPreferences()
  const [day, setDay] = useState<Day | null>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const [amount, setAmount] = useState('')
  const [chosenMode, setMode] = useState<string | null>(null)
  const mode = chosenMode ?? prefs.default_payment_mode
  const [reference, setReference] = useState('')
  const box = useRef<HTMLDivElement>(null)

  const load = useCallback(
    () =>
      api
        .get<Day>('/front-desk/day-bills', { params: { patient_id: patient.id, date } })
        .then((res) => {
          setDay(res.data)
          setAmount(res.data.due > 0 ? String(res.data.due) : '')
        })
        .catch((err) => setError(Object.values(fieldErrors(err))[0] ?? 'Could not load the bills. Close this and try again.')),
    [patient.id, date],
  )
  useEffect(() => {
    void load()
  }, [load])

  // Escape closes the window, as people expect of one that sits over the page.
  useEffect(() => {
    box.current?.focus()
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const owing = day?.bills.filter((b) => b.due > 0) ?? []
  const live = day?.bills.filter((b) => !b.cancelled_at) ?? []
  const collect = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError('')
    setNotice('')
    try {
      await api.post('/front-desk/collect', { patient_id: patient.id, bill_ids: owing.map((b) => b.id), amount, mode, reference: reference || null })
      setNotice(`${rupees(Number(amount))} received by ${modeLabel(mode)}${owing.length > 1 ? `, shared over ${owing.length} bills` : ''}.`)
      setReference('')
      await load()
      onChange()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'The payment was not saved. Check the bills and try again.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="modal-back" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className="modal" role="dialog" aria-modal="true" aria-label={`Bills for ${patient.name}`} tabIndex={-1} ref={box}>
        <div className="panel-head">
          <div>
            <h2>
              Bills for{' '}
              <PatientLink id={patient.id} bills>
                {patient.name}
              </PatientLink>
            </h2>
            <span className="muted">
              {patient.uhid} · {longDate(date)}
              {day ? ` · ${live.length} ${live.length === 1 ? 'bill' : 'bills'}` : ''}
            </span>
          </div>
          <div className="row">
            <button type="button" className="btn primary" onClick={onAdd}>
              + Add services or lab tests
            </button>
            {live.length > 0 && (
              <a className="btn" href={`/front-desk/patients/${patient.id}/bills/${date}/print`} target="_blank" rel="noreferrer" title="One sheet with all of today's bills for this patient">
                Print all
              </a>
            )}
            <button type="button" className="btn ghost" onClick={onClose}>
              Close
            </button>
          </div>
        </div>

        {!day && !error && <p className="loading">Loading the bills…</p>}
        {day && (
          <>
            <div className="tiles small">
              <div className="tile">
                <span>Billed</span>
                <strong>{rupees(day.total)}</strong>
              </div>
              <div className="tile">
                <span>Paid</span>
                <strong className="settled">{rupees(day.paid)}</strong>
              </div>
              <div className="tile">
                <span>Still due</span>
                <strong className={day.due > 0 ? 'due' : ''}>{rupees(day.due)}</strong>
              </div>
            </div>

            {day.bills.length === 0 && <p className="muted">Nothing has been billed to this patient on this day yet.</p>}
            {day.bills.map((b) => (
              <section key={b.id} className={`day-bill ${b.cancelled_at ? 'cancelled' : ''}`}>
                <header>
                  <div>
                    <strong>{KIND[b.kind]}</strong>
                    <small className="muted block">
                      <span className="mono">{b.bill_no}</span>
                      {b.order_no ? ` · ${b.order_no}` : ''} · {stampTime(b.created_at)}
                      {b.doctor ? ` · ${b.doctor}` : ''}
                    </small>
                  </div>
                  <span className={`pill ${b.cancelled_at ? 'off' : b.due > 0 ? 'owed' : 'ok'}`}>{b.cancelled_at ? 'Cancelled' : settlement(b.due, b.moved_to_ipd, b.total)}</span>
                  <span className="row">
                    <button type="button" className="link" onClick={() => onOpenBill(b.id)} title="Open this bill by itself: discount, refund, or change its lines">
                      Open
                    </button>
                    <a className="link" href={`/bills/${b.id}/print`} target="_blank" rel="noreferrer">
                      Print
                    </a>
                  </span>
                </header>
                <table className="compact">
                  <tbody>
                    {b.items.map((i) => (
                      <tr key={i.id} className="static">
                        <td className="wrap">{i.description}</td>
                        <td className="num muted">{i.quantity !== 1 ? `${i.quantity} × ${rupees(i.unit_price)}` : ''}</td>
                        <td className="num">{rupees(i.amount)}</td>
                      </tr>
                    ))}
                    {b.items.length === 0 && (
                      <tr className="empty">
                        <td colSpan={3}>Nothing on this bill yet.</td>
                      </tr>
                    )}
                  </tbody>
                </table>
                {b.cancelled_at && <p className="day-bill-foot">Cancelled: {b.cancel_reason}</p>}
                <p className="day-bill-foot" hidden={Boolean(b.cancelled_at)}>
                  {b.discount > 0 && <span>Discount {rupees(b.discount)}</span>}
                  <span>
                    Total <b>{rupees(b.total)}</b>
                  </span>
                  <span>
                    Paid {rupees(b.paid)}
                    {b.payments.length > 0 ? ` (${[...new Set(b.payments.filter((p) => p.type === 'payment').map((p) => modeLabel(p.mode)))].join(', ')})` : ''}
                  </span>
                  {b.due > 0 && <span className="due">Due {rupees(b.due)}</span>}
                </p>
              </section>
            ))}

            {notice && <p className="notice">{notice}</p>}
            {error && <div className="form-error">{error}</div>}

            {owing.length > 0 && (
              <form onSubmit={collect} className="day-collect">
                <h3>
                  Collect {rupees(day.due)} for {owing.length === 1 ? 'the unpaid bill' : `all ${owing.length} unpaid bills`}
                </h3>
                <div className="grid three">
                  <TextField label="Amount (₹)" required type="number" value={amount} onChange={setAmount} />
                  <SelectField label="Mode" required value={mode} onChange={setMode} options={PAYMENT_MODES} />
                  <TextField label="Reference" value={reference} onChange={setReference} placeholder="optional" />
                </div>
                <div className="panel-foot">
                  <span className="muted small grow">A part payment is put against the bills in the order shown. Each bill keeps its own receipt.</span>
                  <button className="btn primary" disabled={busy || !(Number(amount) > 0)}>
                    {busy ? 'Saving…' : 'Collect payment'}
                  </button>
                </div>
              </form>
            )}
          </>
        )}
        {!day && error && <div className="form-error">{error}</div>}
      </div>
    </div>
  )
}
