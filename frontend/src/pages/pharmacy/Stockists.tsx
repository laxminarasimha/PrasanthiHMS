import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api } from '../../api'
import { useAuth } from '../../auth'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { daysUntil, downloadCsv, longDate, rupees, today } from '../../format'
import type { Stockist } from '../../types'

const two = (n: number) => Math.round(n * 100) / 100

/** Every stockist the pharmacy buys from, with what is owed to each. A row opens that stockist's own page. */
export function PharmacyStockists() {
  const navigate = useNavigate()
  const [stockists, setStockists] = useState<Stockist[] | null>(null)
  const [query, setQuery] = useState('')

  useEffect(() => {
    api.get<Stockist[]>('/pharmacy/stockists').then((res) => setStockists(res.data))
  }, [])

  const term = query.trim().toLowerCase()
  const rows = (stockists ?? []).filter((s) => s.name.toLowerCase().includes(term) || (s.gstin ?? '').toLowerCase().includes(term) || (s.phone ?? '').includes(term))
  const owed = (stockists ?? []).reduce((sum, s) => sum + Math.max(0, s.balance ?? 0), 0)

  return (
    <>
      <PharmacyTabs />
      <section className="card">
        <div className="toolbar">
          <h1>Stockists</h1>
          <input className="search" type="search" placeholder="Filter by name, phone or GSTIN" value={query} onChange={(e) => setQuery(e.target.value)} />
          <span className="muted">
            We owe in all: <strong className={`loads ${owed > 0 ? 'due' : ''}`}>{rupees(two(owed))}</strong>
          </span>
          <Link className="btn ghost" to="/pharmacy/medicines">
            Add or edit stockists
          </Link>
        </div>
        <p className="muted small">Choose a stockist to see every bill from them on one page.</p>
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Stockist</th>
                <th>Phone</th>
                <th>GSTIN</th>
                <th className="num">Bills</th>
                <th className="num">Billed</th>
                <th className="num">Paid</th>
                <th className="num">Returned</th>
                <th className="num">We owe</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((s) => (
                <tr key={s.id} onClick={() => navigate(`/pharmacy/stockists/${s.id}`)}>
                  <td>
                    <Link className="link" to={`/pharmacy/stockists/${s.id}`} onClick={(e) => e.stopPropagation()}>
                      {s.name}
                    </Link>
                  </td>
                  <td className="mono">{s.phone ?? '—'}</td>
                  <td className="mono">{s.gstin ?? '—'}</td>
                  <td className="num">{s.invoices ?? 0}</td>
                  <td className="num">{rupees(s.billed ?? 0)}</td>
                  <td className="num">{rupees(s.paid ?? 0)}</td>
                  <td className="num">{s.returned ? rupees(s.returned) : '—'}</td>
                  <td className={`num ${(s.balance ?? 0) > 0 ? 'due' : 'settled'}`}>{rupees(s.balance ?? 0)}</td>
                </tr>
              ))}
              {stockists && rows.length === 0 && (
                <tr className="empty">
                  <td colSpan={8}>{stockists.length === 0 ? 'No stockists yet.' : 'No stockist matches that.'}</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
    </>
  )
}

type Bill = { id: number; invoice_no: string; invoice_date: string; due_date: string | null; total: number; paid: number; remarks: string | null; batches_count: number }
type Page = {
  stockist: Stockist
  totals: { invoices: number; billed: number; paid: number; returned: number; balance: number }
  invoices: Bill[]
  returns: { id: number; return_no: string; returned_on: string; total: number; remarks: string | null }[]
}
type Show = 'all' | 'unpaid'

const left = (b: Bill) => two(b.total - b.paid)

/** One stockist's own page: every bill from them, what was paid on each, and what is still owed. */
export function PharmacyStockist() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const [page, setPage] = useState<Page | null>(null)
  const [missing, setMissing] = useState(false)
  const [show, setShow] = useState<Show>('all')
  const [query, setQuery] = useState('')

  useEffect(() => {
    api.get<Page>(`/pharmacy/stockists/${id}`).then((res) => setPage(res.data)).catch(() => setMissing(true))
  }, [id])

  const unpaid = useMemo(() => (page?.invoices ?? []).filter((b) => left(b) > 0.004), [page])
  const term = query.trim().toLowerCase()
  const rows = (show === 'unpaid' ? unpaid : page?.invoices ?? []).filter((b) => b.invoice_no.toLowerCase().includes(term))
  const sum = (key: 'total' | 'paid') => two(rows.reduce((s, b) => s + b[key], 0))
  const s = page?.stockist

  const status = (b: Bill) => {
    if (left(b) <= 0.004) return <span className="pill ok">Paid</span>
    if (!b.due_date) return <span className="pill warn">{b.paid > 0 ? 'Part paid' : 'Not paid'}</span>
    const days = daysUntil(b.due_date)
    if (days < 0) return <span className="pill off">Overdue {-days} {days === -1 ? 'day' : 'days'}</span>
    return <span className="pill warn">{b.paid > 0 ? 'Part paid · due' : 'Due'} {days === 0 ? 'today' : longDate(b.due_date)}</span>
  }

  const exportCsv = () =>
    downloadCsv(
      `${(s?.name ?? 'stockist').replace(/[^a-z0-9]+/gi, '-').toLowerCase()}-bills-${today()}.csv`,
      ['Invoice', 'Date', 'Due', 'Medicines', 'Total', 'Paid', 'Balance', 'Remarks'],
      rows.map((b) => [b.invoice_no, b.invoice_date, b.due_date, b.batches_count, b.total, b.paid, left(b), b.remarks]),
    )

  if (missing) {
    return (
      <>
        <PharmacyTabs />
        <section className="card">
          <p className="muted">
            This stockist could not be found.{' '}
            <Link className="link" to="/pharmacy/stockists">
              See all stockists
            </Link>
          </p>
        </section>
      </>
    )
  }

  return (
    <>
      <PharmacyTabs />
      <section className="card">
        <div className="toolbar">
          <div className="grow">
            <Link className="link small" to="/pharmacy/stockists">
              ← All stockists
            </Link>
            <h1 className="loads">{s?.name ?? 'Stockist'}</h1>
            {s && (
              <span className="muted small">
                {[s.phone, s.email, s.gstin && `GSTIN ${s.gstin}`, s.address, `${s.credit_days ?? 0} days of credit`].filter(Boolean).join(' · ')}
              </span>
            )}
          </div>
          {user?.can_run_pharmacy && s && (
            <Link className="btn primary" to={`/pharmacy/purchases?add=${s.id}`}>
              + Add invoice
            </Link>
          )}
          <button className="btn ghost" onClick={exportCsv} disabled={rows.length === 0}>
            Export
          </button>
        </div>

        <div className="tiles">
          {(
            [
              ['Bills', page ? String(page.totals.invoices) : '', ''],
              ['Billed', page ? rupees(page.totals.billed) : '', ''],
              ['Paid', page ? rupees(page.totals.paid) : '', ''],
              ['Returned', page ? rupees(page.totals.returned) : '', ''],
              ['We owe', page ? rupees(page.totals.balance) : '', page && page.totals.balance > 0.004 ? 'due' : 'settled'],
            ] as const
          ).map(([label, value, tone]) => (
            <div className="tile" key={label}>
              <span>{label}</span>
              <strong className={tone}>{value}</strong>
            </div>
          ))}
        </div>

        <div className="toolbar minor">
          <div className="tabs">
            <button className={show === 'all' ? 'active' : ''} onClick={() => setShow('all')}>
              All bills <span>{page?.invoices.length ?? 0}</span>
            </button>
            <button className={show === 'unpaid' ? 'active' : ''} onClick={() => setShow('unpaid')}>
              Not fully paid <span>{unpaid.length}</span>
            </button>
          </div>
          <input className="search" type="search" placeholder="Find an invoice number" value={query} onChange={(e) => setQuery(e.target.value)} />
        </div>

        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Invoice</th>
                <th>Date</th>
                <th className="num">Medicines</th>
                <th className="num">Total</th>
                <th className="num">Paid</th>
                <th className="num">Balance</th>
                <th>Payment</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((b) => (
                <tr key={b.id} onClick={() => navigate(`/pharmacy/purchases?invoice=${b.id}`)} title="Open this invoice">
                  <td>
                    <Link className="link" to={`/pharmacy/purchases?invoice=${b.id}`} onClick={(e) => e.stopPropagation()}>
                      {b.invoice_no}
                    </Link>
                    {b.remarks && <small className="muted block">{b.remarks}</small>}
                  </td>
                  <td>{longDate(b.invoice_date)}</td>
                  <td className="num">{b.batches_count}</td>
                  <td className="num">{rupees(b.total)}</td>
                  <td className="num">{rupees(b.paid)}</td>
                  <td className={`num ${left(b) > 0.004 ? 'due' : ''}`}>{rupees(left(b))}</td>
                  <td>{status(b)}</td>
                </tr>
              ))}
              {page && rows.length === 0 && (
                <tr className="empty">
                  <td colSpan={7}>{page.invoices.length === 0 ? 'No bills from this stockist yet.' : show === 'unpaid' && !term ? 'Every bill from this stockist is paid.' : 'No bill matches that.'}</td>
                </tr>
              )}
            </tbody>
            {rows.length > 1 && (
              <tfoot>
                <tr>
                  <td colSpan={3}>
                    {rows.length} bills{rows.length !== (page?.invoices.length ?? 0) ? ' shown' : ''}
                  </td>
                  <td className="num">{rupees(sum('total'))}</td>
                  <td className="num">{rupees(sum('paid'))}</td>
                  <td className="num">{rupees(two(sum('total') - sum('paid')))}</td>
                  <td />
                </tr>
              </tfoot>
            )}
          </table>
        </div>

        {page && page.returns.length > 0 && (
          <>
            <h3 className="gap-top">Stock sent back to this stockist</h3>
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Credit note</th>
                    <th>Date</th>
                    <th>Remarks</th>
                    <th className="num">Value</th>
                  </tr>
                </thead>
                <tbody>
                  {page.returns.map((r) => (
                    <tr key={r.id} className="static">
                      <td>
                        <strong>{r.return_no}</strong>
                      </td>
                      <td>{longDate(r.returned_on)}</td>
                      <td>{r.remarks ?? '—'}</td>
                      <td className="num">{rupees(r.total)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="muted small">
              Returns are taken off what we owe. The full list is under{' '}
              <Link className="link" to="/pharmacy/stock-returns">
                Purchases → Stock returns
              </Link>
              .
            </p>
          </>
        )}
      </section>
    </>
  )
}
