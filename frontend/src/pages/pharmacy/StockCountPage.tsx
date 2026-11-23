import { useEffect, useRef, useState, type KeyboardEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, fieldErrors } from '../../api'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { expiryLabel, rupees } from '../../format'
import { COUNT_STATUS, COUNT_STATUS_CLASS, REASONS, stamp, type CountFull, type CountRow } from './auditTypes'

type Found = { id: number; medicine: string | null; batch_no: string; expiry: string | null; rack_no: string | null }
type Review = Record<number, { reason: string; note: string; recount: boolean }>

/**
 * The likely reason for each difference, as a starting point for the person approving:
 * when the batches of one medicine cancel out it was sold from another batch; otherwise short is missing and extra is found.
 */
const suggest = (rows: CountRow[]): Review => {
  const net = new Map<number, number>()
  for (const r of rows) if (r.difference) net.set(r.medicine_id, (net.get(r.medicine_id) ?? 0) + r.difference)
  return Object.fromEntries(
    rows
      .filter((r) => r.difference)
      .map((r) => [r.id, { reason: r.reason ?? (net.get(r.medicine_id) === 0 ? 'mixup' : r.difference! < 0 ? 'missing' : 'found'), note: r.note ?? '', recount: r.recount }]),
  )
}

/** One stock count: the sheet the shelf is counted on, then the differences to check and approve. */
export function StockCountPage() {
  const { id } = useParams()
  const [count, setCount] = useState<CountFull | null>(null)
  const [typed, setTyped] = useState<Record<number, string>>({})
  const [review, setReview] = useState<Review>({})
  const [only, setOnly] = useState<'all' | 'left' | 'different'>('all')
  const [filter, setFilter] = useState('')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const [finding, setFinding] = useState('')
  const [found, setFound] = useState<Found[]>([])
  const sheet = useRef<HTMLDivElement>(null)
  const url = `/pharmacy/audit/counts/${id}`

  const apply = (next: CountFull) => {
    setCount(next)
    setTyped(Object.fromEntries(next.rows.map((r) => [r.id, r.counted_units === null ? '' : String(r.counted_units)])))
    setReview(suggest(next.rows))
  }

  useEffect(() => {
    api.get<CountFull>(url).then((res) => apply(res.data))
  }, [url])

  useEffect(() => {
    if (finding.trim().length < 2) return
    const timer = setTimeout(() => api.get<Found[]>('/pharmacy/audit/batches', { params: { q: finding.trim() } }).then((res) => setFound(res.data)), 250)
    return () => clearTimeout(timer)
  }, [finding])

  if (!count) return <p className="loading">Loading the count…</p>

  const counting = count.status === 'counting'
  const checking = count.status === 'submitted' && count.can_approve
  const sees = count.sees_records

  const run = async (request: Promise<{ data: CountFull }>, done: string) => {
    setBusy(true)
    setError('')
    setNotice('')
    try {
      apply((await request).data)
      setNotice(done)
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'Could not save. Please try again.')
    } finally {
      setBusy(false)
    }
  }

  /** Saves what was typed for one batch as soon as the box is left, so nothing is lost if the page closes. */
  const saveLine = async (row: CountRow, written: string) => {
    // Read from the box itself, not from what the screen last drew, so a quick tab or tap away never saves an old number.
    const text = written.trim()
    const value = text === '' ? null : Number(text)
    if (value === row.counted_units || (value !== null && (!Number.isInteger(value) || value < 0))) return
    setError('')
    try {
      const res = await api.put<CountRow>(`${url}/lines/${row.id}`, { counted_units: value })
      setCount((c) => (c ? { ...c, counted: c.counted + (value === null ? -1 : row.counted_units === null ? 1 : 0), rows: c.rows.map((r) => (r.id === row.id ? { ...r, ...res.data } : r)) } : c))
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'Could not save that count.')
    }
  }

  /** Enter and the arrow keys move down and up the count boxes, so a whole rack is entered without the mouse. */
  const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
    const by = e.key === 'Enter' || e.key === 'ArrowDown' ? 1 : e.key === 'ArrowUp' ? -1 : 0
    if (!by) return
    e.preventDefault()
    const boxes = [...(sheet.current?.querySelectorAll<HTMLInputElement>('input.count-box') ?? [])]
    const next = boxes[boxes.indexOf(e.currentTarget) + by]
    if (next) {
      next.focus()
      next.select()
    } else e.currentTarget.blur()
  }

  const reviewed = () => ({ lines: Object.entries(review).map(([lineId, r]) => ({ id: Number(lineId), reason: r.reason || null, note: r.note || null, recount: r.recount })) })
  const setLine = (lineId: number, change: Partial<Review[number]>) => setReview((r) => ({ ...r, [lineId]: { ...r[lineId], ...change } }))

  const term = filter.trim().toLowerCase()
  const rows = count.rows.filter(
    (r) =>
      (only === 'all' || (only === 'left' ? r.counted_units === null : Boolean(r.difference))) &&
      (term === '' || r.medicine.toLowerCase().includes(term) || r.batch_no.toLowerCase().includes(term) || (r.rack_no ?? '').toLowerCase().includes(term)),
  )
  const left = count.rows.filter((r) => r.counted_units === null).length
  const different = count.rows.filter((r) => r.difference).length
  const recounts = Object.values(review).filter((r) => r.recount).length
  const columns = 4 + (sees ? 3 : 0) + (checking || count.status === 'approved' ? 1 : 0) + (checking ? 1 : 0)

  return (
    <>
      <PharmacyTabs />
      <section className="card stock-count">
        <div className="toolbar">
          <Link className="btn ghost no-print" to="/pharmacy/audit?tab=counts">
            ← Counts
          </Link>
          <div className="grow">
            <h1>
              {count.title} <small className="muted mono">{count.count_no}</small>
            </h1>
            <span className="muted">
              Started by {count.started_by ?? 'staff'} · {stamp(count.started_at)}
              {count.submitted_at ? ` · handed in ${stamp(count.submitted_at)}${count.submitted_by ? ` by ${count.submitted_by}` : ''}` : ''}
              {count.approved_at ? ` · approved ${stamp(count.approved_at)} by ${count.approved_by ?? 'staff'}` : ''}
              {count.blind ? ' · blind count' : ''}
            </span>
          </div>
          <span className={`pill ${COUNT_STATUS_CLASS[count.status]}`}>{COUNT_STATUS[count.status]}</span>
          <button className="btn no-print" onClick={() => window.print()} title="A sheet to take to the shelf">
            Print sheet
          </button>
        </div>

        <div className="tiles small audit-tiles">
          <div className="tile">
            <span>Counted</span>
            <strong>
              {count.counted} <small className="muted">of {count.lines}</small>
            </strong>
            <small className={left && counting ? 'waiting' : 'muted'}>{left ? `${left} still to count` : 'Every batch counted'}</small>
          </div>
          {sees && (
            <>
              <div className="tile">
                <span>Short</span>
                <strong className={count.short_lines ? 'due' : ''}>{rupees(count.short_value ?? 0)}</strong>
                <small className="muted">
                  {count.short_units ?? 0} units in {count.short_lines ?? 0} batches
                </small>
              </div>
              <div className="tile">
                <span>Extra</span>
                <strong>{rupees(count.extra_value ?? 0)}</strong>
                <small className="muted">
                  {count.extra_units ?? 0} units in {count.extra_lines ?? 0} batches
                </small>
              </div>
            </>
          )}
        </div>

        {counting && !sees && <p className="notice no-print">This is a blind count: write what is on the shelf. What the records say is shown to the person who checks the count.</p>}
        {count.status === 'submitted' && !count.can_approve && <p className="notice">This count has been handed in and is waiting for approval. The stock changes only when it is approved.</p>}
        {checking && different > 0 && (
          <p className="notice no-print">
            {different} batch{different === 1 ? '' : 'es'} differ. Choose the reason for each, or tick Recount to send it back to the shelf. Approving puts the differences into the stock.
          </p>
        )}
        {notice && <p className="notice no-print">{notice}</p>}
        {error && <p className="form-error no-print">{error}</p>}

        <div className="desk-filters no-print">
          <div className="tabs flush" role="group" aria-label="Which batches to show">
            <button className={only === 'all' ? 'active' : ''} onClick={() => setOnly('all')}>
              All <span>{count.rows.length}</span>
            </button>
            <button className={only === 'left' ? 'active' : ''} onClick={() => setOnly('left')}>
              Not counted <span>{left}</span>
            </button>
            {sees && (
              <button className={only === 'different' ? 'active' : ''} onClick={() => setOnly('different')}>
                Different <span>{different}</span>
              </button>
            )}
          </div>
          <input className="search narrow" type="search" placeholder="Filter by medicine, batch or rack" value={filter} onChange={(e) => setFilter(e.target.value)} />
        </div>

        <div className="table-wrap" ref={sheet}>
          <table className="count-sheet">
            <thead>
              <tr>
                <th>Rack</th>
                <th>Medicine</th>
                <th>Batch</th>
                {sees && <th className="num">On record</th>}
                <th className="num">Counted</th>
                {sees && <th className="num">Difference</th>}
                {sees && <th className="num">Value</th>}
                {(checking || count.status === 'approved') && <th>Reason</th>}
                {checking && <th>Recount</th>}
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => {
                const line = review[r.id]
                return (
                  <tr key={r.id} className={`static ${r.recount && counting ? 'recount' : ''} ${r.difference ? 'different' : ''}`}>
                    <td>{r.rack_no || '—'}</td>
                    <td className="fit-wrap">
                      <strong>{r.medicine}</strong>
                      {r.recount && counting && <span className="pill status-arrived"> Count again</span>}
                      {r.counted_by && <small className="muted block no-print">{`${r.counted_by}${r.counted_at ? ` · ${stamp(r.counted_at)}` : ''}`}</small>}
                    </td>
                    <td className="mono">
                      {r.batch_no}
                      {r.expiry && <small className="muted block">{expiryLabel(r.expiry)}</small>}
                    </td>
                    {sees && (
                      <td className="num" data-label="On record">
                        {r.on_record}
                      </td>
                    )}
                    <td className="num" data-label="Counted">
                      {counting ? (
                        <input
                          className="count-box"
                          type="number"
                          min={0}
                          inputMode="numeric"
                          value={typed[r.id] ?? ''}
                          onChange={(e) => setTyped((t) => ({ ...t, [r.id]: e.target.value }))}
                          onBlur={(e) => saveLine(r, e.currentTarget.value)}
                          onKeyDown={onKey}
                          aria-label={`Counted units of ${r.medicine}, batch ${r.batch_no}`}
                        />
                      ) : (
                        (r.counted_units ?? <span className="muted">Not counted</span>)
                      )}
                    </td>
                    {sees && (
                      <td className={`num ${r.difference ? (r.difference < 0 ? 'due' : 'settled') : ''}`} data-label="Difference">
                        {r.difference == null ? '' : r.difference === 0 ? '✓' : r.difference > 0 ? `+${r.difference}` : r.difference}
                      </td>
                    )}
                    {sees && (
                      <td className="num" data-label="Value">
                        {r.difference ? rupees(r.value ?? 0) : ''}
                      </td>
                    )}
                    {checking && (
                      <td>
                        {r.difference && line ? (
                          <div className="reason-cell">
                            <select value={line.reason} onChange={(e) => setLine(r.id, { reason: e.target.value })} aria-label={`Reason for the difference in ${r.medicine}`}>
                              {REASONS.map(([key, label]) => (
                                <option key={key} value={key}>
                                  {label}
                                </option>
                              ))}
                            </select>
                            <input placeholder="Note (optional)" maxLength={255} value={line.note} onChange={(e) => setLine(r.id, { note: e.target.value })} aria-label={`Note for ${r.medicine}`} />
                          </div>
                        ) : null}
                      </td>
                    )}
                    {count.status === 'approved' && (
                      <td className="wrap">
                        {r.reason ? count.reasons[r.reason] : ''}
                        {r.note && <small className="muted block">{r.note}</small>}
                      </td>
                    )}
                    {checking && <td>{r.difference && line ? <input type="checkbox" className="auto" checked={line.recount} onChange={(e) => setLine(r.id, { recount: e.target.checked })} aria-label={`Count ${r.medicine} again`} /> : null}</td>}
                  </tr>
                )
              })}
              {rows.length === 0 && (
                <tr className="empty">
                  <td colSpan={columns}>{only === 'left' ? 'Every batch has been counted.' : only === 'different' ? 'Nothing differs from the records.' : 'No batch matches.'}</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>

        {counting && (
          <div className="found-batch no-print">
            <strong>Found something on the shelf that is not on this sheet?</strong>
            <input className="search" type="search" placeholder="Type the medicine or batch number to add it" value={finding} onChange={(e) => setFinding(e.target.value)} />
            {finding.trim().length >= 2 && (
              <div className="chips">
                {found
                  .filter((f) => !count.rows.some((r) => r.stock_batch_id === f.id))
                  .map((f) => (
                    <button
                      key={f.id}
                      className="chip"
                      disabled={busy}
                      onClick={() => {
                        setFinding('')
                        run(api.post(`${url}/lines`, { stock_batch_id: f.id }), `${f.medicine} batch ${f.batch_no} added to the sheet.`)
                      }}
                    >
                      + {f.medicine} · {f.batch_no}
                      {f.expiry ? ` · ${expiryLabel(f.expiry)}` : ''}
                    </button>
                  ))}
                {found.length === 0 && <span className="muted">No batch matches. A medicine never received is entered as opening stock, not here.</span>}
              </div>
            )}
          </div>
        )}

        <div className="count-actions no-print">
          {(counting || count.status === 'submitted') && (counting || count.can_approve) && (
            <button className="link danger" disabled={busy} onClick={() => window.confirm('Cancel this count? Nothing changes in the stock, and the batches can be counted afresh.') && run(api.post(`${url}/cancel`, { cancel: true }), 'Count cancelled.')}>
              Cancel this count
            </button>
          )}
          <span className="grow" />
          {counting && (
            <button
              className="btn primary"
              disabled={busy || count.counted === 0}
              onClick={() => (left === 0 || window.confirm(`${left} batch${left === 1 ? ' has not been counted and will be left as it is' : 'es have not been counted and will be left as they are'}. Hand in the count?`)) && run(api.post(`${url}/submit`, { done: true }), 'Handed in for checking.')}
            >
              Hand in for checking
            </button>
          )}
          {checking && (
            <>
              <button className="btn" disabled={busy || recounts === 0} onClick={() => run(api.post(`${url}/reopen`, reviewed()), 'Sent back to the shelf for a recount.')} title={recounts ? '' : 'Tick Recount on the lines to be counted again'}>
                Send back for recount{recounts ? ` (${recounts})` : ''}
              </button>
              <button
                className="btn primary"
                disabled={busy || recounts > 0}
                title={recounts ? 'Untick Recount, or send the count back first' : ''}
                onClick={() =>
                  window.confirm(different ? `Approve this count? ${different} batch${different === 1 ? '' : 'es'} will be corrected in the stock. This cannot be undone, only corrected again.` : 'Approve this count? Nothing differs, so the stock stays as it is.') &&
                  run(api.post(`${url}/approve`, reviewed()), 'Count approved. The stock has been corrected.')
                }
              >
                Approve and correct the stock
              </button>
            </>
          )}
        </div>
      </section>
    </>
  )
}
