import { useEffect, useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, fieldErrors } from '../../api'
import { useAuth } from '../../auth'
import { SelectField, TextField } from '../../components/Field'
import { ADMISSION_STATUS, PAYMENT_MODES, clock, longDate, modeLabel, nowLocal, rupees, today } from '../../format'
import type { Admission, Bed, IpdBillTemplate, IpdService, OutstandingBill } from '../../types'
import { FormsPanel } from '../../components/FormsPanel'
import { AttachmentsPanel } from '../../components/FrontDeskPanels'
import { IpdInvestigations } from './IpdInvestigations'
import { IpdNotes } from './IpdNotes'
import { IpdSummary } from './IpdSummary'

const CLOSE_OPTIONS = ['discharged', 'lama', 'dama', 'absconded', 'died'].map((s) => ({
  value: s,
  label: { discharged: 'Discharged', lama: 'LAMA (left against medical advice)', dama: 'DAMA (discharged against medical advice)', absconded: 'Absconded', died: 'Died' }[s]!,
}))
const PAYMENT_TYPES = [
  { value: 'advance', label: 'Advance' },
  { value: 'payment', label: 'Payment' },
  { value: 'refund', label: 'Refund' },
]

/** Days the bed has been used so far, counting part of a day as a full day. */
const stayDays = (a: Admission) => {
  const end = a.discharged_at ? new Date(a.discharged_at.replace(' ', 'T')) : new Date()
  return Math.max(1, Math.ceil((end.getTime() - new Date(a.admitted_at.replace(' ', 'T')).getTime()) / 86400000))
}

export function IpdCase() {
  const { id } = useParams()
  const [admission, setAdmission] = useState<Admission | null>(null)
  const [services, setServices] = useState<IpdService[]>([])
  const [beds, setBeds] = useState<Bed[]>([])
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [charge, setCharge] = useState({ ipd_service_id: '', description: '', charged_on: today(), quantity: '1', unit_price: '', discount: '', remarks: '' })
  /** The charge being corrected, when the form is editing one instead of adding. */
  const [editingCharge, setEditingCharge] = useState<number | null>(null)
  const [showDeleted, setShowDeleted] = useState(false)
  const [billUpTo, setBillUpTo] = useState('')
  const [billNote, setBillNote] = useState('')
  const [ids, setIds] = useState({ aadhaar_no: '', gst_no: '' })
  const [pay, setPay] = useState({ type: 'advance', amount: '', mode: 'cash', reference: '', remarks: '', paid_on: today() })
  const [discount, setDiscount] = useState('')
  const [closing, setClosing] = useState({ status: 'discharged', discharged_at: nowLocal() })
  const { user } = useAuth()
  const [tab, setTab] = useState<'bill' | 'notes' | 'summary' | 'results' | 'files' | 'forms'>('bill')
  const [templates, setTemplates] = useState<IpdBillTemplate[]>([])
  const [bedId, setBedId] = useState('')
  const [outstanding, setOutstanding] = useState<OutstandingBill[]>([])

  const apply = (next: Admission) => {
    setAdmission(next)
    setDiscount(String(next.discount))
    setBedId(next.bed ? String(next.bed.id) : '')
    setIds({ aadhaar_no: next.aadhaar_no ?? '', gst_no: next.gst_no ?? '' })
    setError('')
    api.get<OutstandingBill[]>(`/ipd/admissions/${next.id}/outstanding`).then((res) => setOutstanding(res.data))
  }

  useEffect(() => {
    api.get<Admission>(`/ipd/admissions/${id}`).then((res) => apply(res.data))
    api.get<IpdService[]>('/ipd/services').then((res) => setServices(res.data))
    api.get<Bed[]>('/ipd/beds').then((res) => setBeds(res.data))
    api.get<IpdBillTemplate[]>('/ipd/templates').then((res) => setTemplates(res.data))
  }, [id])

  if (!admission) return <p className="loading">Loading case…</p>

  const url = `/ipd/admissions/${admission.id}`
  const open = admission.status === 'admitted'
  const billLocked = Boolean(admission.bill_approved_at)
  const billNo = Object.fromEntries((admission.bills ?? []).map((b) => [b.id, b.bill_no]))
  const unbilled = (admission.charges ?? []).filter((c) => !c.admission_bill_id)
  const unbilledUpTo = unbilled.filter((c) => !billUpTo || c.charged_on <= billUpTo)
  const canEdit = Boolean(user?.can_ipd)

  // Sends one change, then shows the updated case or the reason it was refused.
  const run = async (request: Promise<{ data: Admission }>, done?: string) => {
    setNotice('')
    try {
      apply((await request).data)
      if (done) setNotice(done)
      return true
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
      return false
    }
  }

  const pickService = (value: string) => {
    const service = services.find((s) => String(s.id) === value)
    setCharge((c) => ({ ...c, ipd_service_id: value, description: service?.name ?? c.description, unit_price: service ? String(service.price) : c.unit_price }))
  }

  const addCharge = async (e: FormEvent) => {
    e.preventDefault()
    const body = { ...charge, ipd_service_id: charge.ipd_service_id || null, discount: charge.discount || 0, remarks: charge.remarks || null }
    const ok = await run(editingCharge ? api.put(`${url}/charges/${editingCharge}`, body) : api.post(`${url}/charges`, body), editingCharge ? 'Charge corrected.' : undefined)
    if (ok) {
      setCharge((c) => ({ ...c, ipd_service_id: '', description: '', quantity: '1', unit_price: '', discount: '', remarks: '' }))
      setEditingCharge(null)
    }
  }

  const addBedCharge = () =>
    setCharge({
      ipd_service_id: '',
      description: `Bed charges: ${admission.bed!.ward} bed ${admission.bed!.bed_no}`,
      charged_on: today(),
      quantity: String(stayDays(admission)),
      unit_price: String(admission.bed!.daily_charge ?? 0),
      discount: '',
      remarks: '',
    })

  const addPayment = async (e: FormEvent) => {
    e.preventDefault()
    const ok = await run(api.post(`${url}/payments`, { ...pay, reference: pay.reference || null, remarks: pay.remarks || null }))
    if (ok) setPay((p) => ({ ...p, amount: '', reference: '', remarks: '', paid_on: today() }))
  }

  const freeBeds = beds.filter((b) => b.is_active && (!b.current_admission || b.id === admission.bed?.id))

  return (
    <div className="split main-first">
      <section className="card">
        <div className="toolbar">
          <Link className="btn ghost" to="/ipd">
            ← Cases
          </Link>
          <div className="grow">
            <h1>
              {admission.patient.name} · {admission.ipd_no}
            </h1>
            <span className="muted">
              {admission.patient.uhid} · {admission.patient.age_label} / {admission.patient.gender.charAt(0).toUpperCase()} · {admission.patient.mobile} · {admission.doctor.name} ·
              admitted {longDate(admission.admitted_at)} {clock(admission.admitted_at)} ({stayDays(admission)} day{stayDays(admission) === 1 ? '' : 's'})
              {admission.reason ? ` · ${admission.reason}` : ''}
              {admission.payer ? ` · Bill to: ${admission.payer.name}` : ''}
            </span>
          </div>
          <span className={`pill ${open ? 'status-ongoing' : admission.status === 'discharged' ? 'ok' : 'off'}`}>{ADMISSION_STATUS[admission.status]}</span>
          <a className="btn ghost" href={`/ipd/${admission.id}/print`} target="_blank" rel="noreferrer">
            Print bill
          </a>
        </div>

        <div className="tabs flush gap-bottom">
          <button className={tab === 'bill' ? 'active' : ''} onClick={() => setTab('bill')}>
            Bill
          </button>
          <button className={tab === 'notes' ? 'active' : ''} onClick={() => setTab('notes')}>
            Notes
          </button>
          <button className={tab === 'summary' ? 'active' : ''} onClick={() => setTab('summary')}>
            Discharge summary
          </button>
          <button className={tab === 'results' ? 'active' : ''} onClick={() => setTab('results')}>
            Investigations
          </button>
          <button className={tab === 'files' ? 'active' : ''} onClick={() => setTab('files')}>
            Attachments
          </button>
          <button className={tab === 'forms' ? 'active' : ''} onClick={() => setTab('forms')}>
            Forms
          </button>
        </div>

        {tab === 'results' && <IpdInvestigations admissionId={admission.id} />}
        {tab === 'forms' && <FormsPanel url={`/ipd/admissions/${admission.id}/forms`} use="ipd" canEdit={canEdit} />}
        {tab === 'files' && <AttachmentsPanel patient={{ id: admission.patient.id, name: admission.patient.name, uhid: admission.patient.uhid }} />}

        {tab === 'notes' && <IpdNotes admissionId={admission.id} canEdit={canEdit} />}
        {tab === 'summary' && <IpdSummary admissionId={admission.id} canEdit={canEdit} canApprove={Boolean(user?.can_approve_ipd)} />}
        {tab === 'bill' && (
        <>
        {notice && <p className="notice">{notice}</p>}
        {error && <p className="form-error gap-bottom">{error}</p>}
        {billLocked && (
          <p className="notice">
            This bill was approved by {admission.bill_approver?.name ?? 'a doctor'} on {longDate(admission.bill_approved_at!)}. Charges and discount are locked; payments can still be taken.
          </p>
        )}

        <h3>Charges</h3>
        <div className="table-wrap">
          <table className="compact">
            <thead>
              <tr>
                <th>Date</th>
                <th>Charge</th>
                <th>Category</th>
                <th className="num">Qty</th>
                <th className="num">Rate</th>
                <th className="num">Discount</th>
                <th className="num">Amount</th>
                <th>Added by</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {admission.charges?.map((c) => (
                <tr key={c.id} className={`static ${editingCharge === c.id ? 'selected' : ''}`}>
                  <td>{longDate(c.charged_on)}</td>
                  <td>
                    {c.description}
                    {c.remarks && <small className="muted block">{c.remarks}</small>}
                    {c.admission_bill_id && <small className="settled block">On bill {billNo[c.admission_bill_id] ?? ''}</small>}
                  </td>
                  <td>{c.category ?? '—'}</td>
                  <td className="num">{c.quantity}</td>
                  <td className="num">{rupees(c.unit_price)}</td>
                  <td className="num">{c.discount > 0 ? rupees(c.discount) : '—'}</td>
                  <td className="num">{rupees(c.amount)}</td>
                  <td>{c.creator?.name ?? '—'}</td>
                  <td className="num actions">
                    {!billLocked && !c.source_type && !c.admission_bill_id && (
                      <button
                        className="link"
                        onClick={() => {
                          setEditingCharge(c.id)
                          setCharge({ ipd_service_id: '', description: c.description, charged_on: c.charged_on, quantity: String(c.quantity), unit_price: String(c.unit_price), discount: c.discount ? String(c.discount) : '', remarks: c.remarks ?? '' })
                        }}
                      >
                        Edit
                      </button>
                    )}
                    {!billLocked && !c.admission_bill_id && (
                      <button className="link danger" onClick={() => window.confirm(`Remove "${c.description}" from the bill?`) && run(api.delete(`${url}/charges/${c.id}`))}>
                        Remove
                      </button>
                    )}
                  </td>
                </tr>
              ))}
              {admission.charges?.length === 0 && (
                <tr className="empty">
                  <td colSpan={9}>No charges added yet.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>

        {!billLocked && (
        <>
        <form className="charge-row" onSubmit={addCharge}>
          <select value={charge.ipd_service_id} disabled={Boolean(editingCharge)} onChange={(e) => pickService(e.target.value)} aria-label="IPD service">
            <option value="">Choose a service…</option>
            {services.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}
                {s.category ? ` (${s.category})` : ''}
              </option>
            ))}
          </select>
          <input placeholder="or describe the charge" value={charge.description} disabled={Boolean(editingCharge)} onChange={(e) => setCharge((c) => ({ ...c, description: e.target.value }))} aria-label="Charge description" />
          <input type="date" value={charge.charged_on} max={today()} onChange={(e) => setCharge((c) => ({ ...c, charged_on: e.target.value }))} aria-label="Charge date" />
          <input type="number" min={0} step="0.5" placeholder="Qty" value={charge.quantity} onChange={(e) => setCharge((c) => ({ ...c, quantity: e.target.value }))} aria-label="Quantity" />
          <input type="number" min={0} step="0.01" placeholder="Rate ₹" value={charge.unit_price} onChange={(e) => setCharge((c) => ({ ...c, unit_price: e.target.value }))} aria-label="Rate" />
          <input type="number" min={0} step="0.01" placeholder="Disc. ₹" value={charge.discount} onChange={(e) => setCharge((c) => ({ ...c, discount: e.target.value }))} aria-label="Discount on this charge" />
          <input placeholder="Remarks (optional)" value={charge.remarks} onChange={(e) => setCharge((c) => ({ ...c, remarks: e.target.value }))} aria-label="Remarks" />
          <button className="btn primary" disabled={!charge.description || charge.unit_price === ''}>
            {editingCharge ? 'Save charge' : 'Add charge'}
          </button>
          {editingCharge && (
            <button
              type="button"
              className="btn ghost"
              onClick={() => {
                setEditingCharge(null)
                setCharge((c) => ({ ...c, ipd_service_id: '', description: '', quantity: '1', unit_price: '', discount: '', remarks: '' }))
              }}
            >
              Cancel
            </button>
          )}
        </form>
        {admission.bed && (admission.bed.daily_charge ?? 0) > 0 && (
          <button type="button" className="link add-medicine" onClick={addBedCharge}>
            Fill in bed charges for {stayDays(admission)} day(s) at {rupees(admission.bed.daily_charge!)} a day
          </button>
        )}
        {templates.length > 0 && (
          <div className="row gap-top">
            <select
              className="narrow"
              value=""
              onChange={(e) => {
                const template = templates.find((t) => String(t.id) === e.target.value)
                if (template && window.confirm(`Add the ${template.items.length} service(s) in "${template.name}" to this bill?`)) run(api.post(`${url}/templates/${template.id}`), `"${template.name}" added to the bill.`)
              }}
              aria-label="Add a bill template"
            >
              <option value="">Add a bill template…</option>
              {templates.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name} ({t.items.length} services)
                </option>
              ))}
            </select>
          </div>
        )}
        </>
        )}

        {outstanding.length > 0 && (
          <div className="rx-card gap-top">
            <strong>Other unpaid bills for this patient during the stay</strong>
            <ul>
              {outstanding.map((o) => (
                <li key={`${o.type}-${o.id}`}>
                  <span className="grow">
                    {o.label}
                    <small className="muted"> · {longDate(o.date)}</small>
                  </span>
                  <span className="mono">{rupees(o.due)} due</span>
                  <button type="button" className="link" onClick={() => run(api.post(`${url}/transfer`, { type: o.type, id: o.id }), `${o.label} was added to the IPD bill.`)}>
                    Add to IPD bill
                  </button>
                </li>
              ))}
            </ul>
          </div>
        )}

        {(admission.charges?.length ?? 0) > 0 && (
          <div className="numbered-bills gap-top">
            <h3>Numbered bills</h3>
            <p className="muted small">Group the charges so far into a bill with its own number, for example an interim bill every few days. Charges on a bill cannot be changed unless the bill is cancelled.</p>
            {(admission.bills?.length ?? 0) > 0 && (
              <div className="table-wrap">
                <table className="compact">
                  <thead>
                    <tr>
                      <th>Bill no.</th>
                      <th>Date</th>
                      <th>Note</th>
                      <th>Made by</th>
                      <th className="num">Lines</th>
                      <th className="num">Amount</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {admission.bills!.map((b) => (
                      <tr key={b.id} className="static">
                        <td className="mono">{b.bill_no}</td>
                        <td>{longDate(b.billed_on)}</td>
                        <td>{b.notes ?? '—'}</td>
                        <td>{b.creator?.name ?? '—'}</td>
                        <td className="num">{admission.charges?.filter((c) => c.admission_bill_id === b.id).length}</td>
                        <td className="num">{rupees(b.total)}</td>
                        <td className="num actions">
                          <a className="link" href={`/ipd/${admission.id}/bills/${b.id}/print`} target="_blank" rel="noreferrer">
                            Print
                          </a>
                          {canEdit && !billLocked && (
                            <button className="link danger" onClick={() => window.confirm(`Cancel bill ${b.bill_no}? Its charges stay on the case and can be billed again. The number will not be reused.`) && run(api.delete(`${url}/bills/${b.id}`), `Bill ${b.bill_no} cancelled.`)}>
                              Cancel
                            </button>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            {canEdit && unbilled.length > 0 && (
              <div className="toolbar gap-top">
                <span>
                  Not yet on a bill: <strong>{unbilled.length}</strong> charge{unbilled.length === 1 ? '' : 's'}, <strong>{rupees(unbilled.reduce((sum, c) => sum + c.amount, 0))}</strong>
                </span>
                <label className="inline-field">
                  Charges up to
                  <input type="date" className="date" max={today()} value={billUpTo} onChange={(e) => setBillUpTo(e.target.value)} aria-label="Bill charges up to this date" />
                </label>
                <input className="narrow" placeholder="Note (optional)" maxLength={255} value={billNote} onChange={(e) => setBillNote(e.target.value)} aria-label="Note for the bill" />
                <button
                  className="btn"
                  disabled={unbilledUpTo.length === 0}
                  onClick={async () => {
                    const ok = await run(api.post(`${url}/bills`, { up_to: billUpTo || null, notes: billNote || null }), 'Bill made. Print it from the list.')
                    if (ok) {
                      setBillUpTo('')
                      setBillNote('')
                    }
                  }}
                >
                  Make bill for {rupees(unbilledUpTo.reduce((sum, c) => sum + c.amount, 0))}
                </button>
              </div>
            )}
            {unbilled.length === 0 && <p className="settled small">Every charge is on a numbered bill.</p>}
          </div>
        )}

        {(admission.deleted_charges?.length ?? 0) > 0 && (
          <div className="gap-top">
            <button type="button" className="link" onClick={() => setShowDeleted((v) => !v)}>
              {showDeleted ? 'Hide' : 'Show'} {admission.deleted_charges!.length} removed charge{admission.deleted_charges!.length === 1 ? '' : 's'}
            </button>
            {showDeleted && (
              <ul className="history">
                {admission.deleted_charges!.map((c) => (
                  <li key={c.id}>
                    <span>
                      {c.description} · {c.quantity} × {rupees(c.unit_price)}
                      <small className="muted">
                        {' '}
                        billed {longDate(c.charged_on)}, removed {c.deleted_at ? longDate(c.deleted_at) : ''}
                        {c.deleter ? ` by ${c.deleter.name}` : ''}
                      </small>
                    </span>
                    <strong className="muted">{rupees(c.amount)}</strong>
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}

        <h3>Advances, payments and refunds</h3>
        <ul className="history">
          {admission.payments?.map((p) => (
            <li key={p.id}>
              <span>
                {PAYMENT_TYPES.find((t) => t.value === p.type)?.label} · {modeLabel(p.mode)}
                {p.reference ? ` · ${p.reference}` : ''}
                {p.remarks ? ` · ${p.remarks}` : ''}
                <small className="muted">
                  {' '}
                  {new Date(p.created_at).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: 'numeric', minute: '2-digit' })}
                  {p.receiver ? ` by ${p.receiver.name}` : ''}
                </small>
              </span>
              <a className="link" href={`/ipd/${admission.id}/receipt/${p.id}`} target="_blank" rel="noreferrer">
                Receipt
              </a>
              {user?.can_approve_ipd && (
                <button type="button" className="link danger" title="Remove an entry made by mistake" onClick={() => window.confirm('Delete this entry? Use this only for a mistake; money handed back should be entered as a refund.') && run(api.delete(`${url}/payments/${p.id}`), 'Entry deleted.')}>
                  Delete
                </button>
              )}
              <strong className={p.type === 'refund' ? 'due' : ''}>
                {p.type === 'refund' ? '−' : ''}
                {rupees(p.amount)}
              </strong>
            </li>
          ))}
          {admission.payments?.length === 0 && <li className="muted">Nothing received yet.</li>}
        </ul>
        <form className="charge-row pay" onSubmit={addPayment}>
          <select value={pay.type} onChange={(e) => setPay((p) => ({ ...p, type: e.target.value }))} aria-label="Type">
            {PAYMENT_TYPES.map((t) => (
              <option key={t.value} value={t.value}>
                {t.label}
              </option>
            ))}
          </select>
          <input type="number" min={0} step="0.01" placeholder="Amount ₹" value={pay.amount} onChange={(e) => setPay((p) => ({ ...p, amount: e.target.value }))} aria-label="Amount" />
          <select value={pay.mode} onChange={(e) => setPay((p) => ({ ...p, mode: e.target.value }))} aria-label="Mode">
            {PAYMENT_MODES.map((m) => (
              <option key={m.value} value={m.value}>
                {m.label}
              </option>
            ))}
          </select>
          <input placeholder="Reference (optional)" value={pay.reference} onChange={(e) => setPay((p) => ({ ...p, reference: e.target.value }))} aria-label="Reference" />
          <input type="date" value={pay.paid_on} max={today()} min={admission.admitted_at.slice(0, 10)} onChange={(e) => setPay((p) => ({ ...p, paid_on: e.target.value || today() }))} aria-label="Date received" title="Date the money was received" />
          <input placeholder="Remarks (optional)" value={pay.remarks} onChange={(e) => setPay((p) => ({ ...p, remarks: e.target.value }))} aria-label="Payment remarks" />
          <button className="btn primary" disabled={!pay.amount || Number(pay.amount) <= 0}>
            Record
          </button>
        </form>
        </>
        )}
      </section>

      <aside className="stack panel">
        <div className="card">
          <h2>Bill</h2>
          <dl className="totals">
            <dt>Total charges</dt>
            <dd>{rupees(admission.total)}</dd>
            <dt>Discount</dt>
            <dd>
              <form
                className="row"
                onSubmit={(e) => {
                  e.preventDefault()
                  run(api.put(url, { discount: discount || 0 }))
                }}
              >
                <input className="qty" type="number" min={0} step="0.01" value={discount} disabled={billLocked} onChange={(e) => setDiscount(e.target.value)} aria-label="Discount" />
                <button className="btn ghost" disabled={billLocked || Number(discount || 0) === admission.discount}>
                  Apply
                </button>
              </form>
            </dd>
            <dt className="strong">Net bill</dt>
            <dd className="strong">{rupees(admission.net)}</dd>
            <dt>Received</dt>
            <dd>{rupees(admission.paid)}</dd>
            <dt className="strong">{admission.due < 0 ? 'Advance left' : 'Due'}</dt>
            <dd className={`strong ${admission.due > 0 ? 'due' : 'settled'}`}>{rupees(Math.abs(admission.due))}</dd>
          </dl>
          <div className="row gap-top">
            <span className="grow">{billLocked ? <span className="pill ok">Bill approved</span> : <span className="muted small">Bill not yet approved</span>}</span>
            {user?.can_approve_ipd && (
              <button className="btn ghost small-btn" onClick={() => run(api.post(`${url}/approve-bill`, { approved: !billLocked }), billLocked ? 'Approval withdrawn; the bill can be changed again.' : 'Bill approved and locked.')}>
                {billLocked ? 'Withdraw approval' : 'Approve bill'}
              </button>
            )}
          </div>
        </div>

        <div className="card">
          <h2>Case details</h2>
          <form
            className="grid gap-top"
            onSubmit={(e) => {
              e.preventDefault()
              run(api.put(url, { aadhaar_no: ids.aadhaar_no || null, gst_no: ids.gst_no || null }), 'Case details saved.')
            }}
          >
            <TextField label="Aadhaar number" inputMode="numeric" maxLength={12} value={ids.aadhaar_no} onChange={(v) => setIds((i) => ({ ...i, aadhaar_no: v }))} />
            <TextField label="GST number" maxLength={20} value={ids.gst_no} onChange={(v) => setIds((i) => ({ ...i, gst_no: v }))} />
            <div className="span-2">
              <button className="btn ghost" disabled={ids.aadhaar_no === (admission.aadhaar_no ?? '') && ids.gst_no === (admission.gst_no ?? '')}>
                Save details
              </button>
            </div>
          </form>
        </div>

        <div className="card">
          <h2>Bed</h2>
          {open ? (
            <form
              className="row gap-top"
              onSubmit={(e) => {
                e.preventDefault()
                run(api.put(url, { bed_id: bedId || null }), 'Bed updated.')
              }}
            >
              <select value={bedId} onChange={(e) => setBedId(e.target.value)} aria-label="Bed">
                <option value="">No bed assigned</option>
                {freeBeds.map((b) => (
                  <option key={b.id} value={b.id}>
                    {b.ward} · Bed {b.bed_no}
                  </option>
                ))}
              </select>
              <button className="btn ghost" disabled={bedId === (admission.bed ? String(admission.bed.id) : '')}>
                Move
              </button>
            </form>
          ) : (
            <p className="muted">{admission.bed ? `Was in ${admission.bed.ward}, bed ${admission.bed.bed_no}. The bed is free again.` : 'No bed was assigned.'}</p>
          )}
        </div>

        <div className="card">
          <h2>{open ? 'Discharge' : 'Discharged'}</h2>
          {open ? (
            <form
              onSubmit={(e) => {
                e.preventDefault()
                run(api.post(`${url}/discharge`, { ...closing, discharged_at: closing.discharged_at.replace('T', ' ') }), 'Case closed and bed freed.')
              }}
            >
              <div className="grid gap-top">
                <div className="span-2">
                  <SelectField label="Outcome" required value={closing.status} onChange={(v) => setClosing((c) => ({ ...c, status: v }))} options={CLOSE_OPTIONS} />
                </div>
                <div className="span-2">
                  <TextField label="Date and time" required type="datetime-local" value={closing.discharged_at} onChange={(v) => setClosing((c) => ({ ...c, discharged_at: v }))} />
                </div>
              </div>
              <p className="muted small">
                Write the discharge summary on the{' '}
                <button type="button" className="link" onClick={() => setTab('summary')}>
                  Discharge summary
                </button>{' '}
                tab, before or after closing the case.
              </p>
              {admission.due > 0 && <p className="muted small">{rupees(admission.due)} is still due. You can discharge now and collect it afterwards.</p>}
              <div className="panel-foot">
                <button className="btn primary">Close case</button>
              </div>
            </form>
          ) : (
            <>
              <p className="muted">
                {ADMISSION_STATUS[admission.status]} on {longDate(admission.discharged_at!)} {clock(admission.discharged_at!)}
              </p>
              <button type="button" className="btn ghost" onClick={() => setTab('summary')}>
                Open the discharge summary
              </button>
            </>
          )}
        </div>
      </aside>
    </div>
  )
}
