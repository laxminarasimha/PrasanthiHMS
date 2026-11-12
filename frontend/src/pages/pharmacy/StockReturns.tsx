import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../../api'
import { useAuth } from '../../auth'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { expiryLabel, longDate, rupees, today } from '../../format'
import type { StockBatch, StockReturn, Stockist } from '../../types'

/** Stock sent back to a stockist: expired, damaged or excess. */
export function PharmacyStockReturns() {
  const { user } = useAuth()
  const [returns, setReturns] = useState<StockReturn[]>([])
  const [adding, setAdding] = useState(false)
  const [viewing, setViewing] = useState<StockReturn | null>(null)
  const [stockists, setStockists] = useState<Stockist[]>([])
  const [batches, setBatches] = useState<StockBatch[]>([])
  const [stockistId, setStockistId] = useState('')
  const [date, setDate] = useState(today())
  const [remarks, setRemarks] = useState('')
  const [filter, setFilter] = useState('')
  const [quantities, setQuantities] = useState<Record<number, string>>({})
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const load = useCallback(() => {
    api.get<StockReturn[]>('/pharmacy/stock-returns').then((res) => setReturns(res.data))
  }, [])
  useEffect(load, [load])

  const start = () => {
    api.get<Stockist[]>('/pharmacy/stockists').then((res) => setStockists(res.data))
    api.get<StockBatch[]>('/pharmacy/stock').then((res) => setBatches(res.data))
    setAdding(true)
    setViewing(null)
    setQuantities({})
    setError('')
    setNotice('')
  }

  const chosen = Object.entries(quantities).filter(([, q]) => Number(q) > 0)
  const shown = batches.filter((b) => `${b.medicine?.name} ${b.batch_no}`.toLowerCase().includes(filter.trim().toLowerCase()))

  const save = async (e: FormEvent) => {
    e.preventDefault()
    try {
      const res = await api.post<StockReturn>('/pharmacy/stock-returns', {
        stockist_id: stockistId || null, returned_on: date, remarks: remarks || null,
        items: chosen.map(([id, q]) => ({ stock_batch_id: Number(id), quantity: Number(q) })),
      })
      setNotice(`Return ${res.data.return_no} saved: ${rupees(res.data.total)} to be credited by ${res.data.stockist.name}.`)
      setAdding(false)
      setRemarks('')
      load()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    }
  }

  return (
    <>
      <PharmacyTabs />
      {adding ? (
        <form className="card" onSubmit={save}>
          <div className="toolbar">
            <h1 className="grow">Return stock to a stockist</h1>
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
              <span className="field-label">Returned on *</span>
              <input type="date" value={date} max={today()} onChange={(e) => setDate(e.target.value)} />
            </label>
            <label className="field span-2">
              <span className="field-label">Remarks</span>
              <input placeholder="Expired, damaged, excess…" value={remarks} onChange={(e) => setRemarks(e.target.value)} />
            </label>
          </div>
          <h3>Batches to return</h3>
          <input type="search" placeholder="Filter by medicine or batch" value={filter} onChange={(e) => setFilter(e.target.value)} />
          <div className="table-wrap gap-top tall">
            <table className="compact">
              <thead>
                <tr>
                  <th>Medicine</th>
                  <th>Batch</th>
                  <th>Expiry</th>
                  <th>Invoice</th>
                  <th className="num">In stock</th>
                  <th className="num">Units to return</th>
                </tr>
              </thead>
              <tbody>
                {shown.map((b) => (
                  <tr key={b.id} className="static">
                    <td>{b.medicine?.name}</td>
                    <td>{b.batch_no}</td>
                    <td>{expiryLabel(b.expiry)}</td>
                    <td>{b.invoice?.invoice_no ?? 'Opening stock'}</td>
                    <td className="num">{b.units_in_stock}</td>
                    <td className="num">
                      <input className="qty" type="number" min={0} max={b.units_in_stock} value={quantities[b.id] ?? ''} onChange={(e) => setQuantities((q) => ({ ...q, [b.id]: e.target.value }))} aria-label={`Units of ${b.medicine?.name} to return`} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {error && <p className="form-error">{error}</p>}
          <div className="panel-foot between">
            <span>{chosen.length} batch(es) chosen</span>
            <button className="btn primary" disabled={chosen.length === 0 || !stockistId}>
              Save return
            </button>
          </div>
        </form>
      ) : (
        <div className="split">
          <section className="card">
            <div className="toolbar">
              <h1 className="grow">Stock returns</h1>
              {user?.can_run_pharmacy && (
                <button className="btn primary" onClick={start}>
                  + Return stock
                </button>
              )}
            </div>
            {notice && <p className="notice">{notice}</p>}
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Return</th>
                    <th>Stockist</th>
                    <th>Date</th>
                    <th className="num">Lines</th>
                    <th className="num">Credit value</th>
                    <th>Remarks</th>
                  </tr>
                </thead>
                <tbody>
                  {returns.map((r) => (
                    <tr key={r.id} onClick={() => setViewing(r)} className={viewing?.id === r.id ? 'selected' : ''}>
                      <td className="mono">
                        <strong>{r.return_no}</strong>
                      </td>
                      <td>{r.stockist.name}</td>
                      <td>{longDate(r.returned_on)}</td>
                      <td className="num">{r.items.length}</td>
                      <td className="num">{rupees(r.total)}</td>
                      <td>{r.remarks ?? '—'}</td>
                    </tr>
                  ))}
                  {returns.length === 0 && (
                    <tr className="empty">
                      <td colSpan={6}>Nothing has been returned to a stockist yet.</td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </section>
          {viewing && (
            <aside className="card panel wide">
              <div className="panel-head">
                <h2>Return {viewing.return_no}</h2>
                <button className="btn ghost" onClick={() => setViewing(null)}>
                  Close
                </button>
              </div>
              <table className="compact">
                <thead>
                  <tr>
                    <th>Medicine</th>
                    <th>Batch</th>
                    <th className="num">Units</th>
                    <th className="num">Rate</th>
                    <th className="num">Value</th>
                  </tr>
                </thead>
                <tbody>
                  {viewing.items.map((i) => (
                    <tr key={i.id} className="static">
                      <td>{i.medicine_name}</td>
                      <td>{i.batch_no}</td>
                      <td className="num">{i.quantity}</td>
                      <td className="num">{rupees(i.unit_cost)}</td>
                      <td className="num">{rupees(i.amount)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <dl className="totals">
                <dt className="strong">Credit value (cost + GST)</dt>
                <dd className="strong">{rupees(viewing.total)}</dd>
              </dl>
            </aside>
          )}
        </div>
      )}
    </>
  )
}
