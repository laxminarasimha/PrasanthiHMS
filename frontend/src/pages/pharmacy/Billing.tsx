import { useEffect, useState, type FormEvent } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api, fieldErrors } from '../../api'
import { useAuth } from '../../auth'
import { SelectField, TextField } from '../../components/Field'
import { PatientPicker } from '../../components/PatientPicker'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { PAYMENT_MODES, expiryLabel, longDate, rupees } from '../../format'
import type { Patient, PharmacySale, PrescriptionForPharmacy, SellableMedicine } from '../../types'

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
  const [search, setSearch] = useState('')
  const [matches, setMatches] = useState<SellableMedicine[]>([])
  const [lines, setLines] = useState<Line[]>([])
  const [discount, setDiscount] = useState('')
  const [paid, setPaid] = useState('')
  const [paidTouched, setPaidTouched] = useState(false)
  const [mode, setMode] = useState('cash')
  const [reference, setReference] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const [done, setDone] = useState<PharmacySale | null>(null)
  const [prescriptions, setPrescriptions] = useState<PrescriptionForPharmacy[]>([])

  // Choosing a registered patient brings up what the doctor prescribed recently.
  const choosePatient = (next: Patient | null) => {
    setPatient(next)
    setPrescriptions([])
    if (next) {
      api.get<PrescriptionForPharmacy[]>('/pharmacy/prescriptions', { params: { patient_id: next.id } }).then((res) => {
        setPrescriptions(res.data)
        if (res.data[0]?.doctor) setDoctor((current) => current || res.data[0].doctor!)
      })
    }
  }

  useEffect(() => {
    if (search.trim().length < 2) return
    const timer = setTimeout(() => {
      api.get<SellableMedicine[]>('/pharmacy/sell-search', { params: { q: search.trim() } }).then((res) => setMatches(res.data))
    }, 200)
    return () => clearTimeout(timer)
  }, [search])

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

  const shown = search.trim().length >= 2 ? matches : []
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
    setSearch('')
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
  }

  const save = async (e: FormEvent) => {
    e.preventDefault()
    const customer = walkIn ? name.trim() : patient?.name
    if (!customer) {
      setError(walkIn ? 'Enter the customer name.' : 'Choose a patient.')
      return
    }
    setSaving(true)
    setError('')
    try {
      const res = await api.post<PharmacySale>('/pharmacy/sales', {
        patient_id: walkIn ? null : patient?.id,
        customer_name: customer,
        customer_phone: (walkIn ? phone : patient?.mobile) || null,
        referred_doctor: doctor || null,
        discount: discount || 0,
        paid: paidValue || 0,
        payment_mode: mode,
        reference: reference || null,
        items: lines.map((l) => ({ stock_batch_id: l.batchId, quantity: l.quantity || 0, discount_percent: l.discount || 0 })),
      })
      reset()
      setDone(res.data)
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    } finally {
      setSaving(false)
    }
  }

  return (
    <>
      <PharmacyTabs />
      <form className="split" onSubmit={save} noValidate>
        <section className="card">
          <div className="toolbar">
            <h1>Pharmacy billing</h1>
            <div className="tabs flush">
              <button type="button" className={walkIn ? 'active' : ''} onClick={() => setWalkIn(true)}>
                Walk-in
              </button>
              <button type="button" className={!walkIn ? 'active' : ''} onClick={() => setWalkIn(false)}>
                Registered patient
              </button>
            </div>
          </div>

          {done && (
            <p className="notice">
              Bill {done.bill_no} saved for {done.customer_name}: {rupees(done.total)}
              {done.due > 0 ? `, ${rupees(done.due)} due` : ', paid'}.{' '}
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
                <PatientPicker patient={patient} onChange={choosePatient} />
              </div>
              <TextField label="Referred doctor" value={doctor} onChange={setDoctor} />
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
          <div className="medicine-search">
            <input type="search" placeholder="Type a medicine name to add it" value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Search medicine" />
            {shown.length > 0 && (
              <ul className="picker floating">
                {shown.flatMap((m) =>
                  m.sellable_batches.map((b, i) => (
                    <li key={b.id}>
                      <button type="button" onClick={() => add(m, b)}>
                        <strong>{m.name}</strong>
                        <small className="muted">
                          Batch {b.batch_no} · exp {expiryLabel(b.expiry)} · {b.units_in_stock} in stock · {rupees(b.unit_mrp)} each
                          {i === 0 && m.sellable_batches.length > 1 ? ' · sell this first' : ''}
                        </small>
                      </button>
                    </li>
                  )),
                )}
              </ul>
            )}
            {search.trim().length >= 2 && shown.length === 0 && <small className="muted">No medicine in stock matches "{search.trim()}".</small>}
          </div>

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
                    <td>{expiryLabel(l.expiry)}</td>
                    <td className="num">{rupees(l.unitPrice)}</td>
                    <td className="num">
                      <input
                        className="qty"
                        type="number"
                        min={1}
                        max={l.available}
                        value={l.quantity}
                        onChange={(e) => setLine(l.batchId, 'quantity', e.target.value)}
                        aria-label={`Quantity of ${l.medicine}`}
                        aria-invalid={Number(l.quantity) > l.available}
                      />
                      <small className={`block ${Number(l.quantity) > l.available ? 'due' : 'muted'}`}>of {l.available}</small>
                    </td>
                    <td className="num">
                      <input className="qty" type="number" min={0} max={100} value={l.discount} onChange={(e) => setLine(l.batchId, 'discount', e.target.value)} aria-label={`Discount for ${l.medicine}`} />
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
                    <td colSpan={8}>Search for a medicine above to start the bill.</td>
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
            <dd className="strong">{rupees(total)}</dd>
          </dl>
          <p className="muted small">Prices include GST. The bill is rounded to the nearest rupee.</p>

          <div className="grid gap-top">
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
            <div className="span-2">
              <TextField label="Reference (optional)" value={reference} onChange={setReference} />
            </div>
          </div>

          {error && <p className="form-error">{error}</p>}
          {!canSell && <p className="muted gap-top">Only pharmacy staff can save bills.</p>}
          <div className="panel-foot between">
            <span className="row">
              <button type="button" className="link" onClick={reset}>
                Clear bill
              </button>
              <button type="button" className="link" onClick={printPrevious}>
                Print previous bill
              </button>
            </span>
            <button className="btn primary" disabled={saving || lines.length === 0 || !canSell}>
              {saving ? 'Saving…' : 'Save bill'}
            </button>
          </div>
        </aside>
      </form>
    </>
  )
}
