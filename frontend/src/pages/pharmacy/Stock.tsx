import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../../api'
import { useAuth } from '../../auth'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { daysUntil, downloadCsv, expiryLabel, rupees, today } from '../../format'
import type { StockBatch } from '../../types'

export function PharmacyStock() {
  const { user } = useAuth()
  const canEdit = Boolean(user?.can_run_pharmacy)
  const [query, setQuery] = useState('')
  const [showEmpty, setShowEmpty] = useState(false)
  const [batches, setBatches] = useState<StockBatch[] | null>(null)
  const [adjusting, setAdjusting] = useState<StockBatch | null>(null)
  const [count, setCount] = useState('')
  const [reason, setReason] = useState('')
  const [error, setError] = useState('')
  const [details, setDetails] = useState({ batch_no: '', expiry: '', pack_mrp: '', rack_no: '', reason: '' })
  const [detailsError, setDetailsError] = useState('')
  const [notice, setNotice] = useState('')

  const load = useCallback(() => {
    api.get<StockBatch[]>('/pharmacy/stock', { params: { q: query.trim() || undefined, show_empty: showEmpty ? 1 : undefined } }).then((res) => setBatches(res.data))
  }, [query, showEmpty])

  useEffect(() => {
    const timer = setTimeout(load, 250)
    return () => clearTimeout(timer)
  }, [load])

  const expired = batches?.filter((b) => b.units_in_stock > 0 && daysUntil(b.expiry) < 0).length ?? 0
  const expiring = batches?.filter((b) => b.units_in_stock > 0 && daysUntil(b.expiry) >= 0 && daysUntil(b.expiry) <= 90).length ?? 0
  const value = batches?.reduce((sum, b) => sum + b.units_in_stock * b.unit_mrp, 0) ?? 0

  const startAdjust = (batch: StockBatch) => {
    setAdjusting(batch)
    setCount(String(batch.units_in_stock))
    setReason('')
    setError('')
    setNotice('')
    setDetails({ batch_no: batch.batch_no, expiry: batch.expiry.slice(0, 7), pack_mrp: String(batch.pack_mrp), rack_no: batch.rack_no ?? '', reason: '' })
    setDetailsError('')
  }

  const saveDetails = async (e: FormEvent) => {
    e.preventDefault()
    if (!adjusting) return
    try {
      await api.put(`/pharmacy/stock/${adjusting.id}`, { ...details, rack_no: details.rack_no || null })
      setNotice(`Details of ${adjusting.medicine?.name} batch ${details.batch_no} corrected. The change is recorded in the Stock data update report.`)
      setAdjusting(null)
      load()
    } catch (err) {
      setDetailsError(Object.values(fieldErrors(err))[0])
    }
  }

  const adjust = async (e: FormEvent) => {
    e.preventDefault()
    if (!adjusting) return
    try {
      await api.post(`/pharmacy/stock/${adjusting.id}/adjust`, { units_in_stock: count, reason })
      setNotice(`${adjusting.medicine?.name} batch ${adjusting.batch_no} is now ${count} units.`)
      setAdjusting(null)
      load()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    }
  }

  const exportCsv = () =>
    downloadCsv(
      `pharmacy-stock-${today()}.csv`,
      ['Medicine', 'Batch', 'Expiry', 'Units in stock', 'Units received', 'Units per pack', 'Pack price', 'Pack MRP', 'Unit MRP', 'GST %', 'Invoice', 'Rack'],
      (batches ?? []).map((b) => [b.medicine?.name, b.batch_no, b.expiry, b.units_in_stock, b.units_received, b.units_per_pack, b.pack_price, b.pack_mrp, b.unit_mrp, b.gst_percent, b.invoice?.invoice_no ?? 'Opening stock', b.rack_no]),
    )

  return (
    <>
      <PharmacyTabs />
      <div className="split">
        <section className="card">
          <div className="toolbar">
            <h1>Stock by batch</h1>
            <input className="search" type="search" placeholder="Filter by medicine or batch" value={query} onChange={(e) => setQuery(e.target.value)} />
            <label className="check flush">
              <input type="checkbox" checked={showEmpty} onChange={(e) => setShowEmpty(e.target.checked)} />
              Show empty batches
            </label>
            <button className="btn ghost" onClick={exportCsv}>
              Export
            </button>
          </div>
          <p className="muted small">
            Stock value at MRP <strong>{rupees(Math.round(value))}</strong>
            {expiring > 0 && <> · <span className="pill status-arrived">{expiring} expiring in 90 days</span></>}
            {expired > 0 && <> · <span className="pill off">{expired} expired</span></>}
          </p>
          {notice && <p className="notice">{notice}</p>}
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Medicine</th>
                  <th>Batch</th>
                  <th>Expiry</th>
                  <th className="num">Units in stock</th>
                  <th className="num">Per pack</th>
                  <th className="num">Pack price</th>
                  <th className="num">Pack MRP</th>
                  <th className="num">Unit MRP</th>
                  <th className="num">GST %</th>
                  <th>Invoice</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {batches?.map((b) => {
                  const days = daysUntil(b.expiry)
                  return (
                    <tr key={b.id} className={`static ${b.units_in_stock === 0 ? 'faded' : ''} ${adjusting?.id === b.id ? 'selected' : ''}`}>
                      <td>
                        <strong>{b.medicine?.name}</strong>
                      </td>
                      <td>{b.batch_no}</td>
                      <td>
                        {expiryLabel(b.expiry)}{' '}
                        {days < 0 ? <span className="pill off">Expired</span> : days <= 90 ? <span className="pill status-arrived">{days} days</span> : null}
                      </td>
                      <td className="num">
                        <strong>{b.units_in_stock}</strong>
                        <small className="muted"> / {b.units_received}</small>
                      </td>
                      <td className="num">{b.units_per_pack}</td>
                      <td className="num">{rupees(b.pack_price)}</td>
                      <td className="num">{rupees(b.pack_mrp)}</td>
                      <td className="num">{rupees(b.unit_mrp)}</td>
                      <td className="num">{b.gst_percent}</td>
                      <td>{b.invoice?.invoice_no ?? 'Opening stock'}</td>
                      <td className="num">
                        {canEdit && (
                          <button className="link" onClick={() => startAdjust(b)}>
                            Adjust / edit
                          </button>
                        )}
                      </td>
                    </tr>
                  )
                })}
                {batches && batches.length === 0 && (
                  <tr className="empty">
                    <td colSpan={11}>No stock found. Stock comes in through purchase invoices or opening stock.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </section>

        {adjusting && (
          <div className="card panel">
          <form onSubmit={adjust}>
            <div className="panel-head">
              <h2>Adjust stock</h2>
              <button type="button" className="btn ghost" onClick={() => setAdjusting(null)}>
                Close
              </button>
            </div>
            <p>
              <strong>{adjusting.medicine?.name}</strong>
              <br />
              <span className="muted">
                Batch {adjusting.batch_no} · expires {expiryLabel(adjusting.expiry)} · system count {adjusting.units_in_stock}
              </span>
            </p>
            <label className="field">
              <span className="field-label">Actual units on the shelf</span>
              <input type="number" min={0} value={count} onChange={(e) => setCount(e.target.value)} autoFocus />
            </label>
            <label className="field gap-top">
              <span className="field-label">Reason</span>
              <input placeholder="Damaged, counting error, expired and discarded…" value={reason} onChange={(e) => setReason(e.target.value)} />
            </label>
            {error && <p className="form-error">{error}</p>}
            <div className="panel-foot">
              <button className="btn primary" disabled={count === '' || reason.trim().length < 3}>
                Save new count
              </button>
            </div>
          </form>

          <form onSubmit={saveDetails}>
            <h3>Correct batch details</h3>
            <div className="grid">
              <label className="field">
                <span className="field-label">Batch number</span>
                <input value={details.batch_no} onChange={(e) => setDetails({ ...details, batch_no: e.target.value })} />
              </label>
              <label className="field">
                <span className="field-label">Expiry</span>
                <input type="month" value={details.expiry} onChange={(e) => setDetails({ ...details, expiry: e.target.value })} />
              </label>
              <label className="field">
                <span className="field-label">MRP per pack (₹)</span>
                <input type="number" min={0} step="0.01" value={details.pack_mrp} onChange={(e) => setDetails({ ...details, pack_mrp: e.target.value })} />
              </label>
              <label className="field">
                <span className="field-label">Rack</span>
                <input value={details.rack_no} onChange={(e) => setDetails({ ...details, rack_no: e.target.value })} />
              </label>
              <label className="field span-2">
                <span className="field-label">Reason</span>
                <input placeholder="Label read wrongly, price revised…" value={details.reason} onChange={(e) => setDetails({ ...details, reason: e.target.value })} />
              </label>
            </div>
            {detailsError && <p className="form-error">{detailsError}</p>}
            <div className="panel-foot">
              <button className="btn primary" disabled={!details.batch_no.trim() || !details.expiry || details.reason.trim().length < 3}>
                Save details
              </button>
            </div>
          </form>
          </div>
        )}
      </div>
    </>
  )
}
