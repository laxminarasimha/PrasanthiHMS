import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors } from '../../api'
import { useAuth } from '../../auth'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { longDate, rupees } from '../../format'

type Reorder = {
  medicine_id: number
  name: string
  units_per_pack: number
  class: 'out' | 'urgent' | 'low'
  usable: number
  near_expiry: number
  on_order: number
  sold_30: number
  days_cover: number | null
  minimum: number
  desired: number
  lead_days: number
  suggest_packs: number
  stockist_id: number | null
  stockist: string | null
  last_pack_price: number | null
}
type Batch = { batch_id: number; medicine: string; batch_no: string; expiry: string; days_left: number; units: number; value: number; likely_left: number; stockist: string | null }
type Bill = { id: number; invoice_no: string; invoice_date: string; stockist: string; owed: number; total: number; due_date: string; days_overdue: number }
type Ageing = { stockist_id: number; stockist: string; invoices: number; owed: number; returns_credit: number; net: number; not_due: number; d1_30: number; d31_60: number; d61_90: number; over_90: number }
type Order = { id: number; order_no: string; stockist: string; status: 'draft' | 'open'; order_date: string; expected_on: string; days_late: number; items: number }
type Data = {
  today: string
  actions: { key: string; tone: 'danger' | 'warn' | 'note'; count: number; text: string }[]
  reorder: Reorder[]
  expiring: Batch[]
  expired: Batch[]
  payables: { invoices: Bill[]; suppliers: Ageing[]; totals: Record<string, number> }
  unmatched: { orders: Order[]; invoices: { id: number; invoice_no: string; invoice_date: string; stockist: string; total: number }[] }
  slow: { medicine_id: number; medicine: string; units: number; value: number; next_expiry: string }[]
  stockists: { id: number; name: string; lead_days: number; credit_days: number }[]
  rules: Record<string, number>
}
type Statement = {
  stockist: { name: string; phone: string | null; gstin: string | null; credit_days: number }
  from: string
  to: string
  opening: number
  lines: { date: string; kind: string; text: string; billed: number; paid: number; balance: number }[]
  billed: number
  paid: number
  closing: number
}
type Pick = { on: boolean; packs: string; stockist: string }

const STATE = { out: ['Out of stock', 'off'], urgent: ['Running out', 'off'], low: ['Low', 'warn'] } as const
const BUCKETS: [keyof Ageing, string][] = [['not_due', 'Not yet due'], ['d1_30', '1–30 days late'], ['d31_60', '31–60'], ['d61_90', '61–90'], ['over_90', 'Over 90']]
const n = (value: number) => value.toLocaleString('en-IN')

/** The pharmacy manager's morning screen: what to order, what is expiring, which bills are due, what does not match. */
export function PharmacyManager() {
  const { user } = useAuth()
  const [data, setData] = useState<Data | null>(null)
  const [picks, setPicks] = useState<Record<number, Pick>>({})
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [supplier, setSupplier] = useState('')
  const [statement, setStatement] = useState<Statement | null>(null)

  const load = useCallback(() => {
    api.get<Data>('/pharmacy/manager').then((res) => {
      setData(res.data)
      // Ticked to begin with: whatever is out or running out and not already on order.
      setPicks(Object.fromEntries(res.data.reorder.map((r) => [r.medicine_id, { on: r.class !== 'low' && r.suggest_packs > 0, packs: String(r.suggest_packs || ''), stockist: r.stockist_id ? String(r.stockist_id) : '' }])))
    })
  }, [])
  useEffect(load, [load])

  useEffect(() => {
    if (!supplier) return
    api.get<Statement>(`/pharmacy/stockists/${supplier}/statement`).then((res) => setStatement(res.data))
  }, [supplier])

  const setPick = (id: number, change: Partial<Pick>) => setPicks((all) => ({ ...all, [id]: { ...all[id], ...change } }))
  const chosen = data?.reorder.filter((r) => picks[r.medicine_id]?.on) ?? []
  const estimate = chosen.reduce((sum, r) => sum + (r.last_pack_price ?? 0) * Number(picks[r.medicine_id].packs || 0), 0)

  const makeOrders = () => {
    setBusy(true)
    setError('')
    setNotice('')
    api
      .post<{ orders: { order_no: string; stockist: string; items: number }[] }>('/pharmacy/manager/orders', {
        lines: chosen.map((r) => ({ medicine_id: r.medicine_id, stockist_id: picks[r.medicine_id].stockist ? Number(picks[r.medicine_id].stockist) : null, packs: Number(picks[r.medicine_id].packs || 0) })),
      })
      .then((res) => {
        setNotice(`Draft orders made: ${res.data.orders.map((o) => `${o.order_no} to ${o.stockist} (${o.items} ${o.items === 1 ? 'medicine' : 'medicines'})`).join(', ')}. Open Purchase orders to check, approve and print them.`)
        load()
      })
      .catch((e) => setError(Object.values(fieldErrors(e))[0] ?? 'Could not make the orders.'))
      .finally(() => setBusy(false))
  }

  const showStatement = (id: number) => {
    setStatement(null)
    setSupplier(String(id))
    document.getElementById('statement')?.scrollIntoView({ behavior: 'smooth' })
  }

  const batchTable = (rows: Batch[], expired: boolean) => (
    <div className="table-wrap tall">
      <table className="compact">
        <thead>
          <tr>
            <th>Medicine</th>
            <th>Batch</th>
            <th>Expiry</th>
            <th className="num">{expired ? 'Expired' : 'Days left'}</th>
            <th className="num">Units</th>
            <th className="num">Worth at cost</th>
            {!expired && <th className="num">Likely unsold</th>}
            <th>Bought from</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((b) => (
            <tr key={b.batch_id} className="static">
              <td>{b.medicine}</td>
              <td>{b.batch_no}</td>
              <td>{longDate(b.expiry).slice(3)}</td>
              <td className={`num ${expired || b.days_left <= 30 ? 'due' : ''}`}>{expired ? `${-b.days_left} days ago` : b.days_left}</td>
              <td className="num">{n(b.units)}</td>
              <td className="num">{rupees(b.value)}</td>
              {!expired && <td className={`num ${b.likely_left > 0 ? 'due' : 'settled'}`}>{b.likely_left > 0 ? n(b.likely_left) : 'None'}</td>}
              <td>{b.stockist ?? '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )

  return (
    <>
      <PharmacyTabs />
      <section className="card">
        <div className="toolbar">
          <h1>Pharmacy manager</h1>
          {data && <span className="muted">{longDate(data.today)}</span>}
          <span className="grow" />
          <button className="btn ghost no-print" onClick={() => window.print()}>
            Print
          </button>
        </div>
        {!data && <p className="loading">Loading…</p>}

        {data && (
          <>
            <h3 className="flush">To do today</h3>
            {data.actions.length === 0 ? (
              <p className="notice">Nothing needs doing: stock, expiry and supplier bills are all in order.</p>
            ) : (
              <ul className="todo">
                {data.actions.map((a, i) => (
                  <li key={i} className={a.tone}>
                    {a.key === 'stock' ? (
                      <Link to="/pharmacy/opening-stock">
                        <strong>{a.count}</strong> {a.text}
                      </Link>
                    ) : (
                      <a href={`#${a.key}`}>
                        <strong>{a.count}</strong> {a.text}
                      </a>
                    )}
                  </li>
                ))}
              </ul>
            )}

            <div className="day-section" id="order">
              <h3>Medicines to order ({data.reorder.length})</h3>
              {notice && <p className="notice">{notice}</p>}
              {data.reorder.length === 0 ? (
                <p className="day-clear">
                  <span aria-hidden="true">✓</span> Nothing needs ordering.
                </p>
              ) : (
                <>
                  <div className="table-wrap tall">
                    <table className="compact">
                      <thead>
                        <tr>
                          <th className="tick no-print" />
                          <th>Medicine</th>
                          <th>State</th>
                          <th className="num">On shelf</th>
                          <th className="num">Sold in 30 days</th>
                          <th className="num">On order</th>
                          <th className="num">Minimum → fill to</th>
                          <th className="num">Order (packs)</th>
                          <th>Supplier</th>
                        </tr>
                      </thead>
                      <tbody>
                        {data.reorder.map((r) => {
                          const pick = picks[r.medicine_id] ?? { on: false, packs: '', stockist: '' }
                          return (
                            <tr key={r.medicine_id} className="static">
                              <td className="tick no-print">
                                <input type="checkbox" checked={pick.on} onChange={(e) => setPick(r.medicine_id, { on: e.target.checked })} aria-label={`Order ${r.name}`} />
                              </td>
                              <td>
                                {r.name}
                                <small className="block muted">Pack of {r.units_per_pack}</small>
                              </td>
                              <td>
                                <span className={`pill ${STATE[r.class][1]}`}>{STATE[r.class][0]}</span>
                                {r.class === 'urgent' && r.days_cover !== null && (
                                  <small className="block muted">
                                    {r.days_cover} {r.days_cover === 1 ? 'day' : 'days'} left, delivery takes {r.lead_days}
                                  </small>
                                )}
                              </td>
                              <td className="num">
                                {n(r.usable)}
                                {r.near_expiry > 0 && <small className="block due">+{n(r.near_expiry)} expiring soon</small>}
                              </td>
                              <td className="num">{n(r.sold_30)}</td>
                              <td className="num">{r.on_order ? n(r.on_order) : '—'}</td>
                              <td className="num">
                                {n(r.minimum)} → {n(r.desired)}
                              </td>
                              <td className="num">
                                <input className="packs" type="number" min="0" value={pick.packs} onChange={(e) => setPick(r.medicine_id, { packs: e.target.value, on: Number(e.target.value) > 0 })} aria-label={`Packs of ${r.name}`} />
                                {r.suggest_packs === 0 && <small className="block muted">Already on order</small>}
                              </td>
                              <td>
                                <select value={pick.stockist} onChange={(e) => setPick(r.medicine_id, { stockist: e.target.value })} aria-label={`Supplier for ${r.name}`}>
                                  <option value="">Choose supplier</option>
                                  {data.stockists.map((s) => (
                                    <option key={s.id} value={s.id}>
                                      {s.name}
                                    </option>
                                  ))}
                                </select>
                              </td>
                            </tr>
                          )
                        })}
                      </tbody>
                    </table>
                  </div>
                  {error && <p className="form-error">{error}</p>}
                  <div className="toolbar order-foot no-print">
                    <span className="grow">
                      {chosen.length} ticked{estimate > 0 && <> · about {rupees(Math.round(estimate))} at the last purchase rates</>}
                    </span>
                    <button className="btn primary" disabled={busy || chosen.length === 0 || !user?.can_run_pharmacy} onClick={makeOrders}>
                      Make draft orders
                    </button>
                  </div>
                  <details className="how no-print">
                    <summary>How these figures are worked out</summary>
                    <p className="muted small">
                      Sales of the last {data.rules.sales_days} days give each medicine's daily rate. Its minimum is the level set on the medicine, or else enough for the supplier's delivery time plus {data.rules.safety_days} days. An order fills
                      the shelf up to the "fill to" level set on the medicine, or else the minimum plus {data.rules.cover_days} days of sales. Stock that expires within {data.rules.protect_days} days is not counted, because it may not
                      sell in time; what is already on order is. A medicine is "running out" when what is left will not last until a delivery can arrive. Set the minimum, the fill-to level and the usual supplier under Medicines, and each
                      supplier's delivery days under Stockists.
                    </p>
                  </details>
                </>
              )}
            </div>

            <div className="day-section" id="expiry">
              <h3>Expiring stock</h3>
              {data.expired.length > 0 && (
                <>
                  <p className="form-error">{data.expired.length} expired {data.expired.length === 1 ? 'batch is' : 'batches are'} still on the shelf, worth {rupees(data.expired.reduce((s, b) => s + b.value, 0))} at cost. They cannot be sold.</p>
                  {batchTable(data.expired, true)}
                </>
              )}
              {data.expiring.length === 0 ? (
                <p className="day-clear">
                  <span aria-hidden="true">✓</span> Nothing expires in the next {data.rules.expiry_watch_days} days.
                </p>
              ) : (
                <>
                  <p className="muted small">
                    Expiring within {data.rules.expiry_watch_days} days: {data.expiring.length} batches, worth {rupees(data.expiring.reduce((s, b) => s + b.value, 0))} at cost. "Likely unsold" is what will be left on the expiry day at the present
                    rate of sale. Billing always offers the batch that expires first.
                  </p>
                  {batchTable(data.expiring, false)}
                </>
              )}
            </div>

            <div className="day-section" id="pay">
              <h3>Supplier bills</h3>
              {data.payables.suppliers.length === 0 ? (
                <p className="day-clear">
                  <span aria-hidden="true">✓</span> Nothing is owed to suppliers.
                </p>
              ) : (
                <>
                  <div className="table-wrap">
                    <table className="compact">
                      <thead>
                        <tr>
                          <th>Supplier</th>
                          {BUCKETS.map(([, label]) => (
                            <th key={label} className="num">
                              {label}
                            </th>
                          ))}
                          <th className="num">Owed on invoices</th>
                          <th className="num">Credit for returns</th>
                          <th className="num">Net to pay</th>
                          <th className="no-print" />
                        </tr>
                      </thead>
                      <tbody>
                        {data.payables.suppliers.map((s) => (
                          <tr key={s.stockist_id} className="static">
                            <td>{s.stockist}</td>
                            {BUCKETS.map(([key]) => (
                              <td key={key} className={`num ${key !== 'not_due' && Number(s[key]) > 0 ? 'due' : ''}`}>
                                {Number(s[key]) ? rupees(Number(s[key])) : '—'}
                              </td>
                            ))}
                            <td className="num strong">{rupees(s.owed)}</td>
                            <td className="num">{s.returns_credit ? rupees(s.returns_credit) : '—'}</td>
                            <td className="num strong">{rupees(s.net)}</td>
                            <td className="no-print">
                              <button className="link" onClick={() => showStatement(s.stockist_id)}>
                                Statement
                              </button>
                            </td>
                          </tr>
                        ))}
                        <tr className="static">
                          <td>
                            <strong>All suppliers</strong>
                          </td>
                          {BUCKETS.map(([key]) => (
                            <td key={key} className="num strong">
                              {rupees(data.payables.totals[key as string] ?? 0)}
                            </td>
                          ))}
                          <td className="num strong">{rupees(data.payables.totals.owed)}</td>
                          <td className="num strong">{rupees(data.payables.totals.returns_credit)}</td>
                          <td className="num strong">{rupees(data.payables.totals.owed - data.payables.totals.returns_credit)}</td>
                          <td className="no-print" />
                        </tr>
                      </tbody>
                    </table>
                  </div>
                  <h3>Unpaid invoices, oldest due date first</h3>
                  <div className="table-wrap tall">
                    <table className="compact">
                      <thead>
                        <tr>
                          <th>Invoice</th>
                          <th>Supplier</th>
                          <th>Invoice date</th>
                          <th>Due</th>
                          <th />
                          <th className="num">Owed</th>
                        </tr>
                      </thead>
                      <tbody>
                        {[...data.payables.invoices]
                          .sort((a, b) => a.due_date.localeCompare(b.due_date))
                          .map((b) => (
                            <tr key={b.id} className="static">
                              <td>
                                <Link to="/pharmacy/purchases">{b.invoice_no}</Link>
                              </td>
                              <td>{b.stockist}</td>
                              <td>{longDate(b.invoice_date)}</td>
                              <td>{longDate(b.due_date)}</td>
                              <td>
                                {b.days_overdue > 0 ? (
                                  <span className="pill off">{b.days_overdue} days late</span>
                                ) : b.days_overdue > -8 ? (
                                  <span className="pill warn">{b.days_overdue === 0 ? 'Due today' : `Due in ${-b.days_overdue} days`}</span>
                                ) : null}
                              </td>
                              <td className="num">
                                {rupees(b.owed)}
                                {b.owed < b.total && <small className="block muted">of {rupees(b.total)}</small>}
                              </td>
                            </tr>
                          ))}
                      </tbody>
                    </table>
                  </div>
                  <p className="muted small no-print">Record a payment by opening the invoice under Invoices. Set each supplier's credit days under Stockists so due dates come out right.</p>
                </>
              )}
            </div>

            <div className="day-section" id="match">
              <h3>Orders and invoices to match</h3>
              {data.unmatched.orders.length === 0 && data.unmatched.invoices.length === 0 && (
                <p className="day-clear">
                  <span aria-hidden="true">✓</span> No order is waiting or late, and no recent invoice came without an order.
                </p>
              )}
              {data.unmatched.orders.length > 0 && (
                <div className="table-wrap">
                  <table className="compact">
                    <thead>
                      <tr>
                        <th>Order</th>
                        <th>Supplier</th>
                        <th>Ordered</th>
                        <th>Expected</th>
                        <th className="num">Medicines</th>
                        <th />
                      </tr>
                    </thead>
                    <tbody>
                      {data.unmatched.orders.map((o) => (
                        <tr key={o.id} className="static">
                          <td>
                            <Link to="/pharmacy/purchase-orders">{o.order_no}</Link>
                          </td>
                          <td>{o.stockist}</td>
                          <td>{longDate(o.order_date)}</td>
                          <td>{longDate(o.expected_on)}</td>
                          <td className="num">{o.items}</td>
                          <td>{o.status === 'draft' ? <span className="pill warn">Waiting for approval</span> : <span className="pill off">{o.days_late} days late</span>}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
              {data.unmatched.invoices.length > 0 && (
                <>
                  <p className="muted small">Invoices of the last 14 days that were entered without a purchase order:</p>
                  <ul className="plain-list">
                    {data.unmatched.invoices.map((i) => (
                      <li key={i.id}>
                        <Link to="/pharmacy/purchases">{i.invoice_no}</Link> · {i.stockist} · {longDate(i.invoice_date)} · {rupees(i.total)}
                      </li>
                    ))}
                  </ul>
                </>
              )}
            </div>

            <div className="day-section">
              <h3>Not selling</h3>
              {data.slow.length === 0 ? (
                <p className="day-clear">
                  <span aria-hidden="true">✓</span> No stock has sat for {data.rules.slow_days} days without a sale.
                </p>
              ) : (
                <>
                  <p className="muted small">
                    On the shelf for more than {data.rules.slow_days} days with no sale: {data.slow.length} medicines, worth {rupees(data.slow.reduce((s, m) => s + m.value, 0))} at cost. Do not reorder these; return what the supplier will take back.
                  </p>
                  <div className="table-wrap tall">
                    <table className="compact">
                      <thead>
                        <tr>
                          <th>Medicine</th>
                          <th className="num">Units</th>
                          <th className="num">Worth at cost</th>
                          <th>First expiry</th>
                        </tr>
                      </thead>
                      <tbody>
                        {data.slow.map((m) => (
                          <tr key={m.medicine_id} className="static">
                            <td>{m.medicine}</td>
                            <td className="num">{n(m.units)}</td>
                            <td className="num">{rupees(m.value)}</td>
                            <td>{longDate(m.next_expiry).slice(3)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </>
              )}
            </div>

            <div className="day-section" id="statement">
              <h3>Supplier statement</h3>
              <div className="toolbar no-print">
                <select
                  className="narrow"
                  value={supplier}
                  onChange={(e) => {
                    setStatement(null)
                    setSupplier(e.target.value)
                  }}
                  aria-label="Supplier"
                >
                  <option value="">Choose a supplier</option>
                  {data.stockists.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name}
                    </option>
                  ))}
                </select>
                <span className="muted small">The last three months, to compare with the supplier's own statement.</span>
              </div>
              {supplier && statement && (
                <>
                  <p>
                    <strong>{statement.stockist.name}</strong>
                    {statement.stockist.gstin && ` · GSTIN ${statement.stockist.gstin}`} · {longDate(statement.from)} to {longDate(statement.to)} · credit {statement.stockist.credit_days} days
                  </p>
                  <div className="table-wrap">
                    <table className="compact">
                      <thead>
                        <tr>
                          <th>Date</th>
                          <th>What</th>
                          <th className="num">Billed</th>
                          <th className="num">Paid or credited</th>
                          <th className="num">Balance</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr className="static">
                          <td />
                          <td>Balance brought forward</td>
                          <td />
                          <td />
                          <td className="num">{rupees(statement.opening)}</td>
                        </tr>
                        {statement.lines.map((l, i) => (
                          <tr key={i} className="static">
                            <td>{longDate(l.date)}</td>
                            <td>{l.text}</td>
                            <td className="num">{l.billed ? rupees(l.billed) : ''}</td>
                            <td className="num">{l.paid ? rupees(l.paid) : ''}</td>
                            <td className="num">{rupees(l.balance)}</td>
                          </tr>
                        ))}
                        <tr className="static">
                          <td />
                          <td>
                            <strong>We owe</strong>
                          </td>
                          <td className="num strong">{rupees(statement.billed)}</td>
                          <td className="num strong">{rupees(statement.paid)}</td>
                          <td className="num strong">{rupees(statement.closing)}</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </>
              )}
            </div>
          </>
        )}
      </section>
    </>
  )
}
