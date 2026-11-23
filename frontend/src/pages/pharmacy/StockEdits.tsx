import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors } from '../../api'
import { useAuth } from '../../auth'
import { MedicineChoose } from '../../components/MedicineChoose'
import { NewMedicine } from '../../components/NewMedicine'
import { Popup } from '../../components/Popup'
import { expiryLabel, rupees } from '../../format'
import type { Medicine, StockBatch } from '../../types'
import { REASONS } from './auditTypes'

/** The explanations that fit when the shelf holds more than the records say, and when it holds fewer. */
const WHEN_MORE = ['found', 'miscount', 'mixup']
const WHEN_FEWER = ['damaged', 'expired', 'unbilled', 'mixup', 'miscount', 'missing']

const about = (b: StockBatch) => `${b.medicine?.name ?? ''} · batch ${b.batch_no} · expires ${expiryLabel(b.expiry)}`
const two = (n: number) => Math.round(n * 100) / 100

/**
 * One number to type: how many units of this batch are on the shelf. The difference from the records is worked out
 * and shown, and one tap says what kind of difference it is.
 */
export function QuantityPopup({ batch, onClose, onDone }: { batch: StockBatch; onClose: () => void; onDone: (message: string) => void }) {
  const { user } = useAuth()
  const direct = Boolean(user?.can_audit_pharmacy)
  const [count, setCount] = useState(String(batch.units_in_stock))
  const [picked, setPicked] = useState('')
  const [note, setNote] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  const typed = /^\d+$/.test(count.trim()) ? Number(count) : null
  const difference = typed === null ? null : typed - batch.units_in_stock
  const fitting = !difference ? [] : difference > 0 ? WHEN_MORE : WHEN_FEWER
  const choices = fitting.map((key) => REASONS.find(([k]) => k === key)).filter((r): r is [string, string] => Boolean(r))
  // Extra stock is nearly always "found", so that is chosen unless the person says otherwise. A shortage has to be explained.
  const category = fitting.includes(picked) ? picked : difference && difference > 0 ? 'found' : ''
  const ready = typed !== null && Boolean(difference) && category !== '' && !saving
  const step = (by: number) => setCount(String(Math.max(0, (typed ?? batch.units_in_stock) + by)))

  const save = async (e: FormEvent) => {
    e.preventDefault()
    if (!ready) return
    setSaving(true)
    setError('')
    try {
      const res = await api.post<{ applied: boolean; count_no?: string }>(`/pharmacy/stock/${batch.id}/adjust`, { units_in_stock: typed, category, reason: note.trim() || null })
      onDone(
        res.data.applied
          ? `${batch.medicine?.name} batch ${batch.batch_no} is now ${typed} units.`
          : `Sent for approval as ${res.data.count_no}: ${batch.medicine?.name} batch ${batch.batch_no} counted as ${typed}. The stock changes when it is approved under Stock audit.`,
      )
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'Could not save. Please try again.')
      setSaving(false)
    }
  }

  return (
    <Popup
      title="Change the quantity"
      sub={about(batch)}
      size="slim"
      onClose={onClose}
      onSubmit={save}
      foot={
        <>
          <button type="button" className="btn ghost" onClick={onClose}>
            Cancel
          </button>
          <button className="btn primary" disabled={!ready}>
            {saving ? 'Saving…' : direct ? 'Save quantity' : 'Send for approval'}
          </button>
        </>
      }
    >
      <span className="field-label" id="count-label">
        Units on the shelf now
      </span>
      <div className="count-step">
        <button type="button" className="btn ghost" onClick={() => step(-1)} aria-label="One less">
          −
        </button>
        <input type="number" inputMode="numeric" min={0} step={1} value={count} onChange={(e) => setCount(e.target.value)} onFocus={(e) => e.target.select()} aria-labelledby="count-label" />
        <button type="button" className="btn ghost" onClick={() => step(1)} aria-label="One more">
          +
        </button>
        <span className="muted">
          The records say <strong>{batch.units_in_stock}</strong>
        </span>
      </div>
      {difference !== null && difference !== 0 && (
        <p className={`count-diff ${difference > 0 ? 'more' : 'less'}`}>
          {Math.abs(difference)} {difference > 0 ? 'more' : 'fewer'} than the records
          {batch.unit_mrp > 0 && <span className="muted"> · {rupees(two(Math.abs(difference) * batch.unit_mrp))} at MRP</span>}
        </p>
      )}
      {choices.length > 0 && (
        <>
          <span className="field-label gap-top block">Why is it different?</span>
          <div className="chips count-why" role="radiogroup" aria-label="Why is it different?">
            {choices.map(([key, label]) => (
              <button type="button" key={key} role="radio" aria-checked={category === key} className={`chip ${category === key ? 'on' : ''}`} onClick={() => setPicked(key)}>
                {label}
              </button>
            ))}
          </div>
          <label className="field gap-top">
            <span className="field-label">Note (if there is something to add)</span>
            <input maxLength={255} placeholder="For example: two strips crushed at the back of the rack" value={note} onChange={(e) => setNote(e.target.value)} />
          </label>
        </>
      )}
      <p className="muted small gap-top">
        {direct ? 'The stock changes at once and the change is kept in the stock history.' : 'This goes for approval; the stock changes once it is approved under Stock audit.'}{' '}
        {difference !== null && difference > 0 && (
          <>
            New stock from a stockist is entered under{' '}
            <Link className="link" to="/pharmacy/purchases">
              Purchases → Invoices
            </Link>
            .{' '}
          </>
        )}
        <Link className="link" to={`/pharmacy/audit?tab=history&medicine=${batch.medicine_id}`}>
          Stock history
        </Link>
      </p>
      {error && <p className="form-error">{error}</p>}
    </Popup>
  )
}

/** Everything printed on the pack and where it is kept, in one small form. */
export function DetailsPopup({ batch, onClose, onDone }: { batch: StockBatch; onClose: () => void; onDone: (message: string) => void }) {
  // What was paid, the pack size and the GST of a batch bought on an invoice are corrected on that invoice.
  const own = !batch.invoice
  const start = {
    batch_no: batch.batch_no, expiry: batch.expiry.slice(0, 7), pack_mrp: String(batch.pack_mrp), pack_price: String(batch.pack_price), units_per_pack: String(batch.units_per_pack),
    gst_percent: String(batch.gst_percent), hsn_code: batch.hsn_code ?? '', rack_no: batch.rack_no ?? '', box_no: batch.box_no ?? '',
  }
  const [form, setForm] = useState(start)
  const [note, setNote] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const changed = (Object.keys(start) as (keyof typeof start)[]).some((key) => form[key].trim() !== start[key])
  const complete = form.batch_no.trim() !== '' && form.expiry !== '' && Number(form.pack_mrp) > 0 && Number(form.units_per_pack) >= 1 && form.pack_price !== '' && form.gst_percent !== ''
  const perUnit = Number(form.pack_mrp) > 0 && Number(form.units_per_pack) >= 1 ? two(Number(form.pack_mrp) / Number(form.units_per_pack)) : null

  const text = (key: keyof typeof start, label: string, maxLength?: number) => (
    <label className="field">
      <span className="field-label">{label}</span>
      <input maxLength={maxLength} value={form[key]} onChange={(e) => setForm({ ...form, [key]: e.target.value })} />
    </label>
  )
  const number = (key: keyof typeof start, label: string, step: string, locked = false) => (
    <label className="field">
      <span className="field-label">{label}</span>
      <input type="number" min={0} step={step} disabled={locked} value={form[key]} onChange={(e) => setForm({ ...form, [key]: e.target.value })} />
    </label>
  )

  const save = async (e: FormEvent) => {
    e.preventDefault()
    if (!changed || !complete || saving) return
    setSaving(true)
    setError('')
    try {
      const { pack_price, units_per_pack, gst_percent, ...rest } = form
      await api.put(`/pharmacy/stock/${batch.id}`, {
        ...rest, batch_no: form.batch_no.trim(), rack_no: form.rack_no.trim() || null, box_no: form.box_no.trim() || null, hsn_code: form.hsn_code.trim() || null,
        ...(own ? { pack_price, units_per_pack, gst_percent } : {}), reason: note.trim() || null,
      })
      onDone(`${batch.medicine?.name} batch ${form.batch_no.trim()}: details saved. The change is in the Stock data update report.`)
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'Could not save. Please try again.')
      setSaving(false)
    }
  }

  return (
    <Popup
      title="Edit batch details"
      sub={`${about(batch)} · ${batch.units_in_stock} in stock`}
      onClose={onClose}
      onSubmit={save}
      foot={
        <>
          <button type="button" className="btn ghost" onClick={onClose}>
            Cancel
          </button>
          <button className="btn primary" disabled={!changed || !complete || saving}>
            {saving ? 'Saving…' : 'Save details'}
          </button>
        </>
      }
    >
      <div className="grid three">
        {text('batch_no', 'Batch number', 50)}
        <label className="field">
          <span className="field-label">Expiry</span>
          <input type="month" value={form.expiry} onChange={(e) => setForm({ ...form, expiry: e.target.value })} />
        </label>
        {number('pack_mrp', 'MRP / pack ₹', '0.01')}
        {number('pack_price', 'Purchase rate / pack ₹', '0.01', !own)}
        {number('units_per_pack', 'Units in a pack', '1', !own)}
        {number('gst_percent', 'GST %', '0.01', !own)}
        {text('hsn_code', 'HSN code', 20)}
        {text('rack_no', 'Rack', 30)}
        {text('box_no', 'Box', 30)}
      </div>
      <p className="muted small gap-top">
        {perUnit !== null && <>MRP of one unit: <strong>{rupees(perUnit)}</strong>. </>}
        {!own && (
          <>
            The purchase rate, pack size and GST come from invoice {batch.invoice?.invoice_no}; correct them on the invoice under{' '}
            <Link className="link" to="/pharmacy/purchases">
              Purchases → Invoices
            </Link>
            .{' '}
          </>
        )}
        The rack and box here are for a batch kept apart from the rest; to move the medicine itself use{' '}
        <Link className="link" to="/pharmacy/shelves">
          Racks and boxes
        </Link>
        .
      </p>
      <label className="field gap-top">
        <span className="field-label">Note (if there is something to add)</span>
        <input maxLength={255} placeholder="For example: label read wrongly, price revised" value={note} onChange={(e) => setNote(e.target.value)} />
      </label>
      {error && <p className="form-error">{error}</p>}
    </Popup>
  )
}

const blank = { medicine_id: '', batch_no: '', expiry: '', units_per_pack: '', quantity: '', cost_price: '', mrp: '', gst_percent: '', hsn_code: '' }

/**
 * Stock that is on the shelf but came without an invoice in this system, added one batch at a time without leaving
 * the stock list.
 */
export function AddStockPopup({ onClose, onAdded }: { onClose: () => void; onAdded: (message: string) => void }) {
  const [medicines, setMedicines] = useState<Medicine[]>([])
  const [form, setForm] = useState(blank)
  const [newName, setNewName] = useState<string | null>(null)
  const [error, setError] = useState('')
  const [added, setAdded] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    api.get<Medicine[]>('/pharmacy/medicines', { params: { list: 1 } }).then((res) => setMedicines(res.data))
  }, [])

  const choose = (id: string, from = medicines) => {
    const medicine = from.find((m) => String(m.id) === id)
    setForm((f) => ({ ...f, medicine_id: id, ...(medicine ? { units_per_pack: String(medicine.units_per_pack), gst_percent: String(medicine.gst_percent), hsn_code: medicine.hsn_code ?? '' } : {}) }))
  }
  const chosen = medicines.find((m) => String(m.id) === form.medicine_id)
  const perPack = Number(form.units_per_pack)
  const units = Number(form.quantity)
  const complete = form.medicine_id !== '' && form.batch_no.trim() !== '' && form.expiry !== '' && perPack >= 1 && units >= 1 && Number(form.mrp) > 0 && form.cost_price !== '' && form.gst_percent !== ''

  const number = (key: keyof typeof blank, label: string, step: string) => (
    <label className="field">
      <span className="field-label">{label}</span>
      <input type="number" min={0} step={step} value={form[key]} onChange={(e) => setForm({ ...form, [key]: e.target.value })} />
    </label>
  )

  const save = async (another: boolean) => {
    if (!complete || saving) return
    setSaving(true)
    setError('')
    try {
      await api.post('/pharmacy/opening-stock', { items: [{ ...form, batch_no: form.batch_no.trim(), hsn_code: form.hsn_code || null }] })
      const message = `Added ${units} units of ${chosen?.name ?? 'the medicine'}, batch ${form.batch_no.trim()}.`
      onAdded(message)
      if (another) {
        setAdded(message)
        setForm(blank)
        setSaving(false)
      } else {
        onClose()
      }
    } catch (err) {
      setError((Object.values(fieldErrors(err))[0] ?? 'Could not save. Please try again.').replace(/^Line 1 /, 'This batch '))
      setSaving(false)
    }
  }

  return (
    <>
      <Popup
        title="Add stock"
        sub="For stock on the shelf that has no purchase invoice here"
        size="wide"
        onClose={onClose}
        onSubmit={(e) => {
          e.preventDefault()
          save(false)
        }}
        foot={
          <>
            <button type="button" className="btn ghost" onClick={() => save(true)} disabled={!complete || saving}>
              Save and add another
            </button>
            <button className="btn primary" disabled={!complete || saving}>
              {saving ? 'Saving…' : 'Save'}
            </button>
          </>
        }
      >
        {added && <p className="notice">{added}</p>}
        <label className="field">
          <span className="field-label">Medicine</span>
          <MedicineChoose key={added} medicines={medicines} value={form.medicine_id} onChange={(id) => choose(id)} onNew={(typed) => setNewName(typed)} label="Medicine" />
        </label>
        <div className="grid four gap-top">
          <label className="field">
            <span className="field-label">Batch number</span>
            <input maxLength={50} value={form.batch_no} onChange={(e) => setForm({ ...form, batch_no: e.target.value })} />
          </label>
          <label className="field">
            <span className="field-label">Expiry</span>
            <input type="month" value={form.expiry} onChange={(e) => setForm({ ...form, expiry: e.target.value })} />
          </label>
          {number('quantity', 'On the shelf (units)', '1')}
          {number('units_per_pack', 'Units in a pack', '1')}
          {number('mrp', 'MRP / pack ₹', '0.01')}
          {number('cost_price', 'Purchase rate / pack ₹', '0.01')}
          {number('gst_percent', 'GST %', '0.01')}
          <label className="field">
            <span className="field-label">HSN code</span>
            <input maxLength={20} value={form.hsn_code} onChange={(e) => setForm({ ...form, hsn_code: e.target.value })} />
          </label>
        </div>
        <p className="muted small gap-top">
          {perPack >= 1 && units >= 1 && (
            <>
              <strong>{units}</strong> units = {Math.floor(units / perPack)} pack{Math.floor(units / perPack) === 1 ? '' : 's'}
              {units % perPack ? ` + ${units % perPack} loose` : ''}
              {Number(form.mrp) > 0 && <> · MRP {rupees(two(Number(form.mrp) / perPack))} a unit</>}.{' '}
            </>
          )}
          It goes to the medicine's own rack and box. Stock bought from a stockist is entered under{' '}
          <Link className="link" to="/pharmacy/purchases">
            Purchases → Invoices
          </Link>
          ; many batches at once under{' '}
          <Link className="link" to="/pharmacy/opening-stock">
            Opening stock
          </Link>
          .
        </p>
        {error && <p className="form-error">{error}</p>}
      </Popup>
      {newName !== null && (
        <NewMedicine
          start={{ name: newName, units_per_pack: form.units_per_pack, gst_percent: form.gst_percent, hsn_code: form.hsn_code }}
          onClose={() => setNewName(null)}
          onAdded={(m) => {
            const list = [...medicines.filter((x) => x.id !== m.id), m].sort((a, b) => a.name.localeCompare(b.name))
            setMedicines(list)
            choose(String(m.id), list)
            setNewName(null)
          }}
        />
      )}
    </>
  )
}
