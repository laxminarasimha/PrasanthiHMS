import { useCallback, useMemo, useState } from 'react'
import { useLoadOnChange } from '../../loading'
import { api } from '../../api'
import { useAuth } from '../../auth'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { daysUntil, downloadCsv, expiryLabel, placeLabel, rupees, today } from '../../format'
import type { StockBatch } from '../../types'
import { AddStockPopup, DetailsPopup, QuantityPopup } from './StockEdits'

type SortBy = 'medicine' | 'batch' | 'expiry' | 'units' | 'invoice'
const text = (a: string, b: string) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' })
/** How two batches compare under each heading. Batches of one medicine stay together, the first to expire on top. */
const ORDER: Record<SortBy, (a: StockBatch, b: StockBatch) => number> = {
  medicine: (a, b) => text(a.medicine?.name ?? '', b.medicine?.name ?? '') || text(a.expiry, b.expiry),
  batch: (a, b) => text(a.batch_no, b.batch_no),
  expiry: (a, b) => text(a.expiry, b.expiry),
  units: (a, b) => a.units_in_stock - b.units_in_stock,
  invoice: (a, b) => text(a.invoice?.invoice_no ?? '', b.invoice?.invoice_no ?? ''),
}

export function PharmacyStock() {
  const { user } = useAuth()
  const canEdit = Boolean(user?.can_run_pharmacy)
  const [query, setQuery] = useState('')
  const [showEmpty, setShowEmpty] = useState(false)
  const [batches, setBatches] = useState<StockBatch[] | null>(null)
  // The one small window open over the list: a batch's quantity, a batch's details, or new stock.
  const [editing, setEditing] = useState<{ batch: StockBatch; what: 'quantity' | 'details' } | null>(null)
  const [adding, setAdding] = useState(false)
  const [sort, setSort] = useState<{ by: SortBy; down: boolean }>({ by: 'medicine', down: false })
  const [notice, setNotice] = useState('')

  const load = useCallback(() => {
    api.get<StockBatch[]>('/pharmacy/stock', { params: { q: query.trim() || undefined, show_empty: showEmpty ? 1 : undefined } }).then((res) => setBatches(res.data))
  }, [query, showEmpty])

  // At once when the screen opens; after a short pause when a search or filter changes.
  useLoadOnChange(load)

  const rows = useMemo(() => {
    const sorted = [...(batches ?? [])].sort(ORDER[sort.by])
    return sort.down ? sorted.reverse() : sorted
  }, [batches, sort])

  const expired = batches?.filter((b) => b.units_in_stock > 0 && daysUntil(b.expiry) < 0).length ?? 0
  const expiring = batches?.filter((b) => b.units_in_stock > 0 && daysUntil(b.expiry) >= 0 && daysUntil(b.expiry) <= 90).length ?? 0
  const value = batches?.reduce((sum, b) => sum + b.units_in_stock * b.unit_mrp, 0) ?? 0
  // The list stops at 3,000 batches. Beyond that the total and the export would be short, and the page says so.
  const cut = (batches?.length ?? 0) >= 3000

  const open = (batch: StockBatch, what: 'quantity' | 'details') => {
    setNotice('')
    setEditing({ batch, what })
  }
  const done = (message: string) => {
    setNotice(message)
    setEditing(null)
    load()
  }

  const changes = (b: StockBatch) => (
    <>
      <button className="link" onClick={() => open(b, 'quantity')} aria-label={`Change the quantity of ${b.medicine?.name} batch ${b.batch_no}`}>
        Quantity
      </button>
      <button className="link" onClick={() => open(b, 'details')} aria-label={`Edit the details of ${b.medicine?.name} batch ${b.batch_no}`}>
        Details
      </button>
    </>
  )

  const heading = (by: SortBy, label: string, num = false) => (
    <th className={`sortable ${num ? 'num' : ''}`} aria-sort={sort.by === by ? (sort.down ? 'descending' : 'ascending') : undefined}>
      <button type="button" title={`Sort by ${label.toLowerCase()}`} onClick={() => setSort((s) => ({ by, down: s.by === by ? !s.down : false }))}>
        {label}
        <span aria-hidden="true">{sort.by === by ? (sort.down ? ' ▼' : ' ▲') : ''}</span>
      </button>
    </th>
  )

  const exportCsv = () =>
    downloadCsv(
      `pharmacy-stock-${today()}.csv`,
      ['Medicine', 'Batch', 'Expiry', 'Units in stock', 'Units received', 'Units per pack', 'Pack price', 'Pack MRP', 'Unit MRP', 'GST %', 'Invoice', 'Rack', 'Box'],
      rows.map((b) => [b.medicine?.name, b.batch_no, b.expiry, b.units_in_stock, b.units_received, b.units_per_pack, b.pack_price, b.pack_mrp, b.unit_mrp, b.gst_percent, b.invoice?.invoice_no ?? 'Opening stock', b.rack_no, b.box_no]),
    )

  return (
    <>
      <PharmacyTabs />
      <div>
        <section className="card">
          <div className="toolbar">
            <h1>Stock by batch</h1>
            <input className="search" type="search" placeholder="Filter by medicine or batch" value={query} onChange={(e) => setQuery(e.target.value)} />
            <label className="check flush">
              <input type="checkbox" checked={showEmpty} onChange={(e) => setShowEmpty(e.target.checked)} />
              Show empty batches
            </label>
            {canEdit && (
              <button className="btn primary" onClick={() => { setNotice(''); setAdding(true) }}>
                + Add stock
              </button>
            )}
            <button className="btn ghost" onClick={exportCsv}>
              Export
            </button>
          </div>
          <p className="muted small">
            Stock value at MRP <strong className="loads">{rupees(Math.round(value))}</strong>
            {expiring > 0 && <> · <button type="button" className="pill status-arrived as-button" title="Show the first to expire on top" onClick={() => setSort({ by: 'expiry', down: false })}>{expiring} expiring in 90 days</button></>}
            {expired > 0 && <> · <button type="button" className="pill off as-button" title="Show the first to expire on top" onClick={() => setSort({ by: 'expiry', down: false })}>{expired} expired</button></>}
          </p>
          {cut && <p className="form-error">Only the first 3,000 batches are shown, so this total and the export are not the whole stock. Type a medicine name to narrow the list.</p>}
          {notice && <p className="notice">{notice}</p>}
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  {heading('medicine', 'Medicine')}
                  {heading('batch', 'Batch')}
                  {heading('expiry', 'Expiry')}
                  {heading('units', 'Units in stock', true)}
                  <th className="num">Per pack</th>
                  <th className="num">Pack price</th>
                  <th className="num">MRP</th>
                  <th className="num">GST %</th>
                  {heading('invoice', 'Invoice')}
                  {canEdit && <th className="stock-actions">Change</th>}
                </tr>
              </thead>
              <tbody>
                {rows.map((b) => {
                  const days = daysUntil(b.expiry)
                  return (
                    <tr key={b.id} className={`static ${b.units_in_stock === 0 ? 'faded' : ''} ${editing?.batch.id === b.id ? 'selected' : ''}`}>
                      <td>
                        <strong>{b.medicine?.name}</strong>
                        {placeLabel(b.rack_no, b.box_no) && <small className="shelf-place block">{placeLabel(b.rack_no, b.box_no)}</small>}
                        {/* On a phone the last column is a long way to the right, so the two changes also sit under the name. */}
                        {canEdit && <span className="stock-actions under-name">{changes(b)}</span>}
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
                      <td className="num">
                        {rupees(b.pack_mrp)}
                        {b.units_per_pack > 1 && <small className="muted block">{rupees(b.unit_mrp)} a unit</small>}
                      </td>
                      <td className="num">{b.gst_percent}</td>
                      <td>{b.invoice?.invoice_no ?? 'Opening stock'}</td>
                      {canEdit && <td className="stock-actions">{changes(b)}</td>}
                    </tr>
                  )
                })}
                {batches && batches.length === 0 && (
                  <tr className="empty">
                    <td colSpan={canEdit ? 10 : 9}>No stock found. Stock comes in through purchase invoices or opening stock.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </section>

        {editing?.what === 'quantity' && <QuantityPopup key={editing.batch.id} batch={editing.batch} onClose={() => setEditing(null)} onDone={done} />}
        {editing?.what === 'details' && <DetailsPopup key={editing.batch.id} batch={editing.batch} onClose={() => setEditing(null)} onDone={done} />}
        {adding && (
          <AddStockPopup
            onClose={() => setAdding(false)}
            onAdded={(message) => {
              setNotice(message)
              load()
            }}
          />
        )}
      </div>
    </>
  )
}
