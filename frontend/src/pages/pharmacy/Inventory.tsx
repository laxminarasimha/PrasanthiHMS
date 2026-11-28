import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useLoadOnChange } from '../../loading'
import { api } from '../../api'
import { useAuth } from '../../auth'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { daysUntil, downloadCsv, expiryLabel, placeLabel, rupees, today } from '../../format'
import type { StockBatch } from '../../types'
import { AddStockPopup, DetailsPopup, QuantityPopup } from './StockEdits'
import { MedicineLink } from '../../components/MedicineLink'

type SortBy = 'medicine' | 'batch' | 'expiry' | 'units'
const text = (a: string, b: string) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' })
/** How two batches compare under each heading. Batches of one medicine stay together, the first to expire on top. */
const ORDER: Record<SortBy, (a: StockBatch, b: StockBatch) => number> = {
  medicine: (a, b) => text(a.medicine?.name ?? '', b.medicine?.name ?? '') || text(a.expiry, b.expiry),
  batch: (a, b) => text(a.batch_no, b.batch_no),
  expiry: (a, b) => text(a.expiry, b.expiry),
  units: (a, b) => a.units_in_stock - b.units_in_stock,
}

type Tab = 'all' | 'expiring' | 'low'
/** A medicine is low when what can still be sold of it, over all its batches, is at or under its low-stock level. */
const isLow = (b: StockBatch) => (b.medicine_units ?? 0) > 0 && (b.medicine_units ?? 0) <= (b.medicine?.reorder_level ?? 0)
const SHOWN: Record<Tab, (b: StockBatch) => boolean> = {
  all: () => true,
  expiring: (b) => b.units_in_stock > 0 && daysUntil(b.expiry) <= 90,
  low: isLow,
}

/**
 * Everything on the shelf, batch by batch, with the lists a pharmacist checks before ordering (expiring, low) and the
 * changes made from here: a batch's quantity, its details, and stock that came without an invoice.
 */
export function PharmacyInventory() {
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
  const [tab, setTab] = useState<Tab>('all')
  // Medicines with nothing on the shelf are not listed here; the page only says how many there are.
  const [zero, setZero] = useState(0)
  useEffect(() => {
    api.get<{ counts: { zero: number } }>('/pharmacy/inventory').then((res) => setZero(res.data.counts.zero))
  }, [])

  const load = useCallback(() => {
    api.get<StockBatch[]>('/pharmacy/stock', { params: { q: query.trim() || undefined, show_empty: showEmpty ? 1 : undefined } }).then((res) => setBatches(res.data))
  }, [query, showEmpty])

  // At once when the screen opens; after a short pause when a search or filter changes.
  useLoadOnChange(load)

  const rows = useMemo(() => {
    const sorted = (batches ?? []).filter(SHOWN[tab]).sort(ORDER[sort.by])
    return sort.down ? sorted.reverse() : sorted
  }, [batches, sort, tab])
  const stocked = batches?.filter((b) => b.units_in_stock > 0) ?? []
  const medicines = new Set(stocked.map((b) => b.medicine_id)).size
  const lowCount = new Set((batches ?? []).filter(isLow).map((b) => b.medicine_id)).size

  const expired = stocked.filter((b) => daysUntil(b.expiry) < 0).length
  const expiring = stocked.filter(SHOWN.expiring).length
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
      `inventory-${tab}-${today()}.csv`,
      ['Medicine', 'Composition', 'Type', 'Manufacturer', 'Batch', 'Expiry', 'Units in stock', 'Units received', 'Medicine total', 'Low-stock level', 'Units per pack', 'Pack price', 'Pack MRP', 'Unit MRP', 'GST %', 'Invoice', 'Rack', 'Box'],
      rows.map((b) => [b.medicine?.name, b.medicine?.composition, b.medicine?.type, b.medicine?.manufacturer, b.batch_no, b.expiry, b.units_in_stock, b.units_received, b.medicine_units, b.medicine?.reorder_level, b.units_per_pack, b.pack_price, b.pack_mrp, b.unit_mrp, b.gst_percent, b.invoice?.invoice_no ?? 'Opening stock', b.rack_no, b.box_no]),
    )

  return (
    <>
      <PharmacyTabs />
      <div>
        <section className="card">
          <div className="toolbar">
            <h1>Inventory</h1>
            <input className="search" type="search" placeholder="Filter by medicine, batch or the drug inside" value={query} onChange={(e) => setQuery(e.target.value)} />
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
            <strong className="loads">{medicines}</strong> {medicines === 1 ? 'medicine' : 'medicines'} in <strong className="loads">{stocked.length}</strong> {stocked.length === 1 ? 'batch' : 'batches'} · value at MRP{' '}
            <strong className="loads">{rupees(Math.round(value))}</strong>
            {expired > 0 && <> · <span className="pill off">{expired} expired</span></>}
            {/* What is not on the shelf is not inventory: it lives in the medicines list, with where to order it. */}
            {zero > 0 && (
              <>
                {' '}· {zero} {zero === 1 ? 'medicine has' : 'medicines have'} nothing on the shelf:{' '}
                <Link className="link" to="/pharmacy/medicines?view=options&show=out">
                  see them, with where to order
                </Link>
              </>
            )}
          </p>
          <div className="tabs">
            {(
              [
                ['all', 'All stock', null],
                ['expiring', 'Expiring in 90 days', expiring],
                ['low', 'Low stock', lowCount],
              ] as const
            ).map(([key, label, count]) => (
              <button key={key} className={tab === key ? 'active' : ''} onClick={() => setTab(key)}>
                {label}
                {count !== null && <span>{count}</span>}
              </button>
            ))}
          </div>
          {cut && <p className="form-error">Only the first 3,000 batches are shown, so this total and the export are not the whole stock. Type a medicine name to narrow the list.</p>}
          {notice && <p className="notice">{notice}</p>}
          <div className="table-wrap">
            {/* Names and what they contain wrap, and the type is a size smaller, so the whole row fits the screen. */}
            <table className="inventory-table">
              <thead>
                <tr>
                  {heading('medicine', 'Medicine')}
                  {heading('batch', 'Batch and invoice')}
                  {heading('expiry', 'Expiry')}
                  {heading('units', 'Units in stock', true)}
                  <th className="num col-price">Pack price</th>
                  <th className="num">MRP</th>
                  <th className="num col-gst">GST %</th>
                  {canEdit && <th className="stock-actions">Change</th>}
                </tr>
              </thead>
              <tbody>
                {rows.map((b) => {
                  const days = daysUntil(b.expiry)
                  return (
                    <tr key={b.id} className={`static ${b.units_in_stock === 0 ? 'faded' : ''} ${editing?.batch.id === b.id ? 'selected' : ''}`}>
                      <td>
                        <strong>
                          <MedicineLink id={b.medicine?.id ?? b.medicine_id}>{b.medicine?.name}</MedicineLink>
                        </strong>
                        {/* The form, unless the name already says it ("… 650mg Tablet"). */}
                        {b.medicine?.type && !b.medicine.name.toLowerCase().includes(b.medicine.type.toLowerCase()) && <small className="muted"> {b.medicine.type}</small>}
                        {b.medicine?.composition && <small className="muted block">{b.medicine.composition}</small>}
                        {placeLabel(b.rack_no, b.box_no) && <small className="shelf-place block">{placeLabel(b.rack_no, b.box_no)}</small>}
                        {/* On a phone the last column is a long way to the right, so the two changes also sit under the name. */}
                        {canEdit && <span className="stock-actions under-name">{changes(b)}</span>}
                      </td>
                      <td className="batch">
                        {b.batch_no}
                        <small className="muted block">{b.invoice?.invoice_no ?? 'Opening stock'}</small>
                      </td>
                      <td className="expiry">
                        {expiryLabel(b.expiry)}{' '}
                        {days < 0 ? <span className="pill off">Expired</span> : days <= 90 ? <span className="pill status-arrived">{days} days</span> : null}
                      </td>
                      <td className="num">
                        <strong>{b.units_in_stock}</strong>
                        <small className="muted"> / {b.units_received}</small>
                        {/* The medicine as a whole, when it has other batches or is running low. */}
                        {b.medicine_units !== undefined && (isLow(b) || b.medicine_units !== b.units_in_stock) && (
                          <small className="muted block">
                            {b.medicine_units} in all{' '}
                            {isLow(b) && (
                              <span className="pill off" title={`Low-stock level ${b.medicine?.reorder_level}`}>
                                Low
                              </span>
                            )}
                          </small>
                        )}
                      </td>
                      <td className="num col-price">
                        {rupees(b.pack_price)}
                        {b.units_per_pack > 1 && <small className="muted block">pack of {b.units_per_pack}</small>}
                      </td>
                      <td className="num">
                        {rupees(b.pack_mrp)}
                        {b.units_per_pack > 1 && <small className="muted block">{rupees(b.unit_mrp)} a unit</small>}
                      </td>
                      <td className="num col-gst">{b.gst_percent}</td>
                      {canEdit && <td className="stock-actions">{changes(b)}</td>}
                    </tr>
                  )
                })}
                {batches && rows.length === 0 && (
                  <tr className="empty">
                    <td colSpan={canEdit ? 8 : 7}>
                      {batches.length === 0 ? 'No stock found. Stock comes in through purchase invoices or with “Add stock”.' : tab === 'low' ? 'Nothing is running low.' : 'Nothing expires in the next 90 days.'}
                    </td>
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
