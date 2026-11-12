import { useCallback, useEffect, useState } from 'react'
import { api } from '../../api'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { daysUntil, expiryLabel, rupees } from '../../format'
import type { StockBatch } from '../../types'

export function PharmacyStock() {
  const [query, setQuery] = useState('')
  const [showEmpty, setShowEmpty] = useState(false)
  const [batches, setBatches] = useState<StockBatch[] | null>(null)

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

  return (
    <>
      <PharmacyTabs />
      <section className="card">
        <div className="toolbar">
          <h1>Pharmacy stock</h1>
          <input className="search" type="search" placeholder="Filter by medicine or batch" value={query} onChange={(e) => setQuery(e.target.value)} />
          <label className="check flush">
            <input type="checkbox" checked={showEmpty} onChange={(e) => setShowEmpty(e.target.checked)} />
            Show empty batches
          </label>
          <span className="muted">
            Stock value at MRP <strong>{rupees(Math.round(value))}</strong>
            {expiring > 0 && <> · <span className="pill status-arrived">{expiring} expiring in 90 days</span></>}
            {expired > 0 && <> · <span className="pill off">{expired} expired</span></>}
          </span>
        </div>
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
                <th>Rack</th>
              </tr>
            </thead>
            <tbody>
              {batches?.map((b) => {
                const days = daysUntil(b.expiry)
                return (
                  <tr key={b.id} className={`static ${b.units_in_stock === 0 ? 'faded' : ''}`}>
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
                    <td>{b.invoice?.invoice_no ?? '—'}</td>
                    <td>{b.rack_no ?? '—'}</td>
                  </tr>
                )
              })}
              {batches && batches.length === 0 && (
                <tr className="empty">
                  <td colSpan={11}>No stock found. Stock comes in through purchase invoices.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
    </>
  )
}
