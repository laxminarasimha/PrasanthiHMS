import { useEffect, useState, type FormEvent } from 'react'
import { PatientDocuments } from '../../components/PatientDocuments'
import { IpdCharts } from './IpdCharts'
import { HealthSummary } from '../../components/HealthSummary'
import { Link, useParams } from 'react-router-dom'
import { api, fieldErrors } from '../../api'
import { canLeave } from '../../unsaved'
import { useAuth } from '../../auth'
import { mayDateBack } from '../../earlierDay'
import { SelectField, TextField } from '../../components/Field'
import { Popup } from '../../components/Popup'
import { ADMISSION_STATUS, PAYMENT_MODES, clock, longDate, modeLabel, nowLocal, rupees, today } from '../../format'
import type { Admission, Bed, IpdBillTemplate, IpdService, OutstandingBill } from '../../types'
import { FormsPanel } from '../../components/FormsPanel'
import { AttachmentsPanel } from '../../components/FrontDeskPanels'
import { IpdConsultants } from './IpdConsultants'
import { IpdInvestigations } from './IpdInvestigations'
import { IpdNotes } from './IpdNotes'
import { IpdSummary } from './IpdSummary'
import { PatientLink } from '../../components/PatientLink'

const CLOSE_OPTIONS = ['discharged', 'lama', 'dama', 'absconded', 'died'].map((s) => ({
  value: s,
  label: { discharged: 'Discharged', lama: 'LAMA (left against medical advice)', dama: 'DAMA (discharged against medical advice)', absconded: 'Absconded', died: 'Died' }[s]!,
}))
const PAYMENT_TYPES = [
  { value: 'advance', label: 'Advance' },
  { value: 'payment', label: 'Payment' },
  { value: 'refund', label: 'Refund' },
]

/** How long one bed was occupied, in days and hours. */
const stayLength = (from: string, to: string | null) => {
  const hours = Math.max(0, Math.round(((to ? new Date(to.replace(' ', 'T')) : new Date()).getTime() - new Date(from.replace(' ', 'T')).getTime()) / 3600000))
  return hours >= 24 ? `${Math.floor(hours / 24)}d ${hours % 24}h` : `${hours}h`
}

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
  /** True while something is being saved, so the money buttons cannot be pressed a second time meanwhile. */
  const [busy, setBusy] = useState(false)
  const [reopening, setReopening] = useState('')
  const [leftAt, setLeftAt] = useState<{ at: string; reason: string } | null>(null)
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
  const [tab, setTab] = useState<'bill' | 'notes' | 'charts' | 'consultants' | 'summary' | 'results' | 'files' | 'forms'>('bill')
  const [templates, setTemplates] = useState<IpdBillTemplate[]>([])
  const [bedId, setBedId] = useState('')
  const [outstanding, setOutstanding] = useState<OutstandingBill[]>([])
  // The payment whose mode is being corrected, with what is typed for it.
  const [fixing, setFixing] = useState<NonNullable<Admission['payments']>[number] | null>(null)
  const [fix, setFix] = useState({ mode: 'cash', reference: '', reason: '' })

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
    setBusy(true)
    try {
      apply((await request).data)
      if (done) setNotice(done)
      return true
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
      return false
    } finally {
      setBusy(false)
    }
  }

  /** Typing a service's name picks it and fills its rate; anything else is a charge described by hand. */
  const pickCharge = (text: string) => {
    const service = services.find((s) => s.name.toLowerCase() === text.trim().toLowerCase())
    setCharge((c) => ({ ...c, description: service?.name ?? text, ipd_service_id: service ? String(service.id) : '', unit_price: service ? String(service.price) : c.unit_price }))
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

  // A day-care stay is charged the bed's day-care rate, where the bed has one.
  const dayCareRate = admission.is_day_care && admission.bed?.day_care_charge != null
  const bedRate = dayCareRate ? admission.bed!.day_care_charge! : (admission.bed?.daily_charge ?? 0)
  const addBedCharge = () =>
    setCharge({
      ipd_service_id: '',
      description: `Bed charges: ${admission.bed!.ward} bed ${admission.bed!.bed_no}${dayCareRate ? ' (day care)' : ''}`,
      charged_on: today(),
      quantity: String(stayDays(admission)),
      unit_price: String(bedRate),
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
              <PatientLink id={admission.patient.id}>{admission.patient.name}</PatientLink> <small className="muted mono">{admission.ipd_no}</small>
            </h1>
            <span className="muted">
              {admission.patient.uhid} · {admission.patient.age_label} / {admission.patient.gender.charAt(0).toUpperCase()} · {admission.patient.mobile}
            </span>
          </div>
          <span className={`pill ${open ? 'status-ongoing' : admission.status === 'discharged' ? 'ok' : 'off'}`}>{ADMISSION_STATUS[admission.status]}</span>
          <a className="btn ghost" href={`/ipd/${admission.id}/print`} target="_blank" rel="noreferrer">
            Print bill
          </a>
        </div>

        <dl className="case-facts">
          <div>
            <dt>Bed</dt>
            <dd>{admission.bed ? `${admission.bed.ward} · Bed ${admission.bed.bed_no}` : open ? 'Not assigned' : '—'}</dd>
          </div>
          <div>
            <dt>Doctor</dt>
            <dd>{admission.doctor.name}</dd>
          </div>
          <div>
            <dt>Stay</dt>
            <dd>
              {open ? `Day ${stayDays(admission)}` : `${stayDays(admission)} day${stayDays(admission) === 1 ? '' : 's'}`}{' '}
              <small className="muted">
                {open ? 'since' : ''} {longDate(admission.admitted_at)}, {clock(admission.admitted_at)}
                {admission.discharged_at ? ` to ${longDate(admission.discharged_at)}, ${clock(admission.discharged_at)}` : ''}
              </small>
            </dd>
          </div>
          {admission.reason && (
            <div>
              <dt>Reason</dt>
              <dd>{admission.reason}</dd>
            </div>
          )}
          {admission.payer && (
            <div>
              <dt>Bill to</dt>
              <dd>{admission.payer.name}</dd>
            </div>
          )}
          {(admission.attendant_name || admission.attendant_phone) && (
            <div>
              <dt>Attendant</dt>
              <dd>{[admission.attendant_name, admission.attendant_phone].filter(Boolean).join(' · ')}</dd>
            </div>
          )}
          <div className="money">
            <dt>{admission.due < 0 ? 'Advance left' : 'Due'}</dt>
            <dd className={admission.due > 0 ? 'due' : 'settled'}>{rupees(Math.abs(admission.due))}</dd>
          </div>
        </dl>

        <div className="tabs flush gap-bottom">
          <button className={tab === 'bill' ? 'active' : ''} onClick={() => canLeave() && setTab('bill')}>
            Bill
          </button>
          <button className={tab === 'notes' ? 'active' : ''} onClick={() => canLeave() && setTab('notes')}>
            Notes
          </button>
          {user?.can_consult && (
            <button className={tab === 'charts' ? 'active' : ''} onClick={() => canLeave() && setTab('charts')}>
              Charts
            </button>
          )}
          <button className={tab === 'consultants' ? 'active' : ''} onClick={() => canLeave() && setTab('consultants')} title="The consultants who came to see this patient">
            Consultants
          </button>
          <button className={tab === 'summary' ? 'active' : ''} onClick={() => canLeave() && setTab('summary')}>
            Discharge summary
          </button>
          <button className={tab === 'results' ? 'active' : ''} onClick={() => canLeave() && setTab('results')}>
            Investigations
          </button>
          <button className={tab === 'files' ? 'active' : ''} onClick={() => canLeave() && setTab('files')}>
            Papers
          </button>
          <button className={tab === 'forms' ? 'active' : ''} onClick={() => canLeave() && setTab('forms')}>
            Forms
          </button>
        </div>

        {tab === 'charts' && <IpdCharts admissionId={admission.id} userId={user?.id} canChart={Boolean(user?.can_consult)} canOrder={Boolean(user?.can_approve_ipd)} />}
        {tab === 'consultants' && <IpdConsultants admissionId={admission.id} canEdit={canEdit} minDate={admission.admitted_at.slice(0, 10)} maxDate={admission.discharged_at?.slice(0, 10)} />}
        {tab === 'results' && <IpdInvestigations admissionId={admission.id} />}
        {tab === 'forms' && <FormsPanel url={`/ipd/admissions/${admission.id}/forms`} use="ipd" canEdit={canEdit} />}
        {tab === 'files' && <AttachmentsPanel patient={{ id: admission.patient.id, name: admission.patient.name, uhid: admission.patient.uhid }} />}
        {tab === 'files' && (
          <>
            <h3>Certificates, letters and consent forms</h3>
            <PatientDocuments patient={admission.patient} canIssue={Boolean(user?.can_approve_ipd)} admissionId={admission.id} />
          </>
        )}

        {tab === 'notes' && (
          <>
            <details className="past">
              <summary>
                <strong>Health summary</strong> <span className="muted">conditions, regular medicines, allergies, past surgeries</span>
              </summary>
              <HealthSummary patientId={admission.patient.id} canEdit={Boolean(user?.can_consult)} />
            </details>
            <IpdNotes admissionId={admission.id} canEdit={canEdit} />
          </>
        )}
        {tab === 'summary' && <IpdSummary admissionId={admission.id} canEdit={canEdit} canApprove={Boolean(user?.can_approve_ipd)} dischargedOn={admission.discharged_at?.slice(0, 10)} />}
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
                    {c.source_id && (c.source_type === 'pharmacy' || c.source_type === 'bill') ? (
                      <a className="patient-link" href={c.source_type === 'pharmacy' ? `/pharmacy/bills/${c.source_id}/print` : `/bills/${c.source_id}/print`} target="_blank" rel="noreferrer" title="Open that bill (new tab)">
                        {c.description}
                      </a>
                    ) : (
                      c.description
                    )}
                    {c.medicines && c.medicines.length > 0 && (
                      <details className="charge-medicines">
                        <summary>
                          {c.medicines.length} {c.medicines.length === 1 ? 'medicine' : 'medicines'}
                        </summary>
                        <ul>
                          {c.medicines.map((m, i) => (
                            <li key={i}>
                              <span>
                                {m.name}
                                {m.returned > 0 && <small className="muted"> · {m.returned} returned</small>}
                              </span>
                              <span className="muted">
                                {m.quantity} × {rupees(m.rate)}
                              </span>
                              <span>{rupees(m.amount)}</span>
                            </li>
                          ))}
                        </ul>
                      </details>
                    )}
                    {c.remarks && <small className="muted block">{c.remarks}</small>}
                    {c.admission_bill_id && <small className="settled block">On bill {billNo[c.admission_bill_id] ?? ''}</small>}
                  </td>
                  <td>{c.category ?? '—'}</td>
                  <td className="num">{c.quantity}</td>
                  <td className="num">{rupees(c.unit_price)}</td>
                  <td className="num">{c.discount > 0 ? rupees(c.discount) : '—'}</td>
                  <td className="num">{rupees(c.amount)}</td>
                  <td>{c.creator?.name ?? (c.source_type === 'bed' ? 'Automatic' : '—')}</td>
                  <td className="num actions">
                    {!billLocked && (!c.source_type || c.source_type === 'bed') && !c.admission_bill_id && (
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
                    {/* A line for medicines taken back at the pharmacy leaves with the pharmacy bill's own line, not by itself. */}
                    {!billLocked && !c.admission_bill_id && c.source_type !== 'pharmacy_return' && (
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
            {(admission.charges?.length ?? 0) > 1 && (
              <tfoot>
                <tr className="static">
                  <td colSpan={6}>
                    <strong>Total of {admission.charges!.length} charges</strong>
                  </td>
                  <td className="num">
                    <strong>{rupees(admission.total)}</strong>
                  </td>
                  <td colSpan={2} />
                </tr>
              </tfoot>
            )}
          </table>
        </div>

        {!billLocked && (
        <>
        <form className="charge-row" onSubmit={addCharge}>
          <input className="span-2" list="ipd-services" placeholder="Service or charge: type to search" value={charge.description} disabled={Boolean(editingCharge)} onChange={(e) => pickCharge(e.target.value)} aria-label="Service or charge" />
          <datalist id="ipd-services">
            {services.map((s) => (
              <option key={s.id} value={s.name}>
                {[s.category, rupees(s.price)].filter(Boolean).join(' · ')}
              </option>
            ))}
          </datalist>
          <input type="date" value={charge.charged_on} max={today()} onChange={(e) => setCharge((c) => ({ ...c, charged_on: e.target.value }))} aria-label="Charge date" />
          <input type="number" min={0} step="0.5" placeholder="Qty" value={charge.quantity} onChange={(e) => setCharge((c) => ({ ...c, quantity: e.target.value }))} aria-label="Quantity" />
          <input type="number" min={0} step="0.01" placeholder="Rate ₹" value={charge.unit_price} onChange={(e) => setCharge((c) => ({ ...c, unit_price: e.target.value }))} aria-label="Rate" />
          <input type="number" min={0} step="0.01" placeholder="Disc. ₹" value={charge.discount} onChange={(e) => setCharge((c) => ({ ...c, discount: e.target.value }))} aria-label="Discount on this charge" />
          <input placeholder="Remarks (optional)" value={charge.remarks} onChange={(e) => setCharge((c) => ({ ...c, remarks: e.target.value }))} aria-label="Remarks" />
          <button className="btn primary" disabled={busy || !charge.description || charge.unit_price === ''}>
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
        {/* With automatic bed charges on for this patient, each day's line is already there; filling them in by hand would bill twice. */}
        {admission.bed && bedRate > 0 && !(admission.bed_charges_auto && admission.auto_bed_charge) && (
          <button type="button" className="link add-medicine" onClick={addBedCharge}>
            Fill in bed charges for {stayDays(admission)} day(s) at {rupees(bedRate)} a day{dayCareRate ? ' (day care)' : ''}
          </button>
        )}
        {templates.length > 0 && (
          <div className="row gap-top">
            <select
              className="narrow"
              value=""
              onChange={(e) => {
                const template = templates.find((t) => String(t.id) === e.target.value)
                // Dated like a single charge: by the charge date beside it, which is today unless an earlier day is chosen.
                const on = charge.charged_on && charge.charged_on < today() ? charge.charged_on : null
                if (template && window.confirm(`Add the ${template.items.length} service(s) in "${template.name}" to this bill${on ? `, dated ${longDate(on)}` : ''}?`))
                  run(api.post(`${url}/templates/${template.id}`, { charged_on: on }), `"${template.name}" added to the bill.`)
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
                  <button type="button" className="link" disabled={busy} onClick={() => run(api.post(`${url}/transfer`, { type: o.type, id: o.id }), `${o.label} was added to the IPD bill.`)}>
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
        <ul className="history payments-list">
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
              {canEdit && PAYMENT_MODES.some((m) => m.value === p.mode) && (
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
        {fixing && (
          <form
            className="action-box"
            onSubmit={async (e) => {
              e.preventDefault()
              const ok = await run(api.put(`${url}/payments/${fixing.id}`, { mode: fix.mode, reference: fix.reference.trim() || null, reason: fix.reason }), `Recorded as ${modeLabel(fix.mode)} now. The change is kept in the record with your reason.`)
              if (ok) setFixing(null)
            }}
          >
            <h3>Change how this was paid</h3>
            <p className="muted small">
              {PAYMENT_TYPES.find((t) => t.value === fixing.type)?.label} of {rupees(fixing.amount)}, recorded as <strong>{modeLabel(fixing.mode)}</strong>. Only the mode changes: the amount and the day it was taken stay as they are.
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
        {admission.due > 0 && Number(pay.amount || 0) !== admission.due && (
          <button type="button" className="link add-medicine" onClick={() => setPay((p) => ({ ...p, type: 'payment', amount: String(admission.due) }))}>
            Fill in the full due of {rupees(admission.due)}
          </button>
        )}
        {admission.due < 0 && !open && Number(pay.amount || 0) !== -admission.due && (
          <button type="button" className="link add-medicine" onClick={() => setPay((p) => ({ ...p, type: 'refund', amount: String(-admission.due) }))}>
            Fill in a refund of the {rupees(-admission.due)} advance left
          </button>
        )}
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
          {mayDateBack(user) && (
            <input type="date" value={pay.paid_on} max={today()} min={admission.admitted_at.slice(0, 10)} onChange={(e) => setPay((p) => ({ ...p, paid_on: e.target.value || today() }))} aria-label="Date received" title="Date the money was received" />
          )}
          <input placeholder="Remarks (optional)" value={pay.remarks} onChange={(e) => setPay((p) => ({ ...p, remarks: e.target.value }))} aria-label="Payment remarks" />
          <button className="btn primary" disabled={busy || !pay.amount || Number(pay.amount) <= 0}>
            {busy ? 'Saving…' : 'Record'}
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
          {open && canEdit && (
            <label className="check two-line">
              <input
                type="checkbox"
                checked={Boolean(admission.is_day_care)}
                disabled={busy || billLocked}
                onChange={(e) => run(api.put(url, { is_day_care: e.target.checked }), e.target.checked ? 'Marked as day care: the bed is charged at its day-care rate.' : 'No longer day care: the bed is charged at its usual rate.')}
              />
              <span>
                Day care
                <small className="muted block">
                  {admission.bed?.day_care_charge != null ? `This bed is ${rupees(admission.bed.day_care_charge)} a day for day care, ${rupees(admission.bed.daily_charge ?? 0)} otherwise.` : 'This bed has no separate day-care rate.'}
                </small>
              </span>
            </label>
          )}
          {open && admission.bed_charges_auto && (
            <label className="check two-line">
              <input
                type="checkbox"
                checked={admission.auto_bed_charge ?? true}
                disabled={busy || billLocked}
                onChange={(e) => run(api.put(url, { auto_bed_charge: e.target.checked }), e.target.checked ? 'The bed charge will be added each day from the next day of stay.' : 'No more bed charges will be added for this patient. Lines already on the bill stay.')}
              />
              <span>
                Add the bed charge to the bill each day
                <small className="muted block">Untick for a package, or when the bed is charged some other way.</small>
              </span>
            </label>
          )}
          {(admission.bed_stays?.length ?? 0) > 0 && (
            <>
              <h3 className="gap-top">Beds during this stay</h3>
              <ul className="history bed-history">
                {admission.bed_stays!.map((s) => (
                  <li key={s.id}>
                    <span>
                      <strong>
                        {s.ward} · Bed {s.bed_no}
                      </strong>
                      <small className="muted block">
                        {longDate(s.from_at)} {clock(s.from_at)} → {s.to_at ? `${longDate(s.to_at)} ${clock(s.to_at)}` : 'still there'}
                        {s.mover ? ` · by ${s.mover.name}` : ''}
                      </small>
                    </span>
                    <span className="muted">{stayLength(s.from_at, s.to_at)}</span>
                  </li>
                ))}
              </ul>
            </>
          )}
        </div>

        <div className="card">
          <h2>{open ? 'Discharge' : 'Discharged'}</h2>
          {open ? (
            <form
              onSubmit={(e) => {
                e.preventDefault()
                // One press should not end a stay, least of all under the wrong outcome.
                const outcome = CLOSE_OPTIONS.find((o) => o.value === closing.status)?.label ?? closing.status
                if (!window.confirm(`Close this case as "${outcome}"?\n\nThe bed is freed and the patient leaves the list of inpatients.`)) return
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
                <button type="button" className="link" onClick={() => canLeave() && setTab('summary')}>
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
                {canEdit && !billLocked && (
                  <>
                    {' · '}
                    <button type="button" className="link" onClick={() => setLeftAt({ at: admission.discharged_at!.replace(' ', 'T'), reason: '' })}>
                      Correct the time
                    </button>
                  </>
                )}
              </p>
              {canEdit && billLocked && <p className="muted small">The bill is approved, so the discharge time is fixed. Withdraw the approval to correct it.</p>}
              {leftAt && (
                <Popup
                  title="Correct the discharge time"
                  sub={`IPD ${admission.ipd_no} · admitted ${longDate(admission.admitted_at)} ${clock(admission.admitted_at)}`}
                  size="slim"
                  onClose={() => setLeftAt(null)}
                  onSubmit={async (e) => {
                    e.preventDefault()
                    if (await run(api.post(`${url}/discharge-time`, { discharged_at: leftAt.at.replace('T', ' '), reason: leftAt.reason }), 'Discharge time corrected. The bed and the daily bed charges follow the new time.')) setLeftAt(null)
                  }}
                  foot={
                    <>
                      <button type="button" className="btn ghost" onClick={() => setLeftAt(null)}>
                        Cancel
                      </button>
                      <button className="btn primary" disabled={busy || !leftAt.at || leftAt.reason.trim().length < 3}>
                        Save the correct time
                      </button>
                    </>
                  }
                >
                  <p className="muted small">
                    Now recorded: {longDate(admission.discharged_at!)} {clock(admission.discharged_at!)}. The bed is given up at the new time; if the stay is now shorter or longer, the automatic daily bed charges are taken off or added to match. The
                    change and its reason are kept in the record.
                  </p>
                  <TextField label="Correct date and time" required type="datetime-local" min={admission.admitted_at.replace(' ', 'T')} max={nowLocal()} value={leftAt.at} onChange={(v) => setLeftAt((f) => f && { ...f, at: v })} />
                  <TextField label="Why it is being corrected" required value={leftAt.reason} onChange={(v) => setLeftAt((f) => f && { ...f, reason: v })} placeholder="Typed wrongly at discharge" />
                </Popup>
              )}
              <button type="button" className="btn ghost" onClick={() => canLeave() && setTab('summary')}>
                Open the discharge summary
              </button>
              {user?.can_approve_ipd && (
                <form
                  className="gap-top"
                  onSubmit={async (e) => {
                    e.preventDefault()
                    if (await run(api.post(`${url}/reopen`, { reason: reopening }), 'Case reopened. Check the bed, and close it again when the patient leaves.')) setReopening('')
                  }}
                >
                  <p className="muted small">Closed by mistake, or under the wrong outcome? Reopen it and close it again correctly. The reason is kept in the record.</p>
                  <input placeholder="Reason for reopening (required)" value={reopening} onChange={(e) => setReopening(e.target.value)} aria-label="Reason for reopening" />
                  <div className="panel-foot">
                    <button className="btn ghost" disabled={busy || reopening.trim().length < 3}>
                      Reopen case
                    </button>
                  </div>
                </form>
              )}
            </>
          )}
        </div>
      </aside>
      {/* Whatever tab or part of the page the person is on, a refusal is shown where it cannot be missed. */}
      {error && (
        <button type="button" className="form-error floating-alert" role="alert" onClick={() => setError('')} title="Dismiss">
          {error}
        </button>
      )}
    </div>
  )
}
