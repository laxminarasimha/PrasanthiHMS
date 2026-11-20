import { BillDiscountBox } from '../../components/BillDiscountBox'
import { discountRupees, type BillDiscount } from '../../components/billDiscount'
import { useCallback, useEffect, useRef, useState, type FormEvent, type KeyboardEvent } from 'react'
import { useLoadOnChange } from '../../loading'
import { useRangeInAddress } from '../../rangeInAddress'
import { useSearchParams } from 'react-router-dom'
import { api, fieldErrors } from '../../api'
import { useUnsaved } from '../../unsaved'
import { useAuth } from '../../auth'
import { DateRange } from '../../components/DateRange'
import { MedicinePick, type SellableBatch } from '../../components/MedicinePick'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { PAYMENT_MODES, clock, longDate, modeLabel, placeLabel, rupees } from '../../format'
import type { Payment, PharmacySale, SellableMedicine } from '../../types'
import { PatientLink } from '../../components/PatientLink'
import { PharmacyDay } from '../../components/PharmacyDay'

type Action = 'view' | 'collect' | 'return' | 'delete' | 'edit' | 'mode'
/** A medicine being added to a bill that is already saved. */
type Added = { batchId: number; medicine: string; batchNo: string; unitPrice: number; available: number; quantity: string; discount: string; place: string }
const addedAmount = (a: Added) => Math.round(Number(a.quantity || 0) * a.unitPrice * (1 - Number(a.discount || 0) / 100) * 100) / 100
type Edit = { customer_name: string; customer_phone: string; referred_doctor: string; discount: BillDiscount; lines: Record<number, { quantity: string; discount_percent: string }> }

const ModeSelect = ({ value, onChange }: { value: string; onChange: (v: string) => void }) => (
  <select value={value} onChange={(e) => onChange(e.target.value)} aria-label="Mode">
    {PAYMENT_MODES.map((m) => (
      <option key={m.value} value={m.value}>
        {m.label}
      </option>
    ))}
  </select>
)

export function PharmacyBills() {
  const { user } = useAuth()
  const canEdit = Boolean(user?.can_run_pharmacy)
  const [from, to, setRange] = useRangeInAddress('today')
  const [query, setQuery] = useState('')
  const [sales, setSales] = useState<PharmacySale[] | null>(null)
  const [open, setOpen] = useState<PharmacySale | null>(null)
  const [action, setAction] = useState<Action>('view')
  const [amount, setAmount] = useState('')
  const [mode, setMode] = useState('cash')
  const [reason, setReason] = useState('')
  const [quantities, setQuantities] = useState<Record<number, string>>({})
  const [edit, setEdit] = useState<Edit | null>(null)
  // Medicines being added to the open bill, and the money taken for them now (null until typed: then the usual amount is offered).
  const [added, setAdded] = useState<Added[]>([])
  const [collect, setCollect] = useState<string | null>(null)
  const pickBox = useRef<HTMLInputElement>(null)
  const addedBoxes = useRef<Record<number, HTMLInputElement | null>>({})
  const jumpTo = useRef<number | null>(null)
  // The payment whose mode is being corrected, and the reference typed for it.
  const [fixing, setFixing] = useState<Payment | null>(null)
  const [reference, setReference] = useState('')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const searching = query.trim().length >= 2

  const load = useCallback(() => {
    api.get<PharmacySale[]>('/pharmacy/sales', { params: searching ? { q: query.trim() } : { from, to } }).then((res) => setSales(res.data))
  }, [from, to, query, searching])

  // At once when the screen opens; after a short pause when a search or filter changes.
  useLoadOnChange(load)

  const editable = (s: PharmacySale) => !s.voided_at && s.returned === 0 && s.ipd_transfer === 0

  const show = (sale: PharmacySale, next: Action) => {
    setError('')
    setNotice('')
    setAction(next)
    setQuantities({})
    setReason('')
    setAdded([])
    setCollect(null)
    api.get<PharmacySale>(`/pharmacy/sales/${sale.id}`).then((res) => {
      setOpen(res.data)
      if (next === 'edit' && !editable(res.data)) {
        setAction('view')
        setNotice(res.data.voided_at ? 'This bill was deleted.' : 'Medicines were returned on this bill, or it is on an IPD bill, so it cannot be added to. Make a new bill for the extra medicines.')
      }
      // Straight to the medicine box, ready for the first name.
      if (next === 'edit') setTimeout(() => pickBox.current?.focus(), 0)
      setAmount(res.data.due > 0 ? String(res.data.due) : '')
      setEdit({
        customer_name: res.data.customer_name,
        customer_phone: res.data.customer_phone ?? '',
        referred_doctor: res.data.referred_doctor ?? '',
        discount: { mode: 'amount', typed: res.data.discount ? String(res.data.discount) : '' },
        lines: Object.fromEntries((res.data.items ?? []).map((i) => [i.id, { quantity: String(i.quantity), discount_percent: String(i.discount_percent) }])),
      })
    })
  }

  // Sends one change to a bill, then shows the updated bill or why it was refused.
  const run = async (request: Promise<{ data: PharmacySale }>, done: string) => {
    try {
      setOpen((await request).data)
      setNotice(done)
      setError('')
      setAction('view')
      load()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    }
  }

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (!open) return
    const url = `/pharmacy/sales/${open.id}`
    if (action === 'collect') run(api.post(`${url}/payments`, { amount, mode }), 'Payment recorded.')
    if (action === 'return') {
      const items = Object.entries(quantities).filter(([, q]) => Number(q) > 0).map(([id, q]) => ({ id: Number(id), quantity: Number(q) }))
      run(api.post(`${url}/returns`, { refund_mode: mode, items }), 'Medicines taken back and stock updated.')
    }
    if (action === 'edit' && edit) {
      const items = Object.entries(edit.lines).map(([id, l]) => ({ id: Number(id), quantity: Number(l.quantity || 0), discount_percent: Number(l.discount_percent || 0) }))
      run(
        api.put(url, {
          customer_name: edit.customer_name,
          customer_phone: edit.customer_phone || null,
          referred_doctor: edit.referred_doctor || null,
          discount: editedDiscount,
          refund_mode: mode,
          reason: reason.trim() || null,
          items,
          ...(toAdd.length ? { add: toAdd.map((a) => ({ stock_batch_id: a.batchId, quantity: Number(a.quantity), discount_percent: Number(a.discount || 0) })) } : {}),
          ...(newDue > 0 && Number(collectValue) > 0 ? { collect: Number(collectValue), collect_mode: mode } : {}),
        }),
        toAdd.length && !corrected ? 'Medicines added to the bill and stock updated.' : 'Bill changed. The change is recorded in the Bill modified report.',
      )
    }
    if (action === 'delete') run(api.delete(url, { data: { reason, refund_mode: mode } }), 'Bill deleted and stock restored.')
    if (action === 'mode' && fixing) run(api.put(`${url}/payments/${fixing.id}`, { mode, reference: reference.trim() || null, reason }), `Recorded as ${modeLabel(mode)} now. The change is in the Bill modified report.`)
  }

  // What the bill will come to with the quantities and discounts typed so far, and the medicines being added.
  const toAdd = added.filter((a) => Number(a.quantity) > 0)
  const editedSubtotal =
    open && edit
      ? (open.items ?? []).reduce((sum, i) => sum + Number(edit.lines[i.id]?.quantity || 0) * i.unit_price * (1 - Number(edit.lines[i.id]?.discount_percent || 0) / 100), 0) + toAdd.reduce((sum, a) => sum + addedAmount(a), 0)
      : 0
  const editedDiscount = edit ? discountRupees(edit.discount, editedSubtotal) : 0
  const editedTotal = open && edit ? Math.round(editedSubtotal - editedDiscount) : 0
  // Whether anything already on the bill was changed. That needs a reason; only adding medicines does not.
  const corrected = Boolean(
    open &&
      edit &&
      (edit.customer_name !== open.customer_name ||
        edit.customer_phone !== (open.customer_phone ?? '') ||
        edit.referred_doctor !== (open.referred_doctor ?? '') ||
        editedDiscount !== open.discount ||
        (open.items ?? []).some((i) => Number(edit.lines[i.id]?.quantity || 0) !== i.quantity || Number(edit.lines[i.id]?.discount_percent || 0) !== i.discount_percent)),
  )
  // What will be owed after the change. A bill that was fully paid is offered in full; one already carrying a balance, only what is being added.
  const newDue = open ? Math.max(0, Math.round((editedTotal - open.paid) * 100) / 100) : 0
  const collectValue = collect ?? String(open && open.due > 0 ? Math.min(newDue, Math.max(0, editedTotal - open.total)) : newDue)

  useUnsaved(action === 'edit' && (added.length > 0 || corrected), 'The changes to this bill are not saved yet. Leave without saving them?')

  const pickMedicine = (m: SellableMedicine, b: SellableBatch) => {
    setError('')
    setAdded((rows) => (rows.some((r) => r.batchId === b.id) ? rows : [...rows, { batchId: b.id, medicine: m.name, batchNo: b.batch_no, unitPrice: b.unit_mrp, available: b.units_in_stock, quantity: '1', discount: '', place: placeLabel(b.rack_no, b.box_no) }]))
    jumpTo.current = b.id
  }
  // Once a picked medicine is on the screen, the cursor goes to its quantity, with the 1 selected so a number replaces it.
  useEffect(() => {
    if (jumpTo.current === null) return
    const box = addedBoxes.current[jumpTo.current]
    jumpTo.current = null
    box?.focus()
    box?.select()
  }, [added])
  const setAddedLine = (batchId: number, key: 'quantity' | 'discount', value: string) => setAdded((rows) => rows.map((r) => (r.batchId === batchId ? { ...r, [key]: value } : r)))
  /** Enter in a quantity box goes back to the medicine box for the next one, instead of saving the bill. */
  const backToPick = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      e.preventDefault()
      pickBox.current?.focus()
    }
  }

  // Coming from the billing screen's "Add medicines" link: that bill opens ready to be added to.
  // The bill whose customer is being looked at as a whole: all their bills in one window.
  const [together, setTogether] = useState<number | null>(null)
  const [params, setParams] = useSearchParams()
  // Coming from a patient's page: that bill opens to be looked at.
  const preset = params.get('add')
  const linked = params.get('open')
  useEffect(() => {
    if (!preset && !linked) return
    show({ id: Number(preset ?? linked) } as PharmacySale, preset ? 'edit' : 'view')
    setParams({}, { replace: true })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [preset, linked])
  const setLine = (id: number, key: 'quantity' | 'discount_percent', value: string) => setEdit((e) => (e ? { ...e, lines: { ...e.lines, [id]: { ...e.lines[id], [key]: value } } } : e))

  const live = sales?.filter((s) => !s.voided_at) ?? []
  const billed = live.reduce((sum, s) => sum + s.total - s.returned, 0)
  const collected = live.reduce((sum, s) => sum + s.paid, 0)
  const due = live.reduce((sum, s) => sum + s.due, 0)

  return (
    <>
      <PharmacyTabs />
      <div className="split">
        <section className="card">
          <div className="toolbar">
            <h1>Pharmacy bills</h1>
            {!searching && <DateRange from={from} to={to} onChange={setRange} />}
            <input className="search" type="search" placeholder="Find by bill no., customer or phone (all dates)" value={query} onChange={(e) => setQuery(e.target.value)} />
          </div>
          <div className="tiles small">
            <div className="tile"><span>Bills</span><strong>{live.length}</strong></div>
            <div className="tile"><span>Billed (net of returns)</span><strong>{rupees(Math.round(billed * 100) / 100)}</strong></div>
            <div className="tile"><span>Collected</span><strong>{rupees(collected)}</strong></div>
            <div className="tile"><span>Balance due</span><strong className={due > 0 ? 'due' : ''}>{rupees(Math.round(due * 100) / 100)}</strong></div>
          </div>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Bill</th>
                  <th>Customer</th>
                  <th className="num">Amount</th>
                  <th className="num">Paid</th>
                  <th className="num">Balance</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {sales?.map((s) => (
                  <tr key={s.id} className={`static ${s.voided_at ? 'faded' : ''} ${open?.id === s.id ? 'selected' : ''}`}>
                    <td>
                      <button className="link mono" onClick={() => show(s, 'view')}>
                        {s.bill_no}
                      </button>
                      <small className="muted block">
                        {longDate(s.sold_at)} {clock(s.sold_at)}
                      </small>
                    </td>
                    <td>
                      <button type="button" className="patient-link strong" onClick={() => setTogether(s.id)} title="All of this customer's pharmacy bills together">
                        {s.customer_name}
                      </button>
                      {s.customer_phone && <small className="muted block">{s.customer_phone}</small>}
                    </td>
                    <td className="num">
                      {rupees(s.total)}
                      {s.returned > 0 && <small className="block muted">{rupees(s.returned)} returned</small>}
                    </td>
                    <td className="num">
                      {rupees(s.paid)}
                      {s.ipd_transfer > 0 && <small className="block muted">{rupees(s.ipd_transfer)} on IPD bill</small>}
                    </td>
                    <td className={`num ${s.due > 0 ? 'due strong' : ''}`}>{s.voided_at ? <span className="pill off">Deleted</span> : rupees(s.due)}</td>
                    <td className="num actions">
                      {!s.voided_at && canEdit && s.due > 0 && (
                        <button className="link" onClick={() => show(s, 'collect')}>
                          Collect
                        </button>
                      )}
                      <a className="link" href={`/pharmacy/bills/${s.id}/print`} target="_blank" rel="noreferrer">
                        Print
                      </a>
                      {!s.voided_at && canEdit && (
                        <>
                          {editable(s) && (
                            <button className="link" title="Add more medicines to this bill, or correct it" onClick={() => show(s, 'edit')}>
                              Add / edit
                            </button>
                          )}
                          <button className="link" onClick={() => show(s, 'return')}>
                            Return
                          </button>
                          {user?.can_delete_bills && (
                            <button className="link danger" onClick={() => show(s, 'delete')}>
                              Delete
                            </button>
                          )}
                        </>
                      )}
                    </td>
                  </tr>
                ))}
                {sales && sales.length === 0 && (
                  <tr className="empty">
                    <td colSpan={6}>{searching ? 'No bills match this search.' : 'No pharmacy bills in this period.'}</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </section>

        {open && (
          <form className="card panel wide" onSubmit={submit}>
            <div className="panel-head">
              <div>
                <h2>
                  Bill {open.bill_no} {open.voided_at && <span className="pill off">Deleted</span>}
                </h2>
                <span className="muted">
                  <PatientLink id={open.patient_id} bills>
                    {open.customer_name}
                  </PatientLink>{' '}
                  · {longDate(open.sold_at)} {clock(open.sold_at)}
                  {open.referred_doctor ? ` · ${open.referred_doctor}` : ''}
                </span>
              </div>
              <div className="row">
                {canEdit && action === 'view' && editable(open) && (
                  <button type="button" className="btn" onClick={() => show(open, 'edit')}>
                    Add medicines
                  </button>
                )}
                <button type="button" className="btn ghost" onClick={() => setOpen(null)}>
                  Close
                </button>
              </div>
            </div>
            {notice && <p className="notice">{notice}</p>}
            {open.voided_at && <p className="form-error">Deleted on {longDate(open.voided_at)}: {open.void_reason}</p>}

            {action === 'edit' && edit && (
              <div className="add-to-bill">
                <h3>Add medicines to this bill</h3>
                <MedicinePick inputRef={pickBox} onPick={pickMedicine} hint="↑ ↓ to choose · Enter to add · type the quantity · Enter for the next medicine" />
              </div>
            )}

            <table className="compact">
              <thead>
                <tr>
                  <th>Medicine</th>
                  <th>Batch</th>
                  <th className="num">Qty</th>
                  <th className="num">Amount</th>
                  {action === 'return' && <th className="num">Take back</th>}
                  {action === 'edit' && (
                    <>
                      <th className="num">New qty</th>
                      <th className="num">Disc %</th>
                    </>
                  )}
                </tr>
              </thead>
              <tbody>
                {open.items?.map((item) => {
                  const left = item.quantity - item.returned_quantity
                  return (
                    <tr key={item.id} className="static">
                      <td>{item.medicine_name}</td>
                      <td>{item.batch_no}</td>
                      <td className="num">
                        {item.quantity}
                        {item.returned_quantity > 0 && <small className="block muted">{item.returned_quantity} returned</small>}
                      </td>
                      <td className="num">{rupees(item.amount)}</td>
                      {action === 'return' && (
                        <td className="num">
                          <input
                            className="qty"
                            type="number"
                            min={0}
                            max={left}
                            disabled={left === 0}
                            placeholder={left === 0 ? '—' : `of ${left}`}
                            value={quantities[item.id] ?? ''}
                            onChange={(e) => setQuantities((q) => ({ ...q, [item.id]: e.target.value }))}
                            aria-label={`Units of ${item.medicine_name} to take back`}
                          />
                        </td>
                      )}
                      {action === 'edit' && edit && (
                        <>
                          <td className="num">
                            <input className="qty" type="number" min={0} value={edit.lines[item.id]?.quantity ?? ''} onChange={(e) => setLine(item.id, 'quantity', e.target.value)} aria-label={`New quantity of ${item.medicine_name}`} />
                          </td>
                          <td className="num">
                            <input className="qty" type="number" min={0} max={100} step="0.01" value={edit.lines[item.id]?.discount_percent ?? ''} onChange={(e) => setLine(item.id, 'discount_percent', e.target.value)} aria-label={`Discount percent on ${item.medicine_name}`} />
                          </td>
                        </>
                      )}
                    </tr>
                  )
                })}
                {action === 'edit' &&
                  added.map((a) => (
                    <tr key={`new-${a.batchId}`} className="static added-line">
                      <td>
                        {a.medicine} <span className="pill ok">New</span>
                        {a.place && <small className="shelf-place block">{a.place}</small>}
                        <button type="button" className="link danger block" onClick={() => setAdded((rows) => rows.filter((r) => r.batchId !== a.batchId))}>
                          Take off
                        </button>
                      </td>
                      <td>
                        {a.batchNo}
                        <small className="block muted">{a.available} in stock</small>
                      </td>
                      <td className="num muted">—</td>
                      <td className="num">{rupees(addedAmount(a))}</td>
                      <td className="num">
                        <input
                          ref={(el) => {
                            addedBoxes.current[a.batchId] = el
                          }}
                          className="qty"
                          type="number"
                          min={1}
                          max={a.available}
                          value={a.quantity}
                          onChange={(e) => setAddedLine(a.batchId, 'quantity', e.target.value)}
                          onKeyDown={backToPick}
                          aria-label={`Quantity of ${a.medicine} to add`}
                        />
                      </td>
                      <td className="num">
                        <input className="qty" type="number" min={0} max={100} step="0.01" value={a.discount} onChange={(e) => setAddedLine(a.batchId, 'discount', e.target.value)} onKeyDown={backToPick} aria-label={`Discount percent on ${a.medicine}`} />
                      </td>
                    </tr>
                  ))}
              </tbody>
            </table>

            <dl className="totals">
              <dt>Bill amount</dt>
              <dd>{rupees(open.total)}</dd>
              {open.returned > 0 && (
                <>
                  <dt>Returned</dt>
                  <dd>−{rupees(open.returned)}</dd>
                </>
              )}
              <dt>Paid</dt>
              <dd>{rupees(open.paid)}</dd>
              <dt className="strong">Balance</dt>
              <dd className={`strong ${open.due > 0 ? 'due' : 'settled'}`}>{rupees(open.due)}</dd>
            </dl>

            {(open.payments?.length ?? 0) > 0 && (
              <ul className="history payments-list gap-top">
                {open.payments!.map((p) => (
                  <li key={p.id}>
                    <span>
                      {p.type === 'refund' ? 'Refund' : 'Payment'} · {modeLabel(p.mode)}
                      {p.reference ? ` · ${p.reference}` : ''}
                      <small className="muted"> {new Date(p.created_at).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: 'numeric', minute: '2-digit' })}</small>
                    </span>
                    {canEdit && !open.voided_at && PAYMENT_MODES.some((m) => m.value === p.mode) && (
                      <button
                        type="button"
                        className="link"
                        title="Entered under the wrong mode? Correct it here"
                        onClick={() => {
                          setFixing(p)
                          setMode(p.mode)
                          setReference(p.reference ?? '')
                          setReason('')
                          setError('')
                          setNotice('')
                          setAction('mode')
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
            )}

            {error && <p className="form-error">{error}</p>}

            {action === 'collect' && (
              <div className="action-box">
                <h3>Collect balance</h3>
                <div className="row">
                  <input type="number" min={0} step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} aria-label="Amount" />
                  <ModeSelect value={mode} onChange={setMode} />
                  <button className="btn primary" disabled={!amount || Number(amount) <= 0}>
                    Collect
                  </button>
                </div>
              </div>
            )}
            {action === 'return' && (
              <div className="action-box">
                <h3>Take medicines back</h3>
                <p className="muted small">Enter the units coming back against each medicine above. They go back into stock, and any money paid beyond what the bill is then worth is refunded.</p>
                <div className="row">
                  <span className="grow">Refund by</span>
                  <ModeSelect value={mode} onChange={setMode} />
                  <button className="btn primary" disabled={!Object.values(quantities).some((q) => Number(q) > 0)}>
                    Take back
                  </button>
                </div>
              </div>
            )}
            {action === 'edit' && edit && (
              <div className="action-box">
                <h3>{toAdd.length > 0 && !corrected ? 'Adding to this bill' : 'Edit this bill'}</h3>
                <p className="muted small">Medicines you add go on the bill at today's price. To correct what is already on it, change the quantities and discounts above; a quantity of 0 takes that medicine off. Stock follows either way.</p>
                <details className="bill-details">
                  <summary>Customer details and bill discount</summary>
                  <div className="grid">
                    <label className="field">
                      <span className="field-label">Customer</span>
                      <input value={edit.customer_name} onChange={(e) => setEdit({ ...edit, customer_name: e.target.value })} />
                    </label>
                    <label className="field">
                      <span className="field-label">Phone</span>
                      <input inputMode="numeric" value={edit.customer_phone} onChange={(e) => setEdit({ ...edit, customer_phone: e.target.value })} />
                    </label>
                    <label className="field">
                      <span className="field-label">Doctor</span>
                      <input value={edit.referred_doctor} onChange={(e) => setEdit({ ...edit, referred_doctor: e.target.value })} />
                    </label>
                    <div className="field">
                      <span className="field-label">Bill discount</span>
                      <BillDiscountBox value={edit.discount} onChange={(d) => setEdit({ ...edit, discount: d })} subtotal={editedSubtotal} />
                    </div>
                  </div>
                </details>
                <p className="net">
                  New bill total <strong>{rupees(Math.max(0, editedTotal))}</strong>
                  {open.paid > editedTotal && <span className="due"> · {rupees(Math.round((open.paid - Math.max(0, editedTotal)) * 100) / 100)} will be refunded</span>}
                  {open.paid < editedTotal && <span className="muted"> · {rupees(Math.round((editedTotal - open.paid) * 100) / 100)} will be due</span>}
                </p>
                {newDue > 0 && (
                  <div className="row collect-now">
                    <span className="grow">Collect now (₹)</span>
                    <input className="qty wide" type="number" min={0} max={newDue} step="0.01" value={collectValue} onChange={(e) => setCollect(e.target.value)} aria-label="Amount to collect now" />
                    <ModeSelect value={mode} onChange={setMode} />
                  </div>
                )}
                <input className="gap-top" placeholder={corrected ? 'Reason for the change (required)' : 'Reason (not needed when only adding medicines)'} value={reason} onChange={(e) => setReason(e.target.value)} aria-label="Reason" />
                <div className="row gap-top">
                  <span className="grow">{open.paid > editedTotal ? 'Refund by' : ''}</span>
                  {open.paid > editedTotal && <ModeSelect value={mode} onChange={setMode} />}
                  <button className="btn primary" disabled={!edit.customer_name.trim() || (corrected ? reason.trim().length < 3 : toAdd.length === 0)}>
                    {toAdd.length > 0 && !corrected ? 'Add to bill' : 'Save changes'}
                  </button>
                </div>
              </div>
            )}
            {action === 'mode' && fixing && (
              <div className="action-box">
                <h3>Change how this was paid</h3>
                <p className="muted small">
                  {fixing.type === 'refund' ? 'Refund' : 'Payment'} of {rupees(fixing.amount)}, recorded as <strong>{modeLabel(fixing.mode)}</strong>. Only the mode changes: the amount and the day it was taken stay as they are.
                </p>
                <div className="row">
                  <ModeSelect value={mode} onChange={setMode} />
                  <input placeholder="Reference, such as the UPI or card slip number (optional)" value={reference} onChange={(e) => setReference(e.target.value)} aria-label="Reference" />
                </div>
                <input className="gap-top" placeholder="Reason for the change (required)" value={reason} onChange={(e) => setReason(e.target.value)} aria-label="Reason" />
                <div className="row gap-top">
                  <span className="grow" />
                  <button type="button" className="btn ghost" onClick={() => setAction('view')}>
                    Cancel
                  </button>
                  <button className="btn primary" disabled={reason.trim().length < 3 || (mode === fixing.mode && reference.trim() === (fixing.reference ?? ''))}>
                    Save as {modeLabel(mode)}
                  </button>
                </div>
              </div>
            )}
            {action === 'delete' && (
              <div className="action-box danger">
                <h3>Delete this bill</h3>
                <p className="muted small">Everything on it goes back into stock and {open.paid > 0 ? `${rupees(open.paid)} is refunded` : 'nothing needs refunding'}. The bill stays on record as deleted.</p>
                <input placeholder="Reason for deleting (required)" value={reason} onChange={(e) => setReason(e.target.value)} aria-label="Reason" />
                <div className="row gap-top">
                  <span className="grow">Refund by</span>
                  <ModeSelect value={mode} onChange={setMode} />
                  <button className="btn danger-btn" disabled={reason.trim().length < 3}>
                    Delete bill
                  </button>
                </div>
              </div>
            )}
          </form>
        )}
      </div>
      {together !== null && (
        <PharmacyDay
          saleId={together}
          canCollect={canEdit}
          onClose={() => setTogether(null)}
          onChange={load}
          onOpenSale={(id) => {
            setTogether(null)
            show({ id } as PharmacySale, 'view')
          }}
        />
      )}
    </>
  )
}
