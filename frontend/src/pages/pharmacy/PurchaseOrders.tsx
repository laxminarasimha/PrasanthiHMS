import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../../api'
import { useAuth } from '../../auth'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { longDate, today } from '../../format'
import type { InventoryMedicine, Medicine, PurchaseOrder, Stockist } from '../../types'

type Line = { medicine_id: string; units_per_pack: string; packs: string }
const emptyLine = (): Line => ({ medicine_id: '', units_per_pack: '', packs: '' })
const STATUS = { draft: 'Draft, to be approved', open: 'Ordered', received: 'Received', cancelled: 'Cancelled' } as const
const STATUS_CLASS = { draft: 'warn', open: 'status-arrived', received: 'ok', cancelled: 'off' } as const

/** Orders placed with stockists. An order is a request only; stock arrives through a purchase invoice. */
export function PharmacyPurchaseOrders() {
  const { user } = useAuth()
  const canEdit = Boolean(user?.can_run_pharmacy)
  const [orders, setOrders] = useState<PurchaseOrder[]>([])
  const [viewing, setViewing] = useState<PurchaseOrder | null>(null)
  const [adding, setAdding] = useState(false)
  const [stockists, setStockists] = useState<Stockist[]>([])
  const [medicines, setMedicines] = useState<Medicine[]>([])
  const [stockistId, setStockistId] = useState('')
  const [date, setDate] = useState(today())
  const [remarks, setRemarks] = useState('')
  const [lines, setLines] = useState<Line[]>([emptyLine()])
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const load = useCallback(() => {
    api.get<PurchaseOrder[]>('/pharmacy/purchase-orders').then((res) => setOrders(res.data))
  }, [])
  useEffect(load, [load])

  const start = () => {
    api.get<Stockist[]>('/pharmacy/stockists').then((res) => setStockists(res.data))
    api.get<Medicine[]>('/pharmacy/medicines').then((res) => setMedicines(res.data))
    setAdding(true)
    setViewing(null)
    setLines([emptyLine()])
    setError('')
    setNotice('')
  }

  // Starts the order with every medicine that is low or out of stock, one pack each to be edited.
  const fillLowStock = async () => {
    const res = await api.get<{ all: InventoryMedicine[] }>('/pharmacy/inventory')
    const low = res.data.all.filter((m) => m.units_in_stock <= m.reorder_level)
    setLines(low.length ? low.map((m) => ({ medicine_id: String(m.id), units_per_pack: String(medicines.find((x) => x.id === m.id)?.units_per_pack ?? 1), packs: '1' })) : [emptyLine()])
    if (!low.length) setError('No medicine is low or out of stock right now.')
  }

  const setLine = (index: number, key: keyof Line, value: string) =>
    setLines((rows) =>
      rows.map((row, i) => {
        if (i !== index) return row
        const next = { ...row, [key]: value }
        if (key === 'medicine_id') next.units_per_pack = String(medicines.find((m) => String(m.id) === value)?.units_per_pack ?? '')
        return next
      }),
    )

  const filled = lines.filter((l) => l.medicine_id)

  const save = async (e: FormEvent) => {
    e.preventDefault()
    try {
      const res = await api.post<PurchaseOrder>('/pharmacy/purchase-orders', { stockist_id: stockistId || null, order_date: date, remarks: remarks || null, items: filled })
      setNotice(`Order ${res.data.order_no} saved.`)
      setAdding(false)
      setViewing(res.data)
      load()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    }
  }

  const [packs, setPacks] = useState<Record<number, string>>({})
  const [viewError, setViewError] = useState('')
  const view = (order: PurchaseOrder) =>
    api.get<PurchaseOrder>(`/pharmacy/purchase-orders/${order.id}`).then((res) => {
      setViewing(res.data)
      setViewError('')
      setPacks(Object.fromEntries((res.data.items ?? []).map((i) => [i.id, String(i.packs)])))
    })
  const setStatus = async (status: string) => {
    if (!viewing) return
    try {
      // Approving a draft sends the quantities as they now stand, in case they were corrected.
      const items = viewing.status === 'draft' && status === 'open' ? (viewing.items ?? []).map((i) => ({ id: i.id, packs: Number(packs[i.id] || 0) })) : undefined
      const res = await api.put<PurchaseOrder>(`/pharmacy/purchase-orders/${viewing.id}`, { status, items })
      setViewing(res.data)
      setViewError('')
      load()
    } catch (err) {
      setViewError(Object.values(fieldErrors(err))[0])
    }
  }

  return (
    <>
      <PharmacyTabs />
      {adding ? (
        <form className="card" onSubmit={save}>
          <div className="toolbar">
            <h1 className="grow">Create purchase order</h1>
            <button type="button" className="btn ghost" onClick={fillLowStock}>
              Fill with low-stock medicines
            </button>
            <button type="button" className="btn ghost" onClick={() => setAdding(false)}>
              Cancel
            </button>
          </div>
          <div className="grid four">
            <label className="field">
              <span className="field-label">Stockist *</span>
              <select value={stockistId} onChange={(e) => setStockistId(e.target.value)}>
                <option value="">Select stockist</option>
                {stockists.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </select>
            </label>
            <label className="field">
              <span className="field-label">Order date *</span>
              <input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
            </label>
            <label className="field span-2">
              <span className="field-label">Remarks</span>
              <input value={remarks} onChange={(e) => setRemarks(e.target.value)} />
            </label>
          </div>
          <h3>Medicines to order</h3>
          <div className="rx">
            <div className="po-row rx-head">
              <span>Medicine</span>
              <span>Units per pack</span>
              <span>Number of packs</span>
              <span />
            </div>
            {lines.map((l, i) => (
              <div className="po-row" key={i}>
                <select value={l.medicine_id} onChange={(e) => setLine(i, 'medicine_id', e.target.value)} aria-label={`Medicine ${i + 1}`}>
                  <option value="">Select medicine</option>
                  {medicines.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.name} ({m.units_in_stock} in stock)
                    </option>
                  ))}
                </select>
                <input type="number" min={1} value={l.units_per_pack} onChange={(e) => setLine(i, 'units_per_pack', e.target.value)} aria-label="Units per pack" />
                <input type="number" min={1} value={l.packs} onChange={(e) => setLine(i, 'packs', e.target.value)} aria-label="Packs" />
                <button type="button" className="link danger" onClick={() => setLines((rows) => (rows.length > 1 ? rows.filter((_, r) => r !== i) : [emptyLine()]))}>
                  Remove
                </button>
              </div>
            ))}
          </div>
          <button type="button" className="link add-medicine" onClick={() => setLines((rows) => [...rows, emptyLine()])}>
            + Add another medicine
          </button>
          {error && <p className="form-error">{error}</p>}
          <div className="panel-foot">
            <button className="btn primary" disabled={filled.length === 0 || !stockistId}>
              Save order
            </button>
          </div>
        </form>
      ) : (
        <div className="split">
          <section className="card no-print">
            <div className="toolbar">
              <h1 className="grow">Purchase orders</h1>
              {canEdit && (
                <button className="btn primary" onClick={start}>
                  + Create order
                </button>
              )}
            </div>
            {notice && <p className="notice">{notice}</p>}
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Order</th>
                    <th>Stockist</th>
                    <th>Date</th>
                    <th className="num">Medicines</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {orders.map((o) => (
                    <tr key={o.id} onClick={() => view(o)} className={viewing?.id === o.id ? 'selected' : ''}>
                      <td className="mono">
                        <strong>{o.order_no}</strong>
                      </td>
                      <td>{o.stockist.name}</td>
                      <td>{longDate(o.order_date)}</td>
                      <td className="num">{o.items_count}</td>
                      <td>
                        <span className={`pill ${STATUS_CLASS[o.status]}`}>{STATUS[o.status]}</span>
                      </td>
                    </tr>
                  ))}
                  {orders.length === 0 && (
                    <tr className="empty">
                      <td colSpan={5}>No purchase orders yet.</td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </section>
          {viewing && (
            <aside className="card panel wide print-area">
              <div className="panel-head">
                <div>
                  <h2>Purchase order {viewing.order_no}</h2>
                  <span className="muted">
                    To {viewing.stockist.name} · {longDate(viewing.order_date)} · <span className={`pill ${STATUS_CLASS[viewing.status]}`}>{STATUS[viewing.status]}</span>
                  </span>
                </div>
                <div className="row no-print">
                  <button className="btn ghost" onClick={() => window.print()}>
                    Print
                  </button>
                  <button className="btn ghost" onClick={() => setViewing(null)}>
                    Close
                  </button>
                </div>
              </div>
              <p className="print-only">From {user?.branch?.name ?? 'Prasanthi Hospitals'} Pharmacy{user?.branch?.city ? `, ${user.branch.city}` : ''}</p>
              <table className="compact">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Medicine</th>
                    <th className="num">Units per pack</th>
                    <th className="num">Packs</th>
                  </tr>
                </thead>
                <tbody>
                  {viewing.items?.map((item, i) => (
                    <tr key={item.id} className="static">
                      <td>{i + 1}</td>
                      <td>{item.medicine_name}</td>
                      <td className="num">{item.units_per_pack}</td>
                      <td className="num">
                        {viewing.status === 'draft' && canEdit ? (
                          <input className="packs no-print" type="number" min="0" value={packs[item.id] ?? ''} onChange={(e) => setPacks((all) => ({ ...all, [item.id]: e.target.value }))} aria-label={`Packs of ${item.medicine_name}`} />
                        ) : (
                          item.packs
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {viewing.match && viewing.match.invoices.length > 0 && (
                <>
                  <h3>What came against this order</h3>
                  <p className="muted small">
                    {viewing.match.invoices.map((inv) => `Invoice ${inv.invoice_no} of ${longDate(inv.invoice_date)}`).join(', ')}.
                  </p>
                  <table className="compact">
                    <thead>
                      <tr>
                        <th>Medicine</th>
                        <th className="num">Ordered</th>
                        <th className="num">Received</th>
                        <th className="num">Free</th>
                        <th />
                      </tr>
                    </thead>
                    <tbody>
                      {viewing.match.lines.map((l) => (
                        <tr key={l.medicine_id} className="static">
                          <td>{l.medicine_name}</td>
                          <td className="num">{l.ordered}</td>
                          <td className="num">{l.received}</td>
                          <td className="num">{l.free || '—'}</td>
                          <td>
                            {l.state === 'full' ? (
                              <span className="pill ok">In full</span>
                            ) : l.state === 'none' ? (
                              <span className="pill off">Not received</span>
                            ) : l.state === 'short' ? (
                              <span className="pill warn">{l.ordered - l.received} short</span>
                            ) : (
                              <span className="pill warn">{l.received - l.ordered} more than ordered</span>
                            )}
                          </td>
                        </tr>
                      ))}
                      {viewing.match.extras.map((x) => (
                        <tr key={x.medicine_name} className="static">
                          <td>{x.medicine_name}</td>
                          <td className="num">—</td>
                          <td className="num">{x.received}</td>
                          <td className="num">{x.free || '—'}</td>
                          <td>
                            <span className="pill warn">Not ordered</span>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </>
              )}
              {viewing.remarks && <p className="muted">{viewing.remarks}</p>}
              {viewing.expected_on && viewing.status === 'open' && <p className="muted small">Expected by {longDate(viewing.expected_on)}.</p>}
              {viewError && <p className="form-error">{viewError}</p>}
              {canEdit && viewing.status === 'draft' && (
                <>
                  <p className="muted small no-print">This order was suggested from stock levels and sales. Correct the packs if needed (0 drops a line), then approve it. Nothing has been ordered yet.</p>
                  <div className="panel-foot row no-print">
                    <button className="btn ghost" onClick={() => setStatus('cancelled')}>
                      Drop this order
                    </button>
                    <button className="btn primary" onClick={() => setStatus('open')}>
                      Approve order
                    </button>
                  </div>
                </>
              )}
              {canEdit && viewing.status === 'open' && (
                <div className="panel-foot row no-print">
                  <button className="btn ghost" onClick={() => setStatus('cancelled')}>
                    Cancel order
                  </button>
                  <button className="btn primary" onClick={() => setStatus('received')}>
                    Mark received
                  </button>
                </div>
              )}
              {viewing.status === 'open' && <p className="muted small no-print">Stock comes in through the stockist's invoice: under Invoices, choose this order in "Against purchase order". The order closes by itself once every line has come in full; mark it received here only to close it short.</p>}
            </aside>
          )}
        </div>
      )}
    </>
  )
}
