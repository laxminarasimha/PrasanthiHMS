import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../../api'
import { useAuth } from '../../auth'
import { SelectField, TextField } from '../../components/Field'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { expiryLabel, longDate, rupees, today } from '../../format'
import type { Medicine, PurchaseInvoice, Stockist } from '../../types'

type Line = {
  medicine_id: string
  batch_no: string
  expiry: string
  units_per_pack: string
  packs: string
  free_packs: string
  pack_price: string
  pack_mrp: string
  discount_percent: string
  gst_percent: string
  rack_no: string
}

const emptyLine = (): Line => ({
  medicine_id: '', batch_no: '', expiry: '', units_per_pack: '', packs: '', free_packs: '', pack_price: '', pack_mrp: '', discount_percent: '', gst_percent: '', rack_no: '',
})

const lineNet = (l: Line) => Number(l.packs || 0) * Number(l.pack_price || 0) * (1 - Number(l.discount_percent || 0) / 100)

export function PharmacyPurchases() {
  const { user } = useAuth()
  const canEdit = Boolean(user?.can_run_pharmacy)
  const [invoices, setInvoices] = useState<PurchaseInvoice[]>([])
  const [viewing, setViewing] = useState<PurchaseInvoice | null>(null)
  const [adding, setAdding] = useState(false)
  const [stockists, setStockists] = useState<Stockist[]>([])
  const [medicines, setMedicines] = useState<Medicine[]>([])
  const [stockistId, setStockistId] = useState('')
  const [invoiceNo, setInvoiceNo] = useState('')
  const [invoiceDate, setInvoiceDate] = useState(today())
  const [remarks, setRemarks] = useState('')
  const [lines, setLines] = useState<Line[]>([emptyLine()])
  const [errors, setErrors] = useState<string[]>([])
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState('')

  const load = useCallback(() => {
    api.get<PurchaseInvoice[]>('/pharmacy/purchases').then((res) => setInvoices(res.data))
  }, [])
  useEffect(load, [load])

  const startAdding = () => {
    api.get<Stockist[]>('/pharmacy/stockists').then((res) => setStockists(res.data))
    api.get<Medicine[]>('/pharmacy/medicines').then((res) => setMedicines(res.data))
    setViewing(null)
    setAdding(true)
    setNotice('')
    setErrors([])
  }

  const setLine = (index: number, key: keyof Line, value: string) =>
    setLines((rows) =>
      rows.map((row, i) => {
        if (i !== index) return row
        const next = { ...row, [key]: value }
        // Choosing a medicine fills in its usual pack size and GST.
        if (key === 'medicine_id') {
          const medicine = medicines.find((m) => String(m.id) === value)
          if (medicine) {
            next.units_per_pack = String(medicine.units_per_pack)
            next.gst_percent = String(medicine.gst_percent)
          }
        }
        return next
      }),
    )

  const filled = lines.filter((l) => l.medicine_id || l.batch_no || l.packs)
  const subtotal = filled.reduce((sum, l) => sum + lineNet(l), 0)
  const tax = filled.reduce((sum, l) => sum + (lineNet(l) * Number(l.gst_percent || 0)) / 100, 0)

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors([])
    try {
      const res = await api.post<PurchaseInvoice>('/pharmacy/purchases', {
        stockist_id: stockistId || null,
        invoice_no: invoiceNo,
        invoice_date: invoiceDate,
        remarks: remarks || null,
        items: filled.map((l) => ({ ...l, free_packs: l.free_packs || 0, discount_percent: l.discount_percent || 0, rack_no: l.rack_no || null })),
      })
      setNotice(`Invoice ${res.data.invoice_no} saved. ${res.data.batches?.length ?? 0} batch(es) added to stock.`)
      setAdding(false)
      setInvoiceNo('')
      setRemarks('')
      setLines([emptyLine()])
      load()
    } catch (err) {
      setErrors([...new Set(Object.values(fieldErrors(err)))])
    } finally {
      setSaving(false)
    }
  }

  const view = (invoice: PurchaseInvoice) => {
    setAdding(false)
    api.get<PurchaseInvoice>(`/pharmacy/purchases/${invoice.id}`).then((res) => setViewing(res.data))
  }

  return (
    <>
      <PharmacyTabs />
      {adding ? (
        <form className="card" onSubmit={save} noValidate>
          <div className="toolbar">
            <h1 className="grow">Add purchase invoice</h1>
            <button type="button" className="btn ghost" onClick={() => setAdding(false)}>
              Cancel
            </button>
          </div>
          <div className="grid four">
            <SelectField label="Stockist" required value={stockistId} onChange={setStockistId} options={stockists.map((s) => ({ value: String(s.id), label: s.name }))} placeholder="Select stockist" />
            <TextField label="Invoice number" required value={invoiceNo} onChange={setInvoiceNo} />
            <TextField label="Invoice date" required type="date" value={invoiceDate} onChange={setInvoiceDate} />
            <TextField label="Remarks" value={remarks} onChange={setRemarks} />
          </div>
          {stockists.length === 0 && <p className="muted small">No stockists yet. Add one under "Medicines &amp; stockists" first.</p>}

          <h3>Medicines received</h3>
          <div className="rx">
            <div className="purchase-row rx-head">
              <span>Medicine</span>
              <span>Batch</span>
              <span>Expiry</span>
              <span>Units/pack</span>
              <span>Packs</span>
              <span>Free</span>
              <span>Price/pack</span>
              <span>MRP/pack</span>
              <span>Disc %</span>
              <span>GST %</span>
              <span>Rack</span>
              <span className="num">Net</span>
              <span />
            </div>
            {lines.map((l, i) => (
              <div className="purchase-row" key={i}>
                <select value={l.medicine_id} onChange={(e) => setLine(i, 'medicine_id', e.target.value)} aria-label={`Medicine ${i + 1}`}>
                  <option value="">Select medicine</option>
                  {medicines.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.name}
                    </option>
                  ))}
                </select>
                <input value={l.batch_no} onChange={(e) => setLine(i, 'batch_no', e.target.value)} aria-label="Batch number" />
                <input type="month" value={l.expiry} onChange={(e) => setLine(i, 'expiry', e.target.value)} aria-label="Expiry month" />
                <input type="number" min={1} value={l.units_per_pack} onChange={(e) => setLine(i, 'units_per_pack', e.target.value)} aria-label="Units per pack" />
                <input type="number" min={1} value={l.packs} onChange={(e) => setLine(i, 'packs', e.target.value)} aria-label="Packs" />
                <input type="number" min={0} value={l.free_packs} onChange={(e) => setLine(i, 'free_packs', e.target.value)} aria-label="Free packs" />
                <input type="number" min={0} step="0.01" value={l.pack_price} onChange={(e) => setLine(i, 'pack_price', e.target.value)} aria-label="Price per pack" />
                <input type="number" min={0} step="0.01" value={l.pack_mrp} onChange={(e) => setLine(i, 'pack_mrp', e.target.value)} aria-label="MRP per pack" />
                <input type="number" min={0} max={100} step="0.01" value={l.discount_percent} onChange={(e) => setLine(i, 'discount_percent', e.target.value)} aria-label="Discount percent" />
                <input type="number" min={0} step="0.01" value={l.gst_percent} onChange={(e) => setLine(i, 'gst_percent', e.target.value)} aria-label="GST percent" />
                <input value={l.rack_no} onChange={(e) => setLine(i, 'rack_no', e.target.value)} aria-label="Rack" />
                <span className="num mono">{rupees(Math.round(lineNet(l) * 100) / 100)}</span>
                <button type="button" className="link danger" onClick={() => setLines((rows) => (rows.length > 1 ? rows.filter((_, r) => r !== i) : [emptyLine()]))}>
                  Remove
                </button>
              </div>
            ))}
          </div>
          <button type="button" className="link add-medicine" onClick={() => setLines((rows) => [...rows, emptyLine()])}>
            + Add another medicine
          </button>

          <dl className="totals narrow-totals">
            <dt>Total before tax</dt>
            <dd>{rupees(Math.round(subtotal * 100) / 100)}</dd>
            <dt>GST</dt>
            <dd>{rupees(Math.round(tax * 100) / 100)}</dd>
            <dt className="strong">Invoice total</dt>
            <dd className="strong">{rupees(Math.round((subtotal + tax) * 100) / 100)}</dd>
          </dl>

          {errors.length > 0 && (
            <div className="form-error">
              {errors.map((message) => (
                <div key={message}>{message}</div>
              ))}
            </div>
          )}
          <div className="panel-foot">
            <button className="btn primary" disabled={saving || filled.length === 0}>
              {saving ? 'Saving…' : 'Save invoice and add to stock'}
            </button>
          </div>
        </form>
      ) : (
        <div className="split">
          <section className="card">
            <div className="toolbar">
              <h1 className="grow">Purchase invoices</h1>
              {canEdit && (
                <button className="btn primary" onClick={startAdding}>
                  + Add invoice
                </button>
              )}
            </div>
            {notice && <p className="notice">{notice}</p>}
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Invoice</th>
                    <th>Stockist</th>
                    <th>Date</th>
                    <th className="num">Lines</th>
                    <th className="num">Total</th>
                  </tr>
                </thead>
                <tbody>
                  {invoices.map((inv) => (
                    <tr key={inv.id} onClick={() => view(inv)} className={viewing?.id === inv.id ? 'selected' : ''}>
                      <td>
                        <strong>{inv.invoice_no}</strong>
                      </td>
                      <td>{inv.stockist.name}</td>
                      <td>{longDate(inv.invoice_date)}</td>
                      <td className="num">{inv.batches_count}</td>
                      <td className="num">{rupees(inv.total)}</td>
                    </tr>
                  ))}
                  {invoices.length === 0 && (
                    <tr className="empty">
                      <td colSpan={5}>No purchase invoices yet.</td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </section>

          {viewing && (
            <aside className="card panel wide">
              <div className="panel-head">
                <div>
                  <h2>Invoice {viewing.invoice_no}</h2>
                  <span className="muted">
                    {viewing.stockist.name} · {longDate(viewing.invoice_date)}
                  </span>
                </div>
                <button className="btn ghost" onClick={() => setViewing(null)}>
                  Close
                </button>
              </div>
              <table className="compact">
                <thead>
                  <tr>
                    <th>Medicine</th>
                    <th>Batch</th>
                    <th>Expiry</th>
                    <th className="num">Packs</th>
                    <th className="num">Price</th>
                    <th className="num">MRP</th>
                  </tr>
                </thead>
                <tbody>
                  {viewing.batches?.map((b) => (
                    <tr key={b.id} className="static">
                      <td>{b.medicine?.name}</td>
                      <td>{b.batch_no}</td>
                      <td>{expiryLabel(b.expiry)}</td>
                      <td className="num">
                        {b.packs}
                        {b.free_packs > 0 ? ` + ${b.free_packs} free` : ''}
                      </td>
                      <td className="num">{rupees(b.pack_price)}</td>
                      <td className="num">{rupees(b.pack_mrp)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <dl className="totals">
                <dt>Before tax</dt>
                <dd>{rupees(viewing.subtotal)}</dd>
                <dt>GST</dt>
                <dd>{rupees(viewing.tax)}</dd>
                <dt className="strong">Total</dt>
                <dd className="strong">{rupees(viewing.total)}</dd>
              </dl>
              {viewing.remarks && <p className="muted">{viewing.remarks}</p>}
            </aside>
          )}
        </div>
      )}
    </>
  )
}
