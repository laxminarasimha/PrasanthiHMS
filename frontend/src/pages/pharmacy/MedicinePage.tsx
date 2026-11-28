import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../../api'
import { MedicineLink } from '../../components/MedicineLink'
import { PatientLink } from '../../components/PatientLink'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { clock, longDate, placeLabel, rupees } from '../../format'
import { StockistLink } from '../../components/RecordLinks'

type Page = {
  medicine: {
    id: number; name: string; composition: string | null; type: string | null; manufacturer: string | null; category: string | null; is_scheduled: boolean
    hsn_code: string | null; gst_percent: number | null; units_per_pack: number | null; reorder_level: number | null; desired_stock: number | null; is_active: boolean
    rack_no: string | null; box_no: string | null; drugs: { id: number; name: string; strength: string | null }[]
  }
  on_shelf: number
  batches: { id: number; batch_no: string; expiry: string | null; units: number; rack_no: string | null; box_no: string | null; unit_mrp: number; invoice_id: number | null; invoice_no: string | null }[]
  purchases: { invoice_id: number; invoice_no: string; on: string | null; stockist_id: number; stockist: string | null; batch_no: string; packs: number; free_packs: number; pack_price: number; pack_mrp: number }[]
  suppliers: { id: number; name: string; times: number; last_on: string | null; last_rate: number }[]
  sales: { month: { bills: number; units: number; amount: number }; recent: { sale_id: number; bill_no: string; on: string; customer: string | null; patient_id: number | null; units: number; amount: number }[] }
  alternatives: { id: number; name: string; manufacturer: string | null; on_shelf: number }[]
  offers: { id: number; name: string; company: string | null; pack: string | null; mrp: number | null; rate: number | null; scheme: string | null; ours: boolean; reps: { name: string; mobile: string | null }[] }[]
}

const expiresSoon = (d: string | null) => Boolean(d) && new Date(d!).getTime() - Date.parse(new Date().toDateString()) < 30 * 86400000

/** One medicine on one page, opened from its name anywhere in the pharmacy. */
export function MedicinePage() {
  const { id } = useParams()
  const [page, setPage] = useState<Page | null>(null)
  const [failed, setFailed] = useState('')

  useEffect(() => {
    api
      .get<Page>(`/pharmacy/medicines/${id}/page`)
      .then((res) => {
        setPage(res.data)
        setFailed('')
      })
      .catch(() => setFailed('Could not open this medicine.'))
  }, [id])

  const m = page?.medicine
  const place = m ? placeLabel(m.rack_no, m.box_no) : ''

  return (
    <>
      <PharmacyTabs />
      {failed && <p className="form-error">{failed}</p>}
      {!page && !failed && <p className="loading">Loading medicine…</p>}
      {page && m && (
        <>
          <section className="card">
            <div className="toolbar">
              <h1>
                {m.name} {!m.is_active && <span className="pill off">Not sold now</span>} {m.is_scheduled && <span className="pill status-arrived">Scheduled</span>}
              </h1>
              <span className="grow" />
              <Link className="btn ghost" to={`/pharmacy/audit?tab=history&medicine=${m.id}`}>
                Stock history
              </Link>
              <Link className="btn ghost" to={`/pharmacy/medicines?q=${encodeURIComponent(m.name)}&edit=${m.id}`}>
                Edit
              </Link>
            </div>
            <p className="muted">
              {[m.type, m.manufacturer, m.category, m.units_per_pack ? `${m.units_per_pack} in a pack` : null, m.hsn_code ? `HSN ${m.hsn_code}` : null, m.gst_percent !== null ? `GST ${m.gst_percent}%` : null]
                .filter(Boolean)
                .join(' · ')}
            </p>
            <p>
              <strong>Contains: </strong>
              {m.drugs.length > 0 ? m.drugs.map((d) => `${d.name}${d.strength ? ` ${d.strength}` : ''}`).join(' + ') : m.composition || <span className="muted">Not written down yet</span>}
            </p>
            <div className="tiles small">
              <div className="tile">
                <span>On the shelf</span>
                <strong className={page.on_shelf === 0 ? 'due' : ''}>{page.on_shelf}</strong>
              </div>
              <div className="tile">
                <span>Kept at</span>
                <strong>{place || '—'}</strong>
              </div>
              <div className="tile">
                <span>Sold in 30 days</span>
                <strong>
                  {page.sales.month.units} <small className="muted">on {page.sales.month.bills} bills</small>
                </strong>
              </div>
              <div className="tile">
                <span>Order when below</span>
                <strong>{m.reorder_level ?? '—'}</strong>
              </div>
            </div>
          </section>

          <section className="card">
            <h2>On the shelf, by batch</h2>
            <div className="table-wrap">
              <table className="compact">
                <thead>
                  <tr>
                    <th>Batch</th>
                    <th>Expiry</th>
                    <th>Where</th>
                    <th className="num">Units</th>
                    <th className="num">MRP</th>
                    <th>Bought on</th>
                  </tr>
                </thead>
                <tbody>
                  {page.batches.map((b) => (
                    <tr key={b.id} className="static">
                      <td className="mono">{b.batch_no}</td>
                      <td className={expiresSoon(b.expiry) ? 'due' : ''}>{b.expiry ? longDate(b.expiry) : '—'}</td>
                      <td>{placeLabel(b.rack_no, b.box_no) || '—'}</td>
                      <td className="num">{b.units}</td>
                      <td className="num">{rupees(b.unit_mrp)}</td>
                      <td>{b.invoice_id ? <Link to={`/pharmacy/purchases?invoice=${b.invoice_id}`}>{b.invoice_no ?? 'Invoice'}</Link> : <span className="muted">Opening stock</span>}</td>
                    </tr>
                  ))}
                  {page.batches.length === 0 && (
                    <tr className="empty">
                      <td colSpan={6}>Nothing on the shelf.</td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </section>

          <div className="split even">
            <section className="card">
              <h2>Bought from</h2>
              {page.suppliers.length === 0 ? (
                <p className="muted">Not bought on an invoice yet.</p>
              ) : (
                <ul className="plain-list">
                  {page.suppliers.map((s) => (
                    <li key={s.id}>
                      <Link to={`/pharmacy/stockists/${s.id}`}>{s.name}</Link>
                      <small className="muted">
                        {' '}
                        · {s.times} invoice{s.times === 1 ? '' : 's'} · last {s.last_on ? longDate(s.last_on) : ''} at {rupees(s.last_rate)} a pack
                      </small>
                    </li>
                  ))}
                </ul>
              )}
              {page.purchases.length > 0 && (
                <>
                  <h3>Recent purchases</h3>
                  <ul className="plain-list small">
                    {page.purchases.map((p, i) => (
                      <li key={i}>
                        <Link to={`/pharmacy/purchases?invoice=${p.invoice_id}`}>{p.invoice_no}</Link>{' '}
                        <span className="muted">
                          {p.on ? longDate(p.on) : ''} · <StockistLink id={p.stockist_id}>{p.stockist}</StockistLink> · {p.packs}
                          {p.free_packs ? ` + ${p.free_packs} free` : ''} pack{p.packs === 1 ? '' : 's'} at {rupees(p.pack_price)} (MRP {rupees(p.pack_mrp)})
                        </span>
                      </li>
                    ))}
                  </ul>
                </>
              )}
            </section>

            <section className="card">
              <h2>Recent sales</h2>
              {page.sales.recent.length === 0 ? (
                <p className="muted">Not sold yet.</p>
              ) : (
                <ul className="plain-list small">
                  {page.sales.recent.map((s, i) => (
                    <li key={i}>
                      <Link to={`/pharmacy/bills?open=${s.sale_id}`}>{s.bill_no}</Link>{' '}
                      <span className="muted">
                        {longDate(s.on)} {clock(s.on)} ·{' '}
                      </span>
                      <PatientLink id={s.patient_id}>{s.customer ?? 'Walk-in'}</PatientLink>
                      <span className="muted">
                        {' '}
                        · {s.units} · {rupees(s.amount)}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          </div>

          <div className="split even">
            <section className="card">
              <h2>Other brands with the same contents</h2>
              {!m.composition ? (
                <p className="muted">Write down what this medicine contains to see the pharmacy’s other brands of it.</p>
              ) : page.alternatives.length === 0 ? (
                <p className="muted">No other brand in the pharmacy has exactly these contents written down.</p>
              ) : (
                <ul className="plain-list">
                  {page.alternatives.map((a) => (
                    <li key={a.id}>
                      <MedicineLink id={a.id}>{a.name}</MedicineLink>
                      <small className="muted">
                        {a.manufacturer ? ` · ${a.manufacturer}` : ''} · {a.on_shelf} on the shelf
                      </small>
                    </li>
                  ))}
                </ul>
              )}
            </section>

            <section className="card">
              <h2>Offered by medical reps</h2>
              {page.offers.length === 0 ? (
                <p className="muted">No rep’s catalogue has this brand or these contents.</p>
              ) : (
                <ul className="plain-list">
                  {page.offers.map((o) => (
                    <li key={o.id}>
                      <strong>{o.name}</strong>
                      <small className="muted">
                        {o.company ? ` · ${o.company}` : ''}
                        {o.pack ? ` · ${o.pack}` : ''}
                        {o.rate !== null ? ` · we get it at ${rupees(o.rate)}` : ''}
                        {o.mrp !== null ? ` · MRP ${rupees(o.mrp)}` : ''}
                        {o.scheme ? ` · ${o.scheme}` : ''}
                      </small>
                      {o.reps.length > 0 && (
                        <small className="block muted">
                          {o.reps.map((r, i) => (
                            <span key={i}>
                              {i > 0 ? ', ' : ''}
                              {r.name}
                              {r.mobile ? (
                                <>
                                  {' '}
                                  <a href={`tel:${r.mobile}`}>{r.mobile}</a>
                                </>
                              ) : null}
                            </span>
                          ))}
                        </small>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </section>
          </div>
        </>
      )}
    </>
  )
}
