import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, fieldErrors } from '../../api'
import { useAuth } from '../../auth'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { Popup } from '../../components/Popup'
import { PAYMENT_MODES, daysUntil, downloadCsv, longDate, rupees, today } from '../../format'
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
                  <td className="fit-wrap">
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

/**
 * One payment to the stockist against several bills. The amount typed is shared out over the ticked bills, the
 * oldest first, and the table shows what each bill gets before anything is saved.
 */
function PayBills({ stockist, bills, only, onClose, onPaid }: { stockist: Stockist; bills: Bill[]; only: number | null; onClose: () => void; onPaid: (message: string) => void }) {
  // Oldest first: that is the order a stockist expects their bills to be cleared in.
  const [owed] = useState(() => [...bills].sort((a, b) => a.invoice_date.localeCompare(b.invoice_date) || a.id - b.id))
  const [ticked, setTicked] = useState<number[]>(() => (only ? [only] : owed.map((b) => b.id)))
  const due = (ids: number[]) => two(owed.filter((b) => ids.includes(b.id)).reduce((sum, b) => sum + left(b), 0))
  const [amount, setAmount] = useState(() => String(due(only ? [only] : owed.map((b) => b.id))))
  const [mode, setMode] = useState('cash')
  const [reference, setReference] = useState('')
  const [paidOn, setPaidOn] = useState(today())
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  const tickedDue = due(ticked)
  const paying = Number(amount) > 0 ? two(Number(amount)) : 0
  const tooMuch = paying > tickedDue + 0.004
  // What each ticked bill gets, the oldest filled first.
  const shares = new Map<number, number>()
  let rest = Math.min(paying, tickedDue)
  for (const b of owed) {
    if (!ticked.includes(b.id) || rest <= 0.004) continue
    const share = two(Math.min(left(b), rest))
    shares.set(b.id, share)
    rest = two(rest - share)
  }
  const ready = paying > 0 && !tooMuch && shares.size > 0 && paidOn !== '' && !saving

  const tick = (id: number, on: boolean) => {
    const next = on ? [...ticked, id] : ticked.filter((x) => x !== id)
    setTicked(next)
    // The amount follows the ticks, so "pay these bills in full" is the starting point each time.
    setAmount(String(due(next)))
  }

  const pay = async (e: FormEvent) => {
    e.preventDefault()
    if (!ready) return
    setSaving(true)
    setError('')
    try {
      const res = await api.post<{ paid: number; bills: number }>(`/pharmacy/stockists/${stockist.id}/payments`, {
        payments: [...shares].map(([invoice_id, share]) => ({ invoice_id, amount: share })), mode, reference: reference.trim() || null, paid_on: paidOn,
      })
      onPaid(`Paid ${rupees(res.data.paid)} to ${stockist.name} against ${res.data.bills} ${res.data.bills === 1 ? 'bill' : 'bills'}.`)
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'The payment could not be saved. Please try again.')
      setSaving(false)
    }
  }

  return (
    <Popup
      title="Pay bills"
      sub={`${stockist.name} · ${owed.length} ${owed.length === 1 ? 'bill' : 'bills'} not fully paid · ${rupees(due(owed.map((b) => b.id)))} owed`}
      size="wide"
      onClose={onClose}
      onSubmit={pay}
      foot={
        <>
          <button type="button" className="btn ghost" onClick={onClose}>
            Cancel
          </button>
          <button className="btn primary" disabled={!ready}>
            {saving ? 'Saving…' : `Pay ${rupees(Math.min(paying, tickedDue))}`}
          </button>
        </>
      }
    >
      <div className="grid four">
        <label className="field">
          <span className="field-label">Amount paid ₹</span>
          <input type="number" min={0} step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} onFocus={(e) => e.target.select()} />
        </label>
        <label className="field">
          <span className="field-label">How it was paid</span>
          <select value={mode} onChange={(e) => setMode(e.target.value)}>
            {PAYMENT_MODES.filter((m) => m.value !== 'insurance').map((m) => (
              <option key={m.value} value={m.value}>
                {m.label}
              </option>
            ))}
          </select>
        </label>
        <label className="field">
          <span className="field-label">Cheque or transfer no.</span>
          <input maxLength={255} value={reference} onChange={(e) => setReference(e.target.value)} />
        </label>
        <label className="field">
          <span className="field-label">Paid on</span>
          <input type="date" max={today()} value={paidOn} onChange={(e) => setPaidOn(e.target.value)} />
        </label>
      </div>
      <p className={`small gap-top ${tooMuch ? 'form-error' : 'muted'}`}>
        {tooMuch
          ? `That is more than the ${rupees(tickedDue)} owed on the ticked bills.`
          : 'The amount is shared over the ticked bills, the oldest first. Untick a bill to leave it out, or type a smaller amount for a part payment.'}
      </p>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th />
              <th>Invoice</th>
              <th>Date</th>
              <th className="num">Owed</th>
              <th className="num">Paying now</th>
              <th className="num">Left after</th>
            </tr>
          </thead>
          <tbody>
            {owed.map((b) => {
              const share = shares.get(b.id) ?? 0
              return (
                <tr key={b.id} className={`static ${ticked.includes(b.id) ? '' : 'faded'}`}>
                  <td>
                    <input type="checkbox" checked={ticked.includes(b.id)} onChange={(e) => tick(b.id, e.target.checked)} aria-label={`Pay invoice ${b.invoice_no}`} />
                  </td>
                  <td>
                    <strong>{b.invoice_no}</strong>
                  </td>
                  <td>{longDate(b.invoice_date)}</td>
                  <td className="num">{rupees(left(b))}</td>
                  <td className="num">{share > 0 ? <strong>{rupees(share)}</strong> : '—'}</td>
                  <td className={`num ${two(left(b) - share) > 0.004 ? 'due' : 'settled'}`}>{rupees(two(left(b) - share))}</td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      {error && <p className="form-error">{error}</p>}
    </Popup>
  )
}

/** One stockist's own page: every bill from them, what was paid on each, and what is still owed. */
export function PharmacyStockist() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const [page, setPage] = useState<Page | null>(null)
  const [missing, setMissing] = useState(false)
  const [show, setShow] = useState<Show>('all')
  const [query, setQuery] = useState('')
  // The payment window: for every unpaid bill, or opened from one bill's own "Pay".
  const [paying, setPaying] = useState<{ only: number | null } | null>(null)
  const [notice, setNotice] = useState('')
  const canPay = Boolean(user?.can_run_pharmacy)

  const load = useCallback(() => {
    api.get<Page>(`/pharmacy/stockists/${id}`).then((res) => setPage(res.data)).catch(() => setMissing(true))
  }, [id])
  useEffect(load, [load])

  const unpaid = useMemo(() => (page?.invoices ?? []).filter((b) => left(b) > 0.004), [page])
  const term = query.trim().toLowerCase()
  const rows = (show === 'unpaid' ? unpaid : page?.invoices ?? []).filter((b) => b.invoice_no.toLowerCase().includes(term))
  const sum = (key: 'total' | 'paid') => two(rows.reduce((s, b) => s + b[key], 0))
  const s = page?.stockist

  const status = (b: Bill) => {
    if (left(b) <= 0.004) return <span className="pill ok">Paid</span>
    return (
      <>
        {dueTag(b)}
        {canPay && (
          <button
            type="button"
            className="link pay-link"
            aria-label={`Pay invoice ${b.invoice_no}`}
            onClick={(e) => {
              e.stopPropagation()
              setNotice('')
              setPaying({ only: b.id })
            }}
          >
            Pay
          </button>
        )}
      </>
    )
  }
  const dueTag = (b: Bill) => {
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
          {canPay && unpaid.length > 0 && (
            <button className="btn primary" onClick={() => { setNotice(''); setPaying({ only: null }) }}>
              Pay bills
            </button>
          )}
          {canPay && s && (
            <Link className="btn ghost" to={`/pharmacy/purchases?add=${s.id}`}>
              + Add invoice
            </Link>
          )}
          <button className="btn ghost" onClick={exportCsv} disabled={rows.length === 0}>
            Export
          </button>
        </div>

        {notice && <p className="notice">{notice}</p>}
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
                  <td className="fit-wrap">
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
                      <td className="wrap">{r.remarks ?? '—'}</td>
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
      {paying && s && (
        <PayBills
          stockist={s}
          bills={unpaid}
          only={paying.only}
          onClose={() => setPaying(null)}
          onPaid={(message) => {
            setPaying(null)
            setNotice(message)
            load()
          }}
        />
      )}
    </>
  )
}
