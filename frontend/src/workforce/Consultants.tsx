import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors, type FieldErrors } from '../api'
import { SelectField, TextField } from '../components/Field'
import { Popup } from '../components/Popup'
import { longDate, modeLabel, today } from '../format'
import { useChoiceInAddress } from '../rangeInAddress'
import { inr } from './pay'
import { addMonths, monthName, thisMonth } from './shared'
import { WorkforceTabs } from './WorkforceTabs'

export type ConsultantRow = {
  doctor_id: number
  doctor: string
  department: string | null
  shares: { counter: string; collected: number; percent: number | null; share: number }[]
  share: number
  visits: number
  first_visits: number
  visit_fees: number
  earned: number
  paid: number
  due: number
}
type Rates = { first_visit: number; later_visit: number }
type Month = { month: string; month_name: string; rates: Rates; rows: ConsultantRow[]; totals: { earned: number; paid: number; due: number } }
export type Statement = ConsultantRow & {
  month: string
  month_name: string
  phone: string | null
  visit_list: { id: number; visited_on: string; kind: 'first' | 'later'; fee?: number; note: string | null; patient: { id: number; name: string; uhid: string } | null }[]
  payments: { id: number; paid_on: string; amount: number; mode: string; reference: string | null; note: string | null; by: string | null; cancelled: boolean; cancel_reason: string | null }[]
}

const MODES = [
  { value: 'cash', label: 'Cash' },
  { value: 'upi', label: 'UPI' },
  { value: 'bank_transfer', label: 'Bank transfer' },
  { value: 'cheque', label: 'Cheque' },
]
const percent = (p: number | null) => (p === null ? 'Not set' : `${p}%`)

/**
 * What the hospital owes its consultants and what it has paid them, month by month. A consultant earns in two ways:
 * a percentage of what is collected on their own cases, and a fixed fee for each visit to another doctor's inpatient.
 * Doctors and consultants are not in the payroll; this page is where their money is kept.
 */
export function Consultants() {
  const [month, setMonth] = useChoiceInAddress<string>('month', thisMonth())
  const [data, setData] = useState<Month | null>(null)
  const [failed, setFailed] = useState('')
  const [notice, setNotice] = useState('')
  const [open, setOpen] = useState<number | null>(null)
  const [rating, setRating] = useState(false)

  const load = useCallback(() => {
    api
      .get<Month>('/consultants', { params: { month } })
      .then((res) => {
        setData(res.data)
        setFailed('')
      })
      .catch(() => setFailed('Could not load the consultants. Check the connection and try again.'))
  }, [month])
  useEffect(load, [load])
  const shown = data?.month === month ? data : null

  return (
    <>
      <WorkforceTabs />
      <section className="card">
        <div className="toolbar">
          <div className="grow">
            <h1>Consultants</h1>
            <span className="muted">What each doctor earned in the month, what was paid to them for it, and what is still due.</span>
          </div>
          <button className="btn ghost" onClick={() => setMonth(addMonths(month, -1))} aria-label="The month before">
            ‹
          </button>
          <strong>{monthName(month)}</strong>
          <button className="btn ghost" onClick={() => setMonth(addMonths(month, 1))} disabled={month >= thisMonth()} aria-label="The month after">
            ›
          </button>
        </div>
        {notice && <p className="notice">{notice}</p>}
        {failed && <div className="form-error">{failed}</div>}
        {shown && (
          <>
            <p className="muted small">
              For a visit to another doctor's inpatient the hospital pays <strong>{inr(shown.rates.first_visit)}</strong> for the first visit to that patient and <strong>{inr(shown.rates.later_visit)}</strong> for each later day.{' '}
              <button type="button" className="link" onClick={() => setRating(true)}>
                Change these fees
              </button>
              . For their own cases each doctor's percentage is set on their page under Staff → Login and access → Doctor settings.
            </p>
            <div className="tiles small">
              <div className="tile">
                <span>Earned in {shown.month_name}</span>
                <strong>{inr(shown.totals.earned)}</strong>
              </div>
              <div className="tile">
                <span>Paid for it</span>
                <strong>{inr(shown.totals.paid)}</strong>
              </div>
              <div className="tile">
                <span>Still due</span>
                <strong className={shown.totals.due > 0 ? 'due' : ''}>{inr(shown.totals.due)}</strong>
              </div>
            </div>
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Doctor</th>
                    <th className="num">Share of their own cases</th>
                    <th className="num">Visits to other doctors' patients</th>
                    <th className="num">Earned</th>
                    <th className="num">Paid</th>
                    <th className="num">Still due</th>
                  </tr>
                </thead>
                <tbody>
                  {shown.rows.map((r) => (
                    <tr key={r.doctor_id} onClick={() => setOpen(r.doctor_id)} title="Open this doctor's month">
                      <td className="fit-wrap">
                        <strong>{r.doctor}</strong>
                        {r.department && <small className="muted block">{r.department}</small>}
                      </td>
                      <td className="num">
                        {inr(r.share)}
                        {r.shares.every((s) => s.percent === null) && <small className="muted block">No percentage set</small>}
                      </td>
                      <td className="num">
                        {inr(r.visit_fees)}
                        {r.visits > 0 && <small className="muted block">{r.visits} {r.visits === 1 ? 'visit' : 'visits'}, {r.first_visits} first</small>}
                      </td>
                      <td className="num">
                        <strong>{inr(r.earned)}</strong>
                      </td>
                      <td className="num">{inr(r.paid)}</td>
                      <td className={`num ${r.due > 0 ? 'due' : ''}`}>{r.due < 0 ? `${inr(-r.due)} paid ahead` : inr(r.due)}</td>
                    </tr>
                  ))}
                  {shown.rows.length === 0 && (
                    <tr className="empty">
                      <td colSpan={6}>No doctor is on the staff list yet. Add them under Staff with the Doctor role.</td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </>
        )}
      </section>
      {open !== null && (
        <DoctorMonth
          key={`${open}-${month}`}
          doctorId={open}
          month={month}
          onClose={() => setOpen(null)}
          onChanged={(message) => {
            setNotice(message)
            load()
          }}
        />
      )}
      {rating && shown && (
        <RatesPopup
          rates={shown.rates}
          onClose={() => setRating(false)}
          onSaved={() => {
            setRating(false)
            setNotice('The visit fees were changed. They apply to visits written down from now on.')
            load()
          }}
        />
      )}
    </>
  )
}

/** One doctor's month: where each rupee earned comes from, each payment made, and writing down a new payment. */
function DoctorMonth({ doctorId, month, onClose, onChanged }: { doctorId: number; month: string; onClose: () => void; onChanged: (message: string) => void }) {
  const [s, setS] = useState<Statement | null>(null)
  const [form, setForm] = useState({ paid_on: today(), amount: '', mode: 'bank_transfer', reference: '', note: '' })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    api.get<Statement>(`/consultants/${doctorId}`, { params: { month } }).then((res) => {
      setS(res.data)
      // What is still due is the usual amount to pay; it can be changed.
      setForm((f) => ({ ...f, amount: res.data.due > 0 ? String(res.data.due) : '' }))
    })
  }, [doctorId, month])
  useEffect(load, [load])

  const pay = async (e: FormEvent) => {
    e.preventDefault()
    if (!s || !window.confirm(`Write down ${inr(form.amount)} as paid to ${s.doctor} for ${s.month_name}? It goes into the cash book as money paid out on ${longDate(form.paid_on)}.`)) return
    setSaving(true)
    setErrors({})
    try {
      await api.post(`/consultants/${doctorId}/payments`, { month, paid_on: form.paid_on, amount: form.amount, mode: form.mode, reference: form.reference || null, note: form.note || null })
      onChanged(`${inr(form.amount)} written down as paid to ${s.doctor} for ${s.month_name}.`)
      load()
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }
  const cancel = async (id: number, amount: number) => {
    const reason = window.prompt(`Cancel this payment of ${inr(amount)}? It is taken out of the cash book as well. Say why:`)
    if (!reason?.trim()) return
    try {
      await api.post(`/consultants/payments/${id}/cancel`, { reason: reason.trim() })
      onChanged('The payment was cancelled.')
      load()
    } catch (err) {
      setErrors({ _: Object.values(fieldErrors(err))[0] ?? 'That did not save. Try again.' })
    }
  }

  return (
    <Popup
      title={s ? s.doctor : 'Opening…'}
      sub={s ? `${s.month_name}${s.department ? ` · ${s.department}` : ''}` : undefined}
      size="wide"
      onClose={onClose}
      foot={
        s && (
          <Link className="btn" to={`/workforce/consultants/${doctorId}/print?month=${month}`} target="_blank">
            Print the statement
          </Link>
        )
      }
    >
      {!s ? (
        <p className="loading">Loading…</p>
      ) : (
        <div className="person-card">
          <div className="tiles small">
            <div className="tile">
              <span>Earned</span>
              <strong>{inr(s.earned)}</strong>
            </div>
            <div className="tile">
              <span>Paid</span>
              <strong>{inr(s.paid)}</strong>
            </div>
            <div className="tile">
              <span>{s.due < 0 ? 'Paid ahead' : 'Still due'}</span>
              <strong className={s.due > 0 ? 'due' : ''}>{inr(Math.abs(s.due))}</strong>
            </div>
          </div>

          <StatementBody s={s} onCancel={(id, amount) => void cancel(id, amount)} />

          <h3>Write down a payment for {s.month_name}</h3>
          <form onSubmit={pay} noValidate>
            <div className="grid three">
              <TextField label="Paid on" required type="date" max={today()} value={form.paid_on} onChange={(v) => setForm((f) => ({ ...f, paid_on: v }))} error={errors.paid_on} />
              <TextField label="Amount (₹)" required type="number" min={0} step="0.01" value={form.amount} onChange={(v) => setForm((f) => ({ ...f, amount: v }))} error={errors.amount} />
              <SelectField label="Paid by" required value={form.mode} onChange={(v) => setForm((f) => ({ ...f, mode: v }))} options={MODES} error={errors.mode} />
              <TextField label="Reference (optional)" value={form.reference} onChange={(v) => setForm((f) => ({ ...f, reference: v }))} placeholder="Cheque or transfer number" error={errors.reference} />
              <div className="span-2">
                <TextField label="Note (optional)" value={form.note} onChange={(v) => setForm((f) => ({ ...f, note: v }))} error={errors.note} />
              </div>
            </div>
            {errors._ && <p className="form-error">{errors._}</p>}
            <div className="panel-foot">
              <span className="muted small grow">It is also written into the cash book as money paid out, so the day's cash count is right.</span>
              <button className="btn primary" disabled={saving || !form.amount || Number(form.amount) <= 0}>
                {saving ? 'Saving…' : 'Write down the payment'}
              </button>
            </div>
          </form>
        </div>
      )}
    </Popup>
  )
}

/** The three lists a statement is made of, on the screen and on paper alike. */
export function StatementBody({ s, onCancel }: { s: Statement; onCancel?: (id: number, amount: number) => void }) {
  return (
    <>
      <h3>Their own cases: a share of what was collected</h3>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Counter</th>
              <th className="num">Collected</th>
              <th className="num">Their share</th>
              <th className="num">Comes to</th>
            </tr>
          </thead>
          <tbody>
            {s.shares.map((c) => (
              <tr key={c.counter} className="static">
                <td>{c.counter}</td>
                <td className="num">{inr(c.collected)}</td>
                <td className="num">{percent(c.percent)}</td>
                <td className="num">{inr(c.share)}</td>
              </tr>
            ))}
            {s.shares.length === 0 && (
              <tr className="empty">
                <td colSpan={4}>Nothing was collected on their cases in this month.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <h3>Visits to other doctors' inpatients</h3>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Day</th>
              <th>Patient</th>
              <th>Visit</th>
              <th className="num">Fee</th>
            </tr>
          </thead>
          <tbody>
            {s.visit_list.map((v) => (
              <tr key={v.id} className="static">
                <td>{longDate(v.visited_on)}</td>
                <td className="wrap">
                  {v.patient?.name ?? '—'}
                  {v.patient && <small className="muted block">{v.patient.uhid}</small>}
                </td>
                <td>{v.kind === 'first' ? 'First visit' : 'Later visit'}</td>
                <td className="num">{inr(v.fee)}</td>
              </tr>
            ))}
            {s.visit_list.length === 0 && (
              <tr className="empty">
                <td colSpan={4}>No visit was written down for them in this month.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <h3>Paid for this month</h3>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Paid on</th>
              <th>How</th>
              <th className="num">Amount</th>
              <th>Written down by</th>
              {onCancel && <th />}
            </tr>
          </thead>
          <tbody>
            {s.payments.map((p) => (
              <tr key={p.id} className={`static ${p.cancelled ? 'cancelled' : ''}`}>
                <td>{longDate(p.paid_on)}</td>
                <td className="wrap">
                  {modeLabel(p.mode)}
                  {p.reference ? ` · ${p.reference}` : ''}
                  {p.note && <small className="muted block">{p.note}</small>}
                  {p.cancelled && <small className="due block">Cancelled: {p.cancel_reason}</small>}
                </td>
                <td className="num">{p.cancelled ? <s>{inr(p.amount)}</s> : inr(p.amount)}</td>
                <td>{p.by ?? '—'}</td>
                {onCancel && (
                  <td className="num">
                    {!p.cancelled && (
                      <button type="button" className="link danger" onClick={() => onCancel(p.id, p.amount)}>
                        Cancel
                      </button>
                    )}
                  </td>
                )}
              </tr>
            ))}
            {s.payments.length === 0 && (
              <tr className="empty">
                <td colSpan={onCancel ? 5 : 4}>Nothing has been paid for this month yet.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </>
  )
}

function RatesPopup({ rates, onClose, onSaved }: { rates: Rates; onClose: () => void; onSaved: () => void }) {
  const [first, setFirst] = useState(String(rates.first_visit))
  const [later, setLater] = useState(String(rates.later_visit))
  const [errors, setErrors] = useState<FieldErrors>({})
  const save = async (e: FormEvent) => {
    e.preventDefault()
    try {
      await api.put('/consultants/rates', { first_visit: first, later_visit: later })
      onSaved()
    } catch (err) {
      setErrors(fieldErrors(err))
    }
  }
  return (
    <Popup title="Fees for a consultant's visit" sub="What the hospital pays a consultant for seeing another doctor's inpatient" size="slim" onClose={onClose} onSubmit={save} foot={<button className="btn primary">Save</button>}>
      <div className="grid">
        <TextField label="First visit to a patient (₹)" required type="number" min={0} step="1" value={first} onChange={setFirst} error={errors.first_visit} />
        <TextField label="Each later day (₹)" required type="number" min={0} step="1" value={later} onChange={setLater} error={errors.later_visit} />
      </div>
      <p className="muted small">A change applies to visits written down from now on. Visits already written down keep the fee they were given.</p>
      {errors._ && <p className="form-error">{errors._}</p>}
    </Popup>
  )
}
