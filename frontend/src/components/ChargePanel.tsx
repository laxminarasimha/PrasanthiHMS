import { useEffect, useMemo, useRef, useState, type FormEvent, type KeyboardEvent } from 'react'
import { useAuth } from '../auth'
import { api, fieldErrors } from '../api'
import { PAYMENT_MODES, rupees, today } from '../format'
import type { LabCatalogueTest, LabPackage, Service } from '../types'
import { matchAdvised, namesOf } from './LabOrderForm'
import { ReferredBy } from './ReferredBy'
import { DiscountWhy } from './DiscountWhy'
import { noWhy, whyFields } from './discountFields'
import { useBillingPreferences } from './useBillingPreferences'
import { usePartLoading } from '../loading'
import { EarlierDate } from './EarlierDate'
import { earlier } from '../earlierDay'

type Props = {
  patient: { id: number; name: string; uhid: string }
  /** The visit these charges belong to, when the patient is seeing a doctor today. */
  appointmentId?: number
  /** The doctor of that visit, offered as who referred the lab tests. */
  doctor?: string
  /** Tests the doctor advised, as typed on the visit pad: those the lab offers are put in the list ready. */
  advised?: string
  /** The day whose list this was opened from. On the list of an earlier day, what is added belongs to that day unless the desk says today. */
  date?: string
  services: Service[]
  tests: LabCatalogueTest[]
  packages: LabPackage[]
  onClose: () => void
  /** Called after anything is saved or paid, so the day list can refresh. */
  onChange: () => void
  /** Opens one bill by itself: to print it, discount it or refund it. */
  onOpenBill: (billId: number) => void
}

type Line = { key: string; type: 'service' | 'test' | 'package' | 'typed'; id: number | null; name: string; price: string; quantity: string; discount: string }
type Found = { key: string; type: 'service' | 'test' | 'package'; id: number; name: string; price: number; note?: string }
type Due = { id: number; bill_no: string; kind: 'appointment' | 'lab' | 'other'; order_no: string | null; total: number; paid: number; due: number; items: string[] }

/** The most times one test may be put on one lab order; the server holds the same limit. */
const MOST_TIMES = 9

const TAG = { service: 'Service', test: 'Lab test', package: 'Lab package', typed: 'Typed' } as const
const KIND = { appointment: 'Services', other: 'Services', lab: 'Lab' } as const

/**
 * Billing a patient from the front desk in one place. One box finds services, lab tests and lab
 * packages alike; everything chosen is saved together; then all that is due is collected at once.
 * Behind it the services become one bill and the lab tests a lab order with its own bill, as before.
 */
export function ChargePanel({ patient, appointmentId, doctor, advised, date, services, tests, packages, onClose, onChange, onOpenBill }: Props) {
  const { user } = useAuth()
  const prefs = useBillingPreferences()
  const box = useRef<HTMLInputElement>(null)
  const [term, setTerm] = useState('')
  const [at, setAt] = useState(0)
  const [typed, setTyped] = useState({ name: '', price: '' })
  const [referredBy, setReferredBy] = useState(doctor ?? '')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [saved, setSaved] = useState('')
  const [due, setDue] = useState<Due[]>([])
  // Until what is unpaid has come, "nothing is due" is not known to be true.
  const loading = usePartLoading()
  const [amount, setAmount] = useState('')
  const [chosenMode, setMode] = useState<string | null>(null)
  const mode = chosenMode ?? prefs.default_payment_mode
  const [reference, setReference] = useState('')
  // The day the new charges belong to, and the day the money was taken. Money is today's unless a bill has just
  // been entered here for an earlier day: an old due that is paid now is money of today.
  const [day, setDay] = useState(() => (date && date < today() ? date : today()))
  const [paidOn, setPaidOn] = useState(today)

  const testLine = (t: LabCatalogueTest): Line => ({ key: `test-${t.id}`, type: 'test', id: t.id, name: t.name, price: String(t.price), quantity: '1', discount: '' })
  // What the doctor advised is in the list from the start, for the desk to confirm.
  const advice = useMemo(() => (advised ? matchAdvised(advised, tests) : null), [advised, tests])
  const [lines, setLines] = useState<Line[]>(() => (advice ? tests.filter((t) => advice.ids.includes(t.id)).map(testLine) : []))

  // Anything already unpaid for this visit is shown, so it can be collected with the new charges.
  useEffect(() => {
    api.get<{ due: Due[] }>('/front-desk/due', { params: { patient_id: patient.id, appointment_id: appointmentId, date: earlier(day) ?? undefined } }).then((res) => takeDue(res.data.due))
    box.current?.focus()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [patient.id, appointmentId, day])

  const takeDue = (list: Due[]) => {
    setDue(list)
    const sum = list.reduce((s, b) => s + b.due, 0)
    setAmount(sum > 0 ? String(Math.round(sum * 100) / 100) : '')
  }

  // One list to search: every service, lab test and package, each under all the names it goes by.
  const catalogue = useMemo(
    () => [
      ...services.filter((s) => s.is_active !== false).map((s) => ({ found: { key: `service-${s.id}`, type: 'service' as const, id: s.id, name: s.name, price: s.price }, names: [s.name.toLowerCase(), (s.code ?? '').toLowerCase()] })),
      ...packages.map((p) => ({ found: { key: `package-${p.id}`, type: 'package' as const, id: p.id, name: p.name, price: p.price, note: p.tests.map((t) => t.name).join(', ') }, names: [p.name.toLowerCase()] })),
      ...tests.map((t) => ({ found: { key: `test-${t.id}`, type: 'test' as const, id: t.id, name: t.name, price: t.price, note: t.sample_type ?? undefined }, names: [...namesOf(t), (t.code ?? '').toLowerCase()] })),
    ],
    [services, tests, packages],
  )
  const have = new Set(lines.map((l) => l.key))
  const words = term.trim().toLowerCase()
  const found: Found[] = words
    ? catalogue
        .filter((c) => !have.has(c.found.key) && c.names.some((n) => n && n.includes(words)))
        // A name that starts with what was typed comes before one that only contains it.
        .sort((a, b) => Number(b.names.some((n) => n.startsWith(words))) - Number(a.names.some((n) => n.startsWith(words))))
        .slice(0, 8)
        .map((c) => c.found)
    : []

  const add = (f: Found) => {
    setLines((list) => [...list, { key: f.key, type: f.type, id: f.id, name: f.name, price: String(f.price), quantity: '1', discount: '' }])
    setTerm('')
    setAt(0)
    setSaved('')
    setError('')
    box.current?.focus()
  }
  const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setAt((i) => Math.min(i + 1, found.length - 1)) }
    if (e.key === 'ArrowUp') { e.preventDefault(); setAt((i) => Math.max(i - 1, 0)) }
    if (e.key === 'Enter') { e.preventDefault(); if (found[at]) add(found[at]) }
    if (e.key === 'Escape') setTerm('')
  }
  const change = (key: string, patch: Partial<Line>) => setLines((list) => list.map((l) => (l.key === key ? { ...l, ...patch } : l)))
  const addTyped = () => {
    if (!typed.name.trim() || typed.price === '') return
    setLines((list) => [...list, { key: `typed-${Date.now()}`, type: 'typed', id: null, name: typed.name.trim(), price: typed.price, quantity: '1', discount: '' }])
    setTyped({ name: '', price: '' })
  }

  // A test that is inside a chosen package is done once and charged once, in the package.
  const inPackage = new Set(packages.filter((p) => have.has(`package-${p.id}`)).flatMap((p) => p.tests.map((t) => t.id)))
  /** How many times a line is wanted: a whole number, at least once. */
  const times = (l: Line) => Math.max(1, Math.floor(Number(l.quantity) || 1))
  // A test repeated in the day is charged each time; the one a package already has is not charged again.
  const worth = (l: Line) =>
    l.type === 'test'
      ? Number(l.price || 0) * (times(l) - (inPackage.has(l.id ?? 0) ? 1 : 0))
      : Math.max(0, Number(l.price || 0) * Number(l.quantity || 1) - Number(l.discount || 0))
  const newTotal = lines.reduce((sum, l) => sum + worth(l), 0)
  const hasLab = lines.some((l) => l.type === 'test' || l.type === 'package')
  const dueTotal = due.reduce((sum, b) => sum + b.due, 0)

  // Any rupee taken off a line needs its reason and the name of who allowed it, once for the bill.
  const [why, setWhy] = useState(noWhy)
  const discounted = lines.some((l) => l.type !== 'test' && l.type !== 'package' && Number(l.discount || 0) > 0)

  const save = async () => {
    setBusy(true)
    setError('')
    try {
      const res = await api.post<{ made: number[]; due: Due[] }>('/front-desk/charges', {
        patient_id: patient.id,
        appointment_id: appointmentId ?? null,
        services: lines
          .filter((l) => l.type === 'service' || l.type === 'typed')
          .map((l) => ({ service_id: l.type === 'service' ? l.id : null, description: l.type === 'typed' ? l.name : null, unit_price: l.price === '' ? null : l.price, quantity: l.quantity || 1, line_discount: l.discount || 0 })),
        test_ids: lines.filter((l) => l.type === 'test').map((l) => l.id),
        // Only the tests wanted more than once need saying.
        test_counts: Object.fromEntries(lines.filter((l) => l.type === 'test' && times(l) > 1).map((l) => [l.id, times(l)])),
        package_ids: lines.filter((l) => l.type === 'package').map((l) => l.id),
        referred_by: hasLab ? referredBy.trim() || null : null,
        billed_on: earlier(day),
        ...(discounted ? whyFields(why) : {}),
      })
      // A bill that was missed on an earlier day: its money was most likely taken that day too.
      setPaidOn(day)
      setSaved(res.data.made.length > 1 ? 'Saved: a services bill and a lab order were made.' : hasLab ? 'Saved: the lab order was made.' : 'Saved: the bill was made.')
      setLines([])
      setWhy(noWhy)
      takeDue(res.data.due)
      onChange()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    } finally {
      setBusy(false)
    }
  }

  const collect = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError('')
    try {
      const res = await api.post<{ due: Due[] }>('/front-desk/collect', { patient_id: patient.id, appointment_id: appointmentId ?? null, bill_ids: due.map((b) => b.id), amount, mode, reference: reference.trim() || null, paid_on: earlier(paidOn), date: earlier(day) })
      setSaved(res.data.due.length ? `Collected ${rupees(Number(amount))}.` : `Collected ${rupees(Number(amount))}. Nothing more is due.`)
      setReference('')
      takeDue(res.data.due)
      onChange()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    } finally {
      setBusy(false)
    }
  }

  return (
    <aside className="card panel wide">
      <div className="panel-head">
        <div>
          <h2>Bill for {patient.name}</h2>
          <span className="muted">{patient.uhid} · services and lab tests together</span>
        </div>
        <button type="button" className="btn ghost" onClick={onClose}>
          Close
        </button>
      </div>

      {advised && advice && (
        <div className="advised">
          <strong>Tests advised{doctor ? ` by ${doctor}` : ''}</strong>
          <p>{advised}</p>
          <small>
            {advice.ids.length > 0 ? `${advice.ids.length} put in the list below. ` : ''}
            {advice.missing.length > 0 ? `Not in the lab's list: ${advice.missing.join(', ')}. Search for the right test, or it is done outside.` : 'Check the list before saving.'}
          </small>
        </div>
      )}

      <div className="charge-search">
        <input
          ref={box}
          type="search"
          placeholder="Type a service, lab test or package: ECG, CBP, dressing…"
          value={term}
          onChange={(e) => { setTerm(e.target.value); setAt(0) }}
          onKeyDown={onKey}
          aria-label="Find a service or lab test to add"
          role="combobox"
          aria-expanded={found.length > 0}
          aria-controls="charge-found"
        />
        {words && (
          <ul className="picker" id="charge-found" role="listbox">
            {found.map((f, i) => (
              <li key={f.key} role="option" aria-selected={i === at}>
                <button type="button" className={i === at ? 'on' : ''} onClick={() => add(f)} onMouseEnter={() => setAt(i)}>
                  <span className="charge-found">
                    <span>
                      {f.name} <span className={`pill ${f.type === 'service' ? 'status-booked' : 'status-ongoing'}`}>{TAG[f.type]}</span>
                    </span>
                    <strong className="mono">{rupees(f.price)}</strong>
                  </span>
                  {f.note && <small className="muted">{f.note}</small>}
                </button>
              </li>
            ))}
            {found.length === 0 && <li className="none muted">Nothing on the price list or the lab’s list matches. Use “a charge not on the list” below.</li>}
          </ul>
        )}
      </div>

      {lines.length > 0 && (
        <div className="table-wrap gap-top">
          <table className="compact">
            <thead>
              <tr>
                <th>To add</th>
                <th className="num">Qty</th>
                <th className="num">Price ₹</th>
                <th className="num">Disc. ₹</th>
                <th className="num">Amount</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {lines.map((l) => {
                const lab = l.type === 'test' || l.type === 'package'
                const covered = l.type === 'test' && inPackage.has(l.id ?? 0)
                return (
                  <tr key={l.key} className="static">
                    <td>
                      {l.name} <span className={`pill ${lab ? 'status-ongoing' : 'status-booked'}`}>{TAG[l.type]}</span>
                    </td>
                    <td className="num">
                      {l.type === 'package' ? (
                        ''
                      ) : (
                        <input
                          className="qty"
                          type="number"
                          min={1}
                          max={l.type === 'test' ? MOST_TIMES : undefined}
                          value={l.quantity}
                          onChange={(e) => change(l.key, { quantity: e.target.value })}
                          aria-label={`Quantity of ${l.name}`}
                          title={l.type === 'test' ? 'How many times this test is done today. Each time gets its own report in the lab.' : undefined}
                        />
                      )}
                    </td>
                    <td className="num">
                      {lab ? (
                        covered && times(l) === 1 ? '' : rupees(Number(l.price))
                      ) : (
                        <input className="qty" type="number" min={0} step="0.01" value={l.price} disabled={l.type === 'service' && !user?.can_change_price} onChange={(e) => change(l.key, { price: e.target.value })} aria-label={`Price of ${l.name}`} />
                      )}
                    </td>
                    <td className="num">{lab ? '' : <input className="qty" type="number" min={0} step="0.01" placeholder="0" value={l.discount} onChange={(e) => change(l.key, { discount: e.target.value })} aria-label={`Discount on ${l.name}`} />}</td>
                    <td className="num">
                      {covered && times(l) === 1 ? (
                        <small className="muted">in package</small>
                      ) : (
                        <>
                          {rupees(worth(l))}
                          {covered && <small className="muted block">1 in package</small>}
                        </>
                      )}
                    </td>
                    <td className="num">
                      <button type="button" className="link danger" onClick={() => setLines((list) => list.filter((x) => x.key !== l.key))} aria-label={`Remove ${l.name}`}>
                        Remove
                      </button>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      <details className="charge-typed">
        <summary>A charge not on the list</summary>
        <div className="line-row">
          <input placeholder="What it is for" value={typed.name} onChange={(e) => setTyped({ ...typed, name: e.target.value })} aria-label="Charge description" />
          <input type="number" min={0} step="0.01" placeholder="Price ₹" value={typed.price} onChange={(e) => setTyped({ ...typed, price: e.target.value })} aria-label="Price of the typed charge" />
          <button type="button" className="btn ghost" disabled={!typed.name.trim() || typed.price === ''} onClick={addTyped}>
            Add
          </button>
        </div>
      </details>

      {hasLab && (
        <div className="gap-top">
          <ReferredBy label="Lab tests referred by (optional)" value={referredBy} onChange={setReferredBy} ours={doctor} />
        </div>
      )}

      {discounted && <DiscountWhy id="charge-why" value={why} onChange={setWhy} />}

      {(lines.length > 0 || earlier(day)) && <EarlierDate what="bill" value={day} onChange={setDay} />}

      {lines.length > 0 && (
        <div className="panel-foot between">
          <span>
            {lines.length} {lines.length === 1 ? 'item' : 'items'} · <strong>{rupees(newTotal)}</strong>
          </span>
          <button type="button" className="btn primary" onClick={save} disabled={busy}>
            {busy ? 'Saving…' : 'Save to the bill'}
          </button>
        </div>
      )}

      {error && <p className="form-error">{error}</p>}
      {saved && <p className="notice gap-top">{saved}</p>}

      {due.length > 0 && (
        <>
          <h3>To collect now</h3>
          <ul className="history">
            {due.map((b) => (
              <li key={b.id}>
                <span>
                  <button type="button" className="link" onClick={() => onOpenBill(b.id)} title="Open this bill to print it, discount it or refund it">
                    {b.bill_no}
                  </button>{' '}
                  <span className={`pill ${b.kind === 'lab' ? 'status-ongoing' : 'status-booked'}`}>{KIND[b.kind]}</span>
                  <small className="muted block">
                    {b.items.slice(0, 4).join(', ')}
                    {b.items.length > 4 ? ` and ${b.items.length - 4} more` : ''}
                    {b.paid > 0 ? ` · ${rupees(b.paid)} already paid` : ''}
                  </small>
                </span>
                <span className="charge-due">
                  <strong className="due">{rupees(b.due)}</strong>
                  <a className="link" href={`/bills/${b.id}/print`} target="_blank" rel="noreferrer">
                    Print
                  </a>
                </span>
              </li>
            ))}
          </ul>
          <form onSubmit={collect} className="gap-top">
            <div className="grid three">
              <label className="field">
                <span className="field-label">Amount (₹) of {rupees(dueTotal)} due</span>
                <input type="number" min={0} step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} required />
              </label>
              <label className="field">
                <span className="field-label">Mode</span>
                <select value={mode} onChange={(e) => setMode(e.target.value)}>
                  {PAYMENT_MODES.map((m) => (
                    <option key={m.value} value={m.value}>
                      {m.label}
                    </option>
                  ))}
                </select>
              </label>
              <label className="field">
                <span className="field-label">Reference</span>
                <input placeholder="optional" value={reference} onChange={(e) => setReference(e.target.value)} />
              </label>
            </div>
            <EarlierDate what="money" value={paidOn} onChange={setPaidOn} />
            <div className="panel-foot between">
              <small className="muted">{lines.length > 0 ? 'Save the new items first to collect for them too.' : due.length > 1 ? 'One payment, shared across these bills.' : ''}</small>
              <button className="btn primary" disabled={busy || !amount || Number(amount) <= 0}>
                {busy ? 'Saving…' : `Collect ${amount ? rupees(Number(amount)) : ''}`}
              </button>
            </div>
          </form>
        </>
      )}
      {due.length === 0 && lines.length === 0 && !saved && (
        <p className="muted gap-top">{loading ? 'Checking what is due for this patient…' : 'Nothing is due for this patient right now. Search above to add a service or a lab test.'}</p>
      )}
    </aside>
  )
}
