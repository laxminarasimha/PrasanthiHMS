import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../../api'
import { useAuth } from '../../auth'
import { SelectField, TextField } from '../../components/Field'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { PAYMENT_MODES, expiryLabel, longDate, modeLabel, rupees, today } from '../../format'
import type { Medicine, PurchaseInvoice, PurchaseOrder, Stockist } from '../../types'

type Line = {
  /** Set when the line is an existing batch on an invoice being corrected. */
  id?: number
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
  box_no: string
  hsn_code: string
  discount_amount: string
  tax_on_free: boolean
}

const emptyLine = (): Line => ({
  medicine_id: '', batch_no: '', expiry: '', units_per_pack: '', packs: '', free_packs: '', pack_price: '', pack_mrp: '', discount_percent: '', gst_percent: '', rack_no: '', box_no: '', hsn_code: '', discount_amount: '', tax_on_free: false,
})

/** What the stockist charges for the line before tax: paid packs, less the discount in % and in rupees. */
const lineNet = (l: Line) => Math.max(0, Number(l.packs || 0) * Number(l.pack_price || 0) * (1 - Number(l.discount_percent || 0) / 100) - Number(l.discount_amount || 0))
const lineTax = (l: Line) => ((lineNet(l) + (l.tax_on_free ? Number(l.free_packs || 0) * Number(l.pack_price || 0) : 0)) * Number(l.gst_percent || 0)) / 100
/** The real cost of one unit once the free packs are counted, and the margin against MRP. */
const costing = (l: Line) => {
  const perPack = Number(l.units_per_pack || 0)
  const units = (Number(l.packs || 0) + Number(l.free_packs || 0)) * perPack
  if (!units || !Number(l.pack_price)) return null
  const cost = lineNet(l) / units
  const mrp = perPack && Number(l.pack_mrp) ? Number(l.pack_mrp) / perPack : 0
  return { units, free: Number(l.free_packs || 0) * perPack, cost, mrp, margin: mrp ? ((mrp - cost) / mrp) * 100 : null }
}
const two = (n: number) => Math.round(n * 100) / 100

export function PharmacyPurchases() {
  const { user } = useAuth()
  const canEdit = Boolean(user?.can_run_pharmacy)
  const [invoices, setInvoices] = useState<PurchaseInvoice[]>([])
  const [viewing, setViewing] = useState<PurchaseInvoice | null>(null)
  const [adding, setAdding] = useState(false)
  const [editing, setEditing] = useState<PurchaseInvoice | null>(null)
  const [reason, setReason] = useState('')
  const [stockists, setStockists] = useState<Stockist[]>([])
  const [medicines, setMedicines] = useState<Medicine[]>([])
  const [stockistId, setStockistId] = useState('')
  const [invoiceNo, setInvoiceNo] = useState('')
  const [invoiceDate, setInvoiceDate] = useState(today())
  const [orders, setOrders] = useState<PurchaseOrder[]>([])
  const [orderId, setOrderId] = useState('')
  const [remarks, setRemarks] = useState('')
  const [extras, setExtras] = useState({ other_charges: '', cash_discount: '', round_off: '' })
  const [lines, setLines] = useState<Line[]>([emptyLine()])
  const [errors, setErrors] = useState<string[]>([])
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState('')
  const [payAmount, setPayAmount] = useState('')
  const [payMode, setPayMode] = useState('cash')
  const [payRef, setPayRef] = useState('')
  const [payDate, setPayDate] = useState(today())
  const [payError, setPayError] = useState('')

  const load = useCallback(() => {
    api.get<PurchaseInvoice[]>('/pharmacy/purchases').then((res) => setInvoices(res.data))
  }, [])
  useEffect(load, [load])

  const startAdding = () => {
    api.get<Stockist[]>('/pharmacy/stockists').then((res) => setStockists(res.data))
    api.get<Medicine[]>('/pharmacy/medicines').then((res) => setMedicines(res.data))
    api.get<PurchaseOrder[]>('/pharmacy/purchase-orders').then((res) => setOrders(res.data.filter((o) => o.status === 'open')))
    setOrderId('')
    setViewing(null)
    setEditing(null)
    setStockistId('')
    setInvoiceNo('')
    setInvoiceDate(today())
    setRemarks('')
    setExtras({ other_charges: '', cash_discount: '', round_off: '' })
    setLines([emptyLine()])
    setAdding(true)
    setNotice('')
    setErrors([])
  }

  // Opens a saved invoice in the same form, to correct what was typed.
  const startEditing = (invoice: PurchaseInvoice) => {
    api.get<Stockist[]>('/pharmacy/stockists').then((res) => setStockists(res.data))
    setMedicines((invoice.batches ?? []).map((b) => ({ id: b.medicine_id, name: b.medicine?.name ?? '' }) as Medicine))
    setEditing(invoice)
    setStockistId(String(invoice.stockist.id))
    setInvoiceNo(invoice.invoice_no)
    setInvoiceDate(invoice.invoice_date.slice(0, 10))
    setRemarks(invoice.remarks ?? '')
    setExtras({ other_charges: invoice.other_charges ? String(invoice.other_charges) : '', cash_discount: invoice.cash_discount ? String(invoice.cash_discount) : '', round_off: invoice.round_off ? String(invoice.round_off) : '' })
    setReason('')
    setLines(
      (invoice.batches ?? []).map((b) => ({
        id: b.id, medicine_id: String(b.medicine_id), batch_no: b.batch_no, expiry: b.expiry.slice(0, 7), units_per_pack: String(b.units_per_pack),
        packs: String(b.packs), free_packs: String(b.free_packs), pack_price: String(b.pack_price), pack_mrp: String(b.pack_mrp),
        discount_percent: String(b.discount_percent), gst_percent: String(b.gst_percent), rack_no: b.rack_no ?? '', box_no: b.box_no ?? '', hsn_code: b.hsn_code ?? '',
        discount_amount: b.discount_amount ? String(b.discount_amount) : '', tax_on_free: Boolean(b.tax_on_free),
      })),
    )
    setViewing(null)
    setAdding(true)
    setNotice('')
    setErrors([])
  }

  const setLine = (index: number, key: keyof Line, value: string | boolean) =>
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
            next.hsn_code = medicine.hsn_code ?? ''
          }
        }
        // A scheme typed the way it is said, "10+2", fills both boxes: ten paid, two free.
        if (key === 'packs' && typeof value === 'string') {
          const scheme = value.match(/^\s*(\d+)\s*\+\s*(\d+)\s*$/)
          if (scheme) {
            next.packs = scheme[1]
            next.free_packs = scheme[2]
          }
        }
        return next
      }),
    )

  const filled = lines.filter((l) => l.medicine_id || l.batch_no || l.packs)
  const subtotal = filled.reduce((sum, l) => sum + lineNet(l), 0)
  const tax = filled.reduce((sum, l) => sum + lineTax(l), 0)
  const grand = subtotal + tax + Number(extras.other_charges || 0) - Number(extras.cash_discount || 0) + Number(extras.round_off || 0)

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors([])
    try {
      const body = {
        stockist_id: stockistId || null,
        invoice_no: invoiceNo,
        invoice_date: invoiceDate,
        ...(editing ? {} : { purchase_order_id: orderId || null }),
        remarks: remarks || null,
        other_charges: extras.other_charges || 0,
        cash_discount: extras.cash_discount || 0,
        round_off: extras.round_off || 0,
        items: filled.map((l) => ({ ...l, free_packs: l.free_packs || 0, discount_percent: l.discount_percent || 0, discount_amount: l.discount_amount || 0, rack_no: l.rack_no || null, box_no: l.box_no || null, hsn_code: l.hsn_code || null })),
      }
      const res = editing ? await api.put<PurchaseInvoice>(`/pharmacy/purchases/${editing.id}`, { ...body, reason }) : await api.post<PurchaseInvoice>('/pharmacy/purchases', body)
      setNotice(editing ? `Invoice ${res.data.invoice_no} changed and stock corrected. The change is recorded in the Invoice modified report.` : `Invoice ${res.data.invoice_no} saved. ${res.data.batches?.length ?? 0} batch(es) added to stock.`)
      setEditing(null)
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
            <h1 className="grow">{editing ? `Correct invoice ${editing.invoice_no}` : 'Add purchase invoice'}</h1>
            <button type="button" className="btn ghost" onClick={() => setAdding(false)}>
              Cancel
            </button>
          </div>
          <div className="grid four">
            <SelectField label="Stockist" required value={stockistId} onChange={setStockistId} options={stockists.map((s) => ({ value: String(s.id), label: s.name }))} placeholder="Select stockist" />
            <TextField label="Invoice number" required value={invoiceNo} onChange={setInvoiceNo} />
            <TextField label="Invoice date" required type="date" value={invoiceDate} onChange={setInvoiceDate} />
            {!editing && (
              <SelectField
                label="Against purchase order"
                value={orders.some((o) => String(o.id) === orderId && String(o.stockist.id) === stockistId) ? orderId : ''}
                onChange={setOrderId}
                options={orders.filter((o) => String(o.stockist.id) === stockistId).map((o) => ({ value: String(o.id), label: `${o.order_no} · ${longDate(o.order_date)}` }))}
                placeholder={stockistId && orders.some((o) => String(o.stockist.id) === stockistId) ? 'No order' : 'No open order with this stockist'}
              />
            )}
            <TextField label="Remarks" value={remarks} onChange={setRemarks} />
          </div>
          {stockists.length === 0 && <p className="muted small">No stockists yet. Add one under "Medicines &amp; stockists" first.</p>}

          {editing && <p className="muted small">Correct what was typed wrongly. Changing the packs changes the stock by the same number of units. Medicines cannot be added or removed here; use a stock return or a new invoice for that.</p>}
          <h3>Medicines received</h3>
          <p className="muted small">For a scheme, type it the way the stockist says it: <b>10+2</b> in the Qty box means ten paid packs and two free. Stock goes up by all twelve; you pay for ten.</p>
          {lines.map((l, i) => {
            const c = costing(l)
            const num = (key: keyof Line, label: string, more: { step?: string; min?: number; max?: number; disabled?: boolean } = {}) => (
              <label>
                {label}
                <input type="number" min={more.min ?? 0} max={more.max} step={more.step} disabled={more.disabled} value={String(l[key] ?? '')} onChange={(e) => setLine(i, key, e.target.value)} />
              </label>
            )
            return (
              <div className="purchase-line" key={i}>
                <div className="purchase-fields">
                  <label className="wide">
                    Medicine
                    <select value={l.medicine_id} disabled={Boolean(editing)} onChange={(e) => setLine(i, 'medicine_id', e.target.value)} aria-label={`Medicine ${i + 1}`}>
                      <option value="">Select medicine</option>
                      {medicines.map((m) => (
                        <option key={m.id} value={m.id}>
                          {m.name}
                        </option>
                      ))}
                    </select>
                  </label>
                  <label>
                    Batch
                    <input value={l.batch_no} onChange={(e) => setLine(i, 'batch_no', e.target.value)} />
                  </label>
                  <label>
                    Expiry
                    <input type="month" value={l.expiry} onChange={(e) => setLine(i, 'expiry', e.target.value)} />
                  </label>
                  {num('units_per_pack', 'Units in a pack', { min: 1, disabled: Boolean(editing) })}
                  <label>
                    Qty (packs)
                    <input inputMode="numeric" placeholder="10 or 10+2" value={l.packs} onChange={(e) => setLine(i, 'packs', e.target.value)} onBlur={(e) => setLine(i, 'packs', e.target.value.replace(/[^0-9]/g, ''))} />
                  </label>
                  {num('free_packs', 'Free (packs)')}
                  {num('pack_price', 'Purchase rate / pack ₹', { step: '0.01' })}
                  {num('pack_mrp', 'MRP / pack ₹', { step: '0.01' })}
                  {num('discount_percent', 'Discount %', { step: '0.01', max: 100 })}
                  {num('discount_amount', 'Discount ₹', { step: '0.01' })}
                  {num('gst_percent', 'GST %', { step: '0.01' })}
                  <label>
                    HSN code
                    <input maxLength={20} value={l.hsn_code} onChange={(e) => setLine(i, 'hsn_code', e.target.value)} />
                  </label>
                  <label>
                    Rack
                    <input maxLength={30} value={l.rack_no} onChange={(e) => setLine(i, 'rack_no', e.target.value)} />
                  </label>
                  <label>
                    Box
                    <input maxLength={30} value={l.box_no} onChange={(e) => setLine(i, 'box_no', e.target.value)} />
                  </label>
                </div>
                <div className="purchase-foot">
                  <label className="check">
                    <input type="checkbox" checked={l.tax_on_free} disabled={!Number(l.free_packs)} onChange={(e) => setLine(i, 'tax_on_free', e.target.checked)} />
                    GST charged on the free packs too
                  </label>
                  <span className="grow purchase-cost">
                    {c ? (
                      <>
                        You pay <b>{rupees(two(lineNet(l)))}</b> + GST {rupees(two(lineTax(l)))} · stock goes up by <b>{c.units}</b> units{c.free ? ` (${c.free} free)` : ''} · real cost <b>{rupees(two(c.cost))}</b> a unit
                        {c.mrp ? (
                          <>
                            {' '}against MRP {rupees(two(c.mrp))} · margin <b className={c.margin !== null && c.margin < 0 ? 'due' : 'settled'}>{c.margin !== null ? `${c.margin.toFixed(1)}%` : '—'}</b>
                          </>
                        ) : null}
                      </>
                    ) : (
                      <span className="muted">Fill in the quantity and rate to see the real cost and margin.</span>
                    )}
                  </span>
                  {!editing && (
                    <button type="button" className="link danger" onClick={() => setLines((rows) => (rows.length > 1 ? rows.filter((_, r) => r !== i) : [emptyLine()]))}>
                      Remove
                    </button>
                  )}
                </div>
              </div>
            )
          })}
          {!editing && (
            <button type="button" className="link add-medicine" onClick={() => setLines((rows) => [...rows, emptyLine()])}>
              + Add another medicine
            </button>
          )}

          <dl className="totals narrow-totals">
            <dt>Total before tax</dt>
            <dd>{rupees(Math.round(subtotal * 100) / 100)}</dd>
            <dt>GST</dt>
            <dd>{rupees(Math.round(tax * 100) / 100)}</dd>
            <dt>Freight and other charges ₹</dt>
            <dd>
              <input type="number" min={0} step="0.01" className="narrow num" value={extras.other_charges} onChange={(e) => setExtras({ ...extras, other_charges: e.target.value })} aria-label="Freight and other charges" />
            </dd>
            <dt>Cash discount on the bill ₹</dt>
            <dd>
              <input type="number" min={0} step="0.01" className="narrow num" value={extras.cash_discount} onChange={(e) => setExtras({ ...extras, cash_discount: e.target.value })} aria-label="Cash discount" />
            </dd>
            <dt>Round off ₹ (+ or −)</dt>
            <dd>
              <input type="number" min={-10} max={10} step="0.01" className="narrow num" value={extras.round_off} onChange={(e) => setExtras({ ...extras, round_off: e.target.value })} aria-label="Round off" />
            </dd>
            <dt className="strong">Invoice total</dt>
            <dd className="strong">{rupees(two(grand))}</dd>
          </dl>

          {errors.length > 0 && (
            <div className="form-error">
              {errors.map((message) => (
                <div key={message}>{message}</div>
              ))}
            </div>
          )}
          {editing && (
            <div className="gap-top">
              <TextField label="Reason for the change" required value={reason} onChange={setReason} />
            </div>
          )}
          <div className="panel-foot">
            <button className="btn primary" disabled={saving || filled.length === 0 || (Boolean(editing) && reason.trim().length < 3)}>
              {saving ? 'Saving…' : editing ? 'Save corrections' : 'Save invoice and add to stock'}
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
                    <th className="num">Paid</th>
                    <th className="num">Balance</th>
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
                      <td className="num">{rupees(inv.paid)}</td>
                      <td className={`num ${inv.total - inv.paid > 0.004 ? 'due' : ''}`}>{rupees(Math.round((inv.total - inv.paid) * 100) / 100)}</td>
                    </tr>
                  ))}
                  {invoices.length === 0 && (
                    <tr className="empty">
                      <td colSpan={7}>No purchase invoices yet.</td>
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
                <div className="row">
                  {canEdit && (
                    <button className="btn ghost" onClick={() => startEditing(viewing)}>
                      Edit
                    </button>
                  )}
                  <button className="btn ghost" onClick={() => setViewing(null)}>
                    Close
                  </button>
                </div>
              </div>
              {viewing.order && <p className="muted small">Against purchase order {viewing.order.order_no}.</p>}
              {viewing.flagged ? (
                <p className="form-error flag-note">
                  {viewing.flagged} {viewing.flagged === 1 ? 'line needs' : 'lines need'} a second look, marked below. Check with the stockist's bill before paying.
                </p>
              ) : null}
              <table className="compact">
                <thead>
                  <tr>
                    <th>Medicine</th>
                    <th>Batch</th>
                    <th>Expiry</th>
                    <th className="num">Packs</th>
                    <th className="num">Rate / pack</th>
                    <th className="num">MRP / pack</th>
                    <th className="num">Disc</th>
                    <th className="num">GST %</th>
                    <th className="num">Real cost / unit</th>
                    <th className="num">Margin</th>
                  </tr>
                </thead>
                <tbody>
                  {viewing.batches?.map((b) => (
                    <tr key={b.id} className="static">
                      <td>
                        {b.medicine?.name}
                        {b.flags?.map((flag) => (
                          <small key={flag} className="block import-warn">
                            {flag}
                          </small>
                        ))}
                      </td>
                      <td>{b.batch_no}</td>
                      <td>{expiryLabel(b.expiry)}</td>
                      <td className="num">
                        {b.packs}
                        {b.free_packs > 0 ? ` + ${b.free_packs} free` : ''}
                      </td>
                      <td className="num">{rupees(b.pack_price)}</td>
                      <td className="num">{rupees(b.pack_mrp)}</td>
                      <td className="num">{[b.discount_percent ? `${b.discount_percent}%` : '', b.discount_amount ? rupees(b.discount_amount) : ''].filter(Boolean).join(' + ') || '—'}</td>
                      <td className="num">
                        {b.gst_percent}
                        {b.tax_on_free ? <small className="muted block">also on free</small> : null}
                      </td>
                      <td className="num">{b.unit_cost !== undefined ? rupees(b.unit_cost) : '—'}</td>
                      <td className={`num ${b.margin_percent != null && b.margin_percent < 0 ? 'due' : ''}`}>{b.margin_percent != null ? `${b.margin_percent}%` : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <dl className="totals">
                <dt>Before tax</dt>
                <dd>{rupees(viewing.subtotal)}</dd>
                <dt>GST</dt>
                <dd>{rupees(viewing.tax)}</dd>
                {viewing.other_charges ? (
                  <>
                    <dt>Freight and other charges</dt>
                    <dd>{rupees(viewing.other_charges)}</dd>
                  </>
                ) : null}
                {viewing.cash_discount ? (
                  <>
                    <dt>Cash discount</dt>
                    <dd>−{rupees(viewing.cash_discount)}</dd>
                  </>
                ) : null}
                {viewing.round_off ? (
                  <>
                    <dt>Round off</dt>
                    <dd>{viewing.round_off > 0 ? '+' : '−'}{rupees(Math.abs(viewing.round_off))}</dd>
                  </>
                ) : null}
                <dt className="strong">Total</dt>
                <dd className="strong">{rupees(viewing.total)}</dd>
                <dt>Paid to stockist</dt>
                <dd>{rupees(viewing.paid)}</dd>
                <dt className="strong">Balance owed</dt>
                <dd className={`strong ${viewing.total - viewing.paid > 0.004 ? 'due' : 'settled'}`}>{rupees(Math.round((viewing.total - viewing.paid) * 100) / 100)}</dd>
              </dl>
              {canEdit && viewing.total - viewing.paid > 0.004 && (
                <form
                  className="row gap-top"
                  onSubmit={async (e) => {
                    e.preventDefault()
                    try {
                      const res = await api.post<PurchaseInvoice>(`/pharmacy/purchases/${viewing.id}/payments`, { amount: payAmount, mode: payMode, reference: payRef || null, paid_on: payDate })
                      setViewing(res.data)
                      setPayAmount('')
                      setPayRef('')
                      setPayError('')
                      load()
                    } catch (err) {
                      setPayError(Object.values(fieldErrors(err))[0])
                    }
                  }}
                >
                  <input type="number" min={0} step="0.01" placeholder="Amount paid to stockist" value={payAmount} onChange={(e) => setPayAmount(e.target.value)} aria-label="Amount paid to stockist" />
                  <select value={payMode} onChange={(e) => setPayMode(e.target.value)} aria-label="How it was paid">
                    {PAYMENT_MODES.filter((m) => m.value !== 'insurance').map((m) => (
                      <option key={m.value} value={m.value}>
                        {m.label}
                      </option>
                    ))}
                  </select>
                  <input placeholder="Cheque or transfer no." value={payRef} onChange={(e) => setPayRef(e.target.value)} aria-label="Cheque or transfer number" />
                  <input type="date" max={today()} value={payDate} onChange={(e) => setPayDate(e.target.value || today())} aria-label="Paid on" />
                  <button className="btn primary" disabled={!payAmount || Number(payAmount) <= 0}>
                    Record payment
                  </button>
                </form>
              )}
              {payError && <p className="form-error">{payError}</p>}
              {viewing.due_date && viewing.total - viewing.paid > 0.004 && <p className="muted small">Due to be paid by {longDate(viewing.due_date)}.</p>}
              {viewing.payments && viewing.payments.length > 0 && (
                <ul className="plain-list small">
                  {viewing.payments.map((pay) => (
                    <li key={pay.id}>
                      {longDate(pay.paid_on)} · {rupees(pay.amount)} by {modeLabel(pay.mode)}
                      {pay.reference ? ` (${pay.reference})` : ''}
                      {pay.payer ? ` · ${pay.payer.name}` : ''}
                    </li>
                  ))}
                </ul>
              )}
              {viewing.remarks && <p className="muted">{viewing.remarks}</p>}
            </aside>
          )}
        </div>
      )}
    </>
  )
}
