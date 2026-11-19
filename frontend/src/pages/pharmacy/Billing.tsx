import { useEffect, useRef, useState, type FormEvent, type KeyboardEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api, fieldErrors } from '../../api'
import { useUnsaved } from '../../unsaved'
import { useAuth } from '../../auth'
import { SelectField, TextField } from '../../components/Field'
import { MedicinePick } from '../../components/MedicinePick'
import { PatientPicker } from '../../components/PatientPicker'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { PAYMENT_MODES, clock, daysUntil, expiryLabel, longDate, rupees } from '../../format'
import type { Patient, PharmacyInpatient, PharmacySale, PrescriptionForPharmacy, SellableMedicine } from '../../types'
import { PatientLink } from '../../components/PatientLink'

type Line = {
  batchId: number
  medicine: string
  batchNo: string
  expiry: string
  unitPrice: number
  available: number
  quantity: string
  discount: string
}

const lineAmount = (l: Line) => Math.round(Number(l.quantity || 0) * l.unitPrice * (1 - Number(l.discount || 0) / 100) * 100) / 100

export function PharmacyBilling() {
  const { user } = useAuth()
  const canSell = Boolean(user?.can_run_pharmacy)
  const [walkIn, setWalkIn] = useState(true)
  const [patient, setPatient] = useState<Patient | null>(null)
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const [doctor, setDoctor] = useState('')
  const [lines, setLines] = useState<Line[]>([])
  const [discount, setDiscount] = useState('')
  const [paid, setPaid] = useState('')
  const [paidTouched, setPaidTouched] = useState(false)
  const [mode, setMode] = useState('cash')
  const [reference, setReference] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const [done, setDone] = useState<PharmacySale | null>(null)
  // The counter is worked from the keyboard: which match is highlighted, and which line's quantity to jump to next.
  const jumpTo = useRef<number | null>(null)
  const [given, setGiven] = useState('')
  const searchBox = useRef<HTMLInputElement>(null)
  const quantityBoxes = useRef<Record<number, HTMLInputElement | null>>({})
  const [prescriptions, setPrescriptions] = useState<PrescriptionForPharmacy[]>([])
  // Today's bills, newest first, shown under the counter so an earlier bill can be reopened to add medicines.
  const [todays, setTodays] = useState<PharmacySale[] | null>(null)
  useEffect(() => {
    api
      .get<PharmacySale[]>('/pharmacy/sales')
      .then((res) => setTodays([...res.data].sort((a, b) => b.id - a.id)))
      .catch(() => setTodays([]))
  }, [done])

  // The patients admitted now. A bill for one of them can go on the IPD bill instead of being paid at the counter.
  const [inpatients, setInpatients] = useState<PharmacyInpatient[]>([])
  const [fromWard, setFromWard] = useState(false)
  const [onIpd, setOnIpd] = useState(true)
  useEffect(() => {
    api.get<PharmacyInpatient[]>('/pharmacy/inpatients').then((res) => setInpatients(res.data)).catch(() => undefined)
  }, [done])

  // Choosing a registered patient brings up what the doctor prescribed recently.
  const choosePatient = (next: Patient | null) => {
    setPatient(next)
    setOnIpd(true)
    setPrescriptions([])
    if (next) {
      api.get<PrescriptionForPharmacy[]>('/pharmacy/prescriptions', { params: { patient_id: next.id } }).then((res) => {
        setPrescriptions(res.data)
        if (res.data[0]?.doctor) setDoctor((current) => current || res.data[0].doctor!)
      })
    }
  }


  // Once a newly added line is on the screen, the cursor goes to its quantity, with the 1 selected so a number replaces it.
  useEffect(() => {
    if (jumpTo.current === null) return
    const box = quantityBoxes.current[jumpTo.current]
    jumpTo.current = null
    box?.focus()
    box?.select()
  }, [lines])

  // Ctrl+S (or ⌘S) saves the bill from wherever the cursor is.
  const saveKey = useRef<() => void>(() => {})
  useEffect(() => {
    searchBox.current?.focus()
    const onKey = (e: globalThis.KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault()
        saveKey.current()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  const [params, setParams] = useSearchParams()
  const presetPatient = params.get('patient')
  useEffect(() => {
    if (!presetPatient) return
    api.get<Patient>(`/patients/${presetPatient}`).then((res) => {
      setWalkIn(false)
      setPatient(res.data)
      api.get<PrescriptionForPharmacy[]>('/pharmacy/prescriptions', { params: { patient_id: res.data.id } }).then((rx) => {
        setPrescriptions(rx.data)
        if (rx.data[0]?.doctor) setDoctor(rx.data[0].doctor)
      })
      setParams({}, { replace: true })
    })
  }, [presetPatient, setParams])

  // A bill with medicines on it that has not been saved: leaving the counter asks first.
  useUnsaved(lines.length > 0 && !saving, 'This pharmacy bill is not saved yet. Leave without saving it?')
  // The chosen patient's IPD case, if they are admitted, and whether this bill is going onto it.
  const admitted = !walkIn && patient ? inpatients.find((a) => a.patient.id === patient.id) : undefined
  const toIpd = Boolean(admitted) && !admitted!.bill_approved && onIpd
  const subtotal = lines.reduce((sum, l) => sum + lineAmount(l), 0)
  const total = Math.max(0, Math.round(subtotal - Number(discount || 0)))
  // Until the cashier types an amount, assume the customer pays in full.
  const paidValue = paidTouched ? paid : String(total)

  const add = (m: SellableMedicine, batch: SellableMedicine['sellable_batches'][number]) => {
    setDone(null)
    setError('')
    setLines((rows) =>
      rows.some((r) => r.batchId === batch.id)
        ? rows
        : [...rows, { batchId: batch.id, medicine: m.name, batchNo: batch.batch_no, expiry: batch.expiry, unitPrice: batch.unit_mrp, available: batch.units_in_stock, quantity: '1', discount: '' }],
    )
    // Straight to the quantity of that line: at once if it is on the bill already, otherwise as soon as it appears.
    const there = quantityBoxes.current[batch.id]
    if (there && lines.some((r) => r.batchId === batch.id)) {
      there.focus()
      there.select()
    } else {
      jumpTo.current = batch.id
    }
  }

  /** Enter in a quantity or discount box goes back to the search box for the next medicine, instead of saving. */
  const backToSearch = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      e.preventDefault()
      searchBox.current?.focus()
    }
  }

  const setLine = (batchId: number, key: 'quantity' | 'discount', value: string) =>
    setLines((rows) => rows.map((r) => (r.batchId === batchId ? { ...r, [key]: value } : r)))

  // Reprints the last bill made today, for the customer who comes back to the counter for a copy.
  const printPrevious = async () => {
    setError('')
    const res = await api.get<PharmacySale[]>('/pharmacy/sales')
    const last = [...res.data].sort((a, b) => b.id - a.id)[0]
    if (last) window.open(`/pharmacy/bills/${last.id}/print`, '_blank')
    else setError('No pharmacy bill has been made today yet. Older bills can be printed from All bills.')
  }

  const reset = () => {
    setLines([])
    setPatient(null)
    setPrescriptions([])
    setName('')
    setPhone('')
    setDoctor('')
    setDiscount('')
    setPaid('')
    setPaidTouched(false)
    setReference('')
    setError('')
    setGiven('')
    searchBox.current?.focus()
  }

  const save = async (e?: FormEvent, print = false) => {
    e?.preventDefault()
    const customer = walkIn ? name.trim() : patient?.name
    if (!customer) {
      setError(walkIn ? 'Enter the customer name.' : fromWard ? 'Choose the inpatient.' : 'Choose a patient.')
      return
    }
    setSaving(true)
    setError('')
    // The print page is opened at the click itself; a window opened after the wait would be blocked as a pop-up.
    const printTab = print ? window.open('', '_blank') : null
    try {
      const res = await api.post<PharmacySale>('/pharmacy/sales', {
        patient_id: walkIn ? null : patient?.id,
        customer_name: customer,
        customer_phone: (walkIn ? phone : patient?.mobile) || null,
        referred_doctor: doctor || null,
        discount: discount || 0,
        // An inpatient's medicines go on the IPD bill: nothing is taken at the counter.
        paid: toIpd ? 0 : paidValue || 0,
        ...(toIpd ? { admission_id: admitted!.admission_id } : {}),
        payment_mode: mode,
        reference: reference || null,
        items: lines.map((l) => ({ stock_batch_id: l.batchId, quantity: l.quantity || 0, discount_percent: l.discount || 0 })),
      })
      reset()
      setDone(res.data)
      if (printTab) printTab.location.href = `/pharmacy/bills/${res.data.id}/print`
    } catch (err) {
      printTab?.close()
      setError(Object.values(fieldErrors(err))[0])
    } finally {
      setSaving(false)
    }
  }

  saveKey.current = () => {
    if (!saving && lines.length > 0 && canSell) void save()
  }
  const change = mode === 'cash' && Number(given) > 0 ? Math.round((Number(given) - Number(paidValue || 0)) * 100) / 100 : 0

  return (
    <>
      <PharmacyTabs />
      <form className="split" onSubmit={save} noValidate>
        <section className="card">
          <div className="toolbar">
            <h1>Pharmacy billing</h1>
            <div className="tabs flush">
              <button
                type="button"
                className={walkIn ? 'active' : ''}
                onClick={() => {
                  setWalkIn(true)
                  setFromWard(false)
                }}
              >
                Walk-in
              </button>
              <button
                type="button"
                className={!walkIn && !fromWard ? 'active' : ''}
                onClick={() => {
                  setWalkIn(false)
                  setFromWard(false)
                }}
              >
                Registered patient
              </button>
              <button
                type="button"
                className={!walkIn && fromWard ? 'active' : ''}
                onClick={() => {
                  setWalkIn(false)
                  setFromWard(true)
                  if (patient && !inpatients.some((a) => a.patient.id === patient.id)) choosePatient(null)
                }}
              >
                Inpatient{inpatients.length > 0 && <span className="count">{inpatients.length}</span>}
              </button>
            </div>
          </div>

          {done && (
            <p className="notice">
              Bill {done.bill_no} saved for {done.customer_name}: {rupees(done.total)}
              {done.ipd_transfer > 0 ? `, ${rupees(done.ipd_transfer)} put on the IPD bill` : done.due > 0 ? `, ${rupees(done.due)} due` : ', paid'}.{' '}
              <a href={`/pharmacy/bills/${done.id}/print`} target="_blank" rel="noreferrer">
                Print bill
              </a>
            </p>
          )}

          {walkIn ? (
            <div className="grid three">
              <TextField label="Customer name" required value={name} onChange={setName} />
              <TextField label="Phone" inputMode="tel" value={phone} onChange={setPhone} />
              <TextField label="Referred doctor" value={doctor} onChange={setDoctor} />
            </div>
          ) : (
            <div className="grid">
              <div>
                {fromWard ? (
                  <label className="field">
                    <span className="field-label">
                      Inpatient <span className="req">*</span>
                    </span>
                    <select
                      value={admitted ? String(admitted.admission_id) : ''}
                      onChange={(e) => {
                        const chosen = inpatients.find((a) => String(a.admission_id) === e.target.value)
                        choosePatient(chosen?.patient ?? null)
                        if (chosen?.doctor) setDoctor(chosen.doctor)
                      }}
                      autoFocus
                    >
                      <option value="">{inpatients.length ? 'Choose the patient' : 'Nobody is admitted just now'}</option>
                      {inpatients.map((a) => (
                        <option key={a.admission_id} value={a.admission_id}>
                          {a.bed ? `${a.bed} · ` : ''}
                          {a.patient.name} · {a.ipd_no}
                        </option>
                      ))}
                    </select>
                  </label>
                ) : (
                  <PatientPicker patient={patient} onChange={choosePatient} />
                )}
              </div>
              <TextField label="Referred doctor" value={doctor} onChange={setDoctor} />
            </div>
          )}

          {admitted && (
            <div className={`ipd-choice ${toIpd ? 'on' : ''}`}>
              <label className="check two-line">
                <input type="checkbox" checked={toIpd} disabled={admitted.bill_approved} onChange={(e) => setOnIpd(e.target.checked)} />
                <span>
                  Put this bill on the IPD bill
                  <small className="muted block">
                    {admitted.patient.name} is admitted: {admitted.ipd_no}
                    {admitted.bed ? ` · ${admitted.bed}` : ''}
                    {admitted.doctor ? ` · ${admitted.doctor}` : ''}.{' '}
                    {admitted.bill_approved
                      ? 'That IPD bill has been approved, so nothing more can go on it: take the money here.'
                      : toIpd
                        ? 'Nothing is collected at the counter; the medicines are listed on the IPD bill.'
                        : 'Unticked: the money is taken here at the counter, as for any other bill.'}
                  </small>
                </span>
              </label>
            </div>
          )}

          {!walkIn &&
            prescriptions.map((rx) => {
              const available = rx.medicines.filter((m) => m.match?.sellable_batches.length)
              return (
                <div className="rx-card" key={rx.id}>
                  <div className="row">
                    <strong className="grow">
                      Prescription · {longDate(rx.completed_at)}
                      {rx.doctor ? ` · ${rx.doctor}` : ''}
                    </strong>
                    <button type="button" className="btn ghost" disabled={available.length === 0} onClick={() => available.forEach((m) => add(m.match!, m.match!.sellable_batches[0]))}>
                      Add all in stock ({available.length})
                    </button>
                  </div>
                  <ul>
                    {rx.medicines.map((m, i) => (
                      <li key={i}>
                        <span className="grow">
                          {m.medicine}
                          {m.directions && <small className="muted"> · {m.directions}</small>}
                        </span>
                        {m.match?.sellable_batches.length ? (
                          <button type="button" className="link" onClick={() => add(m.match!, m.match!.sellable_batches[0])}>
                            Add
                          </button>
                        ) : (
                          <small className="due">Not in stock under this name</small>
                        )}
                      </li>
                    ))}
                  </ul>
                </div>
              )
            })}

          <h3>Medicines</h3>
          <MedicinePick inputRef={searchBox} onPick={add} hint="↑ ↓ to choose · Enter to add · type the quantity · Enter for the next medicine · Ctrl+S to save" />

          <div className="table-wrap gap-top">
            <table className="compact">
              <thead>
                <tr>
                  <th>Medicine</th>
                  <th>Batch</th>
                  <th>Expiry</th>
                  <th className="num">Price</th>
                  <th className="num">Qty</th>
                  <th className="num">Disc %</th>
                  <th className="num">Amount</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {lines.map((l) => (
                  <tr key={l.batchId} className="static">
                    <td>
                      <strong>{l.medicine}</strong>
                    </td>
                    <td>{l.batchNo}</td>
                    <td>
                      {expiryLabel(l.expiry)}
                      {daysUntil(l.expiry) <= 90 && <small className="soon block">expires soon</small>}
                    </td>
                    <td className="num">{rupees(l.unitPrice)}</td>
                    <td className="num">
                      <input
                        className="qty"
                        type="number"
                        min={1}
                        max={l.available}
                        value={l.quantity}
                        ref={(box) => {
                          quantityBoxes.current[l.batchId] = box
                        }}
                        onKeyDown={backToSearch}
                        onChange={(e) => setLine(l.batchId, 'quantity', e.target.value)}
                        aria-label={`Quantity of ${l.medicine}`}
                        aria-invalid={Number(l.quantity) > l.available}
                      />
                      <small className={`block ${Number(l.quantity) > l.available ? 'due' : 'muted'}`}>of {l.available}</small>
                    </td>
                    <td className="num">
                      <input className="qty" type="number" min={0} max={100} value={l.discount} onKeyDown={backToSearch} onChange={(e) => setLine(l.batchId, 'discount', e.target.value)} aria-label={`Discount for ${l.medicine}`} />
                    </td>
                    <td className="num">{rupees(lineAmount(l))}</td>
                    <td className="num">
                      <button type="button" className="link danger" onClick={() => setLines((rows) => rows.filter((r) => r.batchId !== l.batchId))}>
                        Remove
                      </button>
                    </td>
                  </tr>
                ))}
                {lines.length === 0 && (
                  <tr className="empty">
                    <td colSpan={8}>Type a medicine name above to start the bill.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </section>

        <aside className="card panel narrow">
          <h2>Bill summary</h2>
          <dl className="totals">
            <dt>Items</dt>
            <dd>{lines.length}</dd>
            <dt>Amount</dt>
            <dd>{rupees(Math.round(subtotal * 100) / 100)}</dd>
            <dt>Discount (₹)</dt>
            <dd>
              <input className="qty" type="number" min={0} step="0.01" value={discount} onChange={(e) => setDiscount(e.target.value)} aria-label="Bill discount" />
            </dd>
            <dt className="strong">Bill amount</dt>
            <dd className="strong bill-total">{rupees(total)}</dd>
          </dl>
          <p className="muted small">Prices include GST. The bill is rounded to the nearest rupee.</p>

          {toIpd && (
            <div className="tile gap-top ipd-tile">
              <span>Goes on IPD bill {admitted!.ipd_no}</span>
              <strong>{rupees(total)}</strong>
            </div>
          )}
          <div className={toIpd ? 'hidden' : 'grid gap-top'}>
            <TextField
              label="Paid (₹)"
              type="number"
              min={0}
              step="0.01"
              value={paidValue}
              onChange={(v) => {
                setPaid(v)
                setPaidTouched(true)
              }}
            />
            <SelectField label="Mode" value={mode} onChange={setMode} options={PAYMENT_MODES} />
            {mode === 'cash' ? (
              <>
                <TextField label="Cash given (optional)" type="number" min={0} step="1" value={given} onChange={setGiven} />
                <div className="field">
                  <span className="field-label">To return</span>
                  <strong className={`change ${change > 0 ? 'has' : ''}`}>{change > 0 ? rupees(change) : '—'}</strong>
                </div>
              </>
            ) : (
              <div className="span-2">
                <TextField label="Reference (optional)" value={reference} onChange={setReference} />
              </div>
            )}
          </div>

          {error && <p className="form-error">{error}</p>}
          {!canSell && <p className="muted gap-top">Only pharmacy staff can save bills.</p>}
          <div className="bill-actions">
            <button className="btn ghost" disabled={saving || lines.length === 0 || !canSell} title="Ctrl + S">
              {saving ? 'Saving…' : 'Save'}
            </button>
            <button type="button" className="btn primary" disabled={saving || lines.length === 0 || !canSell} onClick={() => save(undefined, true)}>
              Save and print
            </button>
          </div>
          <div className="bill-links">
            <button type="button" className="link" onClick={() => (lines.length === 0 || window.confirm('Clear this bill? The medicines on it will be taken off.')) && reset()}>
              Clear bill
            </button>
            <button type="button" className="link" onClick={printPrevious}>
              Print previous bill
            </button>
          </div>
        </aside>
      </form>

      <section className="card gap-top todays-bills">
        <div className="toolbar">
          <h2>Today's bills</h2>
          {todays && todays.length > 0 && (
            <span className="muted">
              {todays.filter((s) => !s.voided_at).length === 1 ? '1 bill' : `${todays.filter((s) => !s.voided_at).length} bills`} · {rupees(todays.filter((s) => !s.voided_at).reduce((sum, s) => sum + s.total - s.returned, 0))}
            </span>
          )}
          <Link className="link" to="/pharmacy/bills">
            All bills and earlier days
          </Link>
        </div>
        <div className="table-wrap">
          <table className="compact">
            <thead>
              <tr>
                <th>Bill</th>
                <th>Customer</th>
                <th className="num">Amount</th>
                <th className="num">Balance</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {todays?.map((s) => (
                <tr key={s.id} className={`static ${s.voided_at ? 'faded' : ''}`}>
                  <td>
                    <span className="mono">{s.bill_no}</span>
                    <small className="muted block">{clock(s.sold_at)}</small>
                  </td>
                  <td>
                    <PatientLink id={s.patient_id} bills>
                      {s.customer_name}
                    </PatientLink>
                    {s.customer_phone && <small className="muted block">{s.customer_phone}</small>}
                  </td>
                  <td className="num">{rupees(s.total)}</td>
                  <td className={`num ${s.due > 0 ? 'due strong' : ''}`}>{s.voided_at ? <span className="pill off">Deleted</span> : rupees(s.due)}</td>
                  <td className="num actions">
                    {canSell && !s.voided_at && s.returned === 0 && s.ipd_transfer === 0 && (
                      <Link className="link" to={`/pharmacy/bills?add=${s.id}`}>
                        Add medicines
                      </Link>
                    )}
                    <a className="link" href={`/pharmacy/bills/${s.id}/print`} target="_blank" rel="noreferrer">
                      Print
                    </a>
                  </td>
                </tr>
              ))}
              {todays && todays.length === 0 && (
                <tr className="empty">
                  <td colSpan={5}>No pharmacy bill has been made today yet.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
    </>
  )
}
