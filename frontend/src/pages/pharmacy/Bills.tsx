import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../../api'
import { useAuth } from '../../auth'
import { DateRange } from '../../components/DateRange'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { PAYMENT_MODES, clock, longDate, modeLabel, rupees, today } from '../../format'
import type { PharmacySale } from '../../types'

type Action = 'view' | 'collect' | 'return' | 'delete'

const ModeSelect = ({ value, onChange }: { value: string; onChange: (v: string) => void }) => (
  <select value={value} onChange={(e) => onChange(e.target.value)} aria-label="Mode">
    {PAYMENT_MODES.map((m) => (
      <option key={m.value} value={m.value}>
        {m.label}
      </option>
    ))}
  </select>
)

export function PharmacyBills() {
  const { user } = useAuth()
  const canEdit = Boolean(user?.can_run_pharmacy)
  const [from, setFrom] = useState(today())
  const [to, setTo] = useState(today())
  const [query, setQuery] = useState('')
  const [sales, setSales] = useState<PharmacySale[] | null>(null)
  const [open, setOpen] = useState<PharmacySale | null>(null)
  const [action, setAction] = useState<Action>('view')
  const [amount, setAmount] = useState('')
  const [mode, setMode] = useState('cash')
  const [reason, setReason] = useState('')
  const [quantities, setQuantities] = useState<Record<number, string>>({})
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const searching = query.trim().length >= 2

  const load = useCallback(() => {
    api.get<PharmacySale[]>('/pharmacy/sales', { params: searching ? { q: query.trim() } : { from, to } }).then((res) => setSales(res.data))
  }, [from, to, query, searching])

  useEffect(() => {
    const timer = setTimeout(load, 250)
    return () => clearTimeout(timer)
  }, [load])

  const show = (sale: PharmacySale, next: Action) => {
    setError('')
    setNotice('')
    setAction(next)
    setQuantities({})
    setReason('')
    api.get<PharmacySale>(`/pharmacy/sales/${sale.id}`).then((res) => {
      setOpen(res.data)
      setAmount(res.data.due > 0 ? String(res.data.due) : '')
    })
  }

  // Sends one change to a bill, then shows the updated bill or why it was refused.
  const run = async (request: Promise<{ data: PharmacySale }>, done: string) => {
    try {
      setOpen((await request).data)
      setNotice(done)
      setError('')
      setAction('view')
      load()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    }
  }

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (!open) return
    const url = `/pharmacy/sales/${open.id}`
    if (action === 'collect') run(api.post(`${url}/payments`, { amount, mode }), 'Payment recorded.')
    if (action === 'return') {
      const items = Object.entries(quantities).filter(([, q]) => Number(q) > 0).map(([id, q]) => ({ id: Number(id), quantity: Number(q) }))
      run(api.post(`${url}/returns`, { refund_mode: mode, items }), 'Medicines taken back and stock updated.')
    }
    if (action === 'delete') run(api.delete(url, { data: { reason, refund_mode: mode } }), 'Bill deleted and stock restored.')
  }

  const live = sales?.filter((s) => !s.voided_at) ?? []
  const billed = live.reduce((sum, s) => sum + s.total - s.returned, 0)
  const collected = live.reduce((sum, s) => sum + s.paid, 0)
  const due = live.reduce((sum, s) => sum + s.due, 0)

  return (
    <>
      <PharmacyTabs />
      <div className="split">
        <section className="card">
          <div className="toolbar">
            <h1>Pharmacy bills</h1>
            {!searching && <DateRange from={from} to={to} onChange={(f, t) => { setFrom(f); setTo(t) }} />}
            <input className="search" type="search" placeholder="Find by bill no., customer or phone (all dates)" value={query} onChange={(e) => setQuery(e.target.value)} />
          </div>
          <div className="tiles small">
            <div className="tile"><span>Bills</span><strong>{live.length}</strong></div>
            <div className="tile"><span>Billed (net of returns)</span><strong>{rupees(Math.round(billed * 100) / 100)}</strong></div>
            <div className="tile"><span>Collected</span><strong>{rupees(collected)}</strong></div>
            <div className="tile"><span>Balance due</span><strong className={due > 0 ? 'due' : ''}>{rupees(Math.round(due * 100) / 100)}</strong></div>
          </div>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Bill</th>
                  <th>Customer</th>
                  <th className="num">Amount</th>
                  <th className="num">Paid</th>
                  <th className="num">Balance</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {sales?.map((s) => (
                  <tr key={s.id} className={`static ${s.voided_at ? 'faded' : ''} ${open?.id === s.id ? 'selected' : ''}`}>
                    <td>
                      <button className="link mono" onClick={() => show(s, 'view')}>
                        {s.bill_no}
                      </button>
                      <small className="muted block">
                        {longDate(s.sold_at)} {clock(s.sold_at)}
                      </small>
                    </td>
                    <td>
                      <strong>{s.customer_name}</strong>
                      {s.customer_phone && <small className="muted block">{s.customer_phone}</small>}
                    </td>
                    <td className="num">
                      {rupees(s.total)}
                      {s.returned > 0 && <small className="block muted">{rupees(s.returned)} returned</small>}
                    </td>
                    <td className="num">
                      {rupees(s.paid)}
                      {s.ipd_transfer > 0 && <small className="block muted">{rupees(s.ipd_transfer)} on IPD bill</small>}
                    </td>
                    <td className={`num ${s.due > 0 ? 'due strong' : ''}`}>{s.voided_at ? <span className="pill off">Deleted</span> : rupees(s.due)}</td>
                    <td className="num actions">
                      {!s.voided_at && canEdit && s.due > 0 && (
                        <button className="link" onClick={() => show(s, 'collect')}>
                          Collect
                        </button>
                      )}
                      <a className="link" href={`/pharmacy/bills/${s.id}/print`} target="_blank" rel="noreferrer">
                        Print
                      </a>
                      {!s.voided_at && canEdit && (
                        <>
                          <button className="link" onClick={() => show(s, 'return')}>
                            Return
                          </button>
                          <button className="link danger" onClick={() => show(s, 'delete')}>
                            Delete
                          </button>
                        </>
                      )}
                    </td>
                  </tr>
                ))}
                {sales && sales.length === 0 && (
                  <tr className="empty">
                    <td colSpan={6}>{searching ? 'No bills match this search.' : 'No pharmacy bills in this period.'}</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </section>

        {open && (
          <form className="card panel wide" onSubmit={submit}>
            <div className="panel-head">
              <div>
                <h2>
                  Bill {open.bill_no} {open.voided_at && <span className="pill off">Deleted</span>}
                </h2>
                <span className="muted">
                  {open.customer_name} · {longDate(open.sold_at)} {clock(open.sold_at)}
                  {open.referred_doctor ? ` · ${open.referred_doctor}` : ''}
                </span>
              </div>
              <button type="button" className="btn ghost" onClick={() => setOpen(null)}>
                Close
              </button>
            </div>
            {notice && <p className="notice">{notice}</p>}
            {open.voided_at && <p className="form-error">Deleted on {longDate(open.voided_at)}: {open.void_reason}</p>}

            <table className="compact">
              <thead>
                <tr>
                  <th>Medicine</th>
                  <th>Batch</th>
                  <th className="num">Qty</th>
                  <th className="num">Amount</th>
                  {action === 'return' && <th className="num">Take back</th>}
                </tr>
              </thead>
              <tbody>
                {open.items?.map((item) => {
                  const left = item.quantity - item.returned_quantity
                  return (
                    <tr key={item.id} className="static">
                      <td>{item.medicine_name}</td>
                      <td>{item.batch_no}</td>
                      <td className="num">
                        {item.quantity}
                        {item.returned_quantity > 0 && <small className="block muted">{item.returned_quantity} returned</small>}
                      </td>
                      <td className="num">{rupees(item.amount)}</td>
                      {action === 'return' && (
                        <td className="num">
                          <input
                            className="qty"
                            type="number"
                            min={0}
                            max={left}
                            disabled={left === 0}
                            placeholder={left === 0 ? '—' : `of ${left}`}
                            value={quantities[item.id] ?? ''}
                            onChange={(e) => setQuantities((q) => ({ ...q, [item.id]: e.target.value }))}
                            aria-label={`Units of ${item.medicine_name} to take back`}
                          />
                        </td>
                      )}
                    </tr>
                  )
                })}
              </tbody>
            </table>

            <dl className="totals">
              <dt>Bill amount</dt>
              <dd>{rupees(open.total)}</dd>
              {open.returned > 0 && (
                <>
                  <dt>Returned</dt>
                  <dd>−{rupees(open.returned)}</dd>
                </>
              )}
              <dt>Paid</dt>
              <dd>{rupees(open.paid)}</dd>
              <dt className="strong">Balance</dt>
              <dd className={`strong ${open.due > 0 ? 'due' : 'settled'}`}>{rupees(open.due)}</dd>
            </dl>

            {(open.payments?.length ?? 0) > 0 && (
              <ul className="history gap-top">
                {open.payments!.map((p) => (
                  <li key={p.id}>
                    <span>
                      {p.type === 'refund' ? 'Refund' : 'Payment'} · {modeLabel(p.mode)}
                      {p.reference ? ` · ${p.reference}` : ''}
                      <small className="muted"> {new Date(p.created_at).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: 'numeric', minute: '2-digit' })}</small>
                    </span>
                    <strong className={p.type === 'refund' ? 'due' : ''}>
                      {p.type === 'refund' ? '−' : ''}
                      {rupees(p.amount)}
                    </strong>
                  </li>
                ))}
              </ul>
            )}

            {error && <p className="form-error">{error}</p>}

            {action === 'collect' && (
              <div className="action-box">
                <h3>Collect balance</h3>
                <div className="row">
                  <input type="number" min={0} step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} aria-label="Amount" />
                  <ModeSelect value={mode} onChange={setMode} />
                  <button className="btn primary" disabled={!amount || Number(amount) <= 0}>
                    Collect
                  </button>
                </div>
              </div>
            )}
            {action === 'return' && (
              <div className="action-box">
                <h3>Take medicines back</h3>
                <p className="muted small">Enter the units coming back against each medicine above. They go back into stock, and any money paid beyond what the bill is then worth is refunded.</p>
                <div className="row">
                  <span className="grow">Refund by</span>
                  <ModeSelect value={mode} onChange={setMode} />
                  <button className="btn primary" disabled={!Object.values(quantities).some((q) => Number(q) > 0)}>
                    Take back
                  </button>
                </div>
              </div>
            )}
            {action === 'delete' && (
              <div className="action-box danger">
                <h3>Delete this bill</h3>
                <p className="muted small">Everything on it goes back into stock and {open.paid > 0 ? `${rupees(open.paid)} is refunded` : 'nothing needs refunding'}. The bill stays on record as deleted.</p>
                <input placeholder="Reason for deleting (required)" value={reason} onChange={(e) => setReason(e.target.value)} aria-label="Reason" />
                <div className="row gap-top">
                  <span className="grow">Refund by</span>
                  <ModeSelect value={mode} onChange={setMode} />
                  <button className="btn danger-btn" disabled={reason.trim().length < 3}>
                    Delete bill
                  </button>
                </div>
              </div>
            )}
          </form>
        )}
      </div>
    </>
  )
}
