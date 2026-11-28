import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { api, fieldErrors } from '../../api'
import { useAuth } from '../../auth'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { downloadCsv, expiryLabel, longDate, rupees, today } from '../../format'
import { COUNT_STATUS, COUNT_STATUS_CLASS, stamp, type CountBrief, type CountFull } from './auditTypes'
import { MedicineLink } from '../../components/MedicineLink'

type Tab = 'overview' | 'counts' | 'missing' | 'history'

type Losses = { loss_value: number; unexplained_value: number; found_value: number; corrections: number }
type Overview = {
  stock: { value: number; units: number; batches: number }
  coverage: { counted_value: number; counted_batches: number; never_counted: number }
  this_month: Losses
  last_month: Losses
  waiting: CountBrief[]
  in_progress: CountBrief[]
  adrift: { batch_id: number; medicine_id: number; medicine: string | null; batch_no: string; on_record: number; ledger: number }[]
  signals: { user: string; bills: number; deleted: number; deleted_value: number; returns: number; returns_value: number; edits: number; reported: number }[]
  since: string
}
type Scopes = { in_stock: number; racks: { rack: string; batches: number }[]; no_rack: number; on_open_counts: number; spot_size: number }
type Missing = {
  from: string
  to: string
  totals: { category: string; label: string; loss: boolean; units: number; value: number; value_mrp: number; times: number }[]
  loss_value: number
  unexplained_value: number
  by_medicine: { medicine_id: number; medicine: string; units: number; value: number; times: number; unexplained: number; last_on: string }[]
  rows: { id: number; on: string; medicine_id: number; medicine: string; before: number; after: number; units: number; category: string; category_label: string; loss: boolean; reason: string; count_no: string | null; by: string | null; value: number }[]
}
type History = {
  medicine: { id: number; name: string; composition: string | null }
  batches: {
    id: number
    batch_no: string
    expiry: string | null
    rack_no: string | null
    units_in_stock: number
    invoice_no: string | null
    adds_up: boolean
    movements: { id: number; on: string; kind: string; label: string; units: number; balance_after: number; reference: string | null; note: string | null; by: string | null }[]
  }[]
}

const monthStart = () => today().slice(0, 8) + '01'
const daysAgo = (days: number) => {
  const d = new Date()
  d.setDate(d.getDate() - days)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** The pharmacy's stock audit: counting the shelf against the records, and seeing where stock went. */
export function PharmacyAudit() {
  const { user } = useAuth()
  const auditor = Boolean(user?.can_audit_pharmacy)
  const [params, setParams] = useSearchParams()
  const asked = params.get('tab') as Tab | null
  const tab: Tab = asked && (auditor || asked === 'counts' || asked === 'history') ? asked : auditor ? 'overview' : 'counts'
  const go = (next: Tab, extra: Record<string, string> = {}) => setParams({ tab: next, ...extra })

  return (
    <>
      <PharmacyTabs />
      <section className="card">
        <div className="toolbar">
          <div className="grow">
            <h1>Stock audit</h1>
            <span className="muted">Count the shelf against the records, approve the differences, and see where stock went.</span>
          </div>
        </div>
        <div className="tabs sub">
          {auditor && (
            <button className={tab === 'overview' ? 'active' : ''} onClick={() => go('overview')}>
              Overview
            </button>
          )}
          <button className={tab === 'counts' ? 'active' : ''} onClick={() => go('counts')}>
            Stock counts
          </button>
          {auditor && (
            <button className={tab === 'missing' ? 'active' : ''} onClick={() => go('missing')}>
              Missing stock
            </button>
          )}
          <button className={tab === 'history' ? 'active' : ''} onClick={() => go('history')}>
            Stock history
          </button>
        </div>

        {tab === 'overview' && <OverviewTab go={go} />}
        {tab === 'counts' && <CountsTab auditor={auditor} />}
        {tab === 'missing' && <MissingTab go={go} />}
        {tab === 'history' && <HistoryTab medicineId={params.get('medicine')} onPick={(id) => go('history', { medicine: String(id) })} />}
      </section>
    </>
  )
}

/** One line for a count in a list: what it is, how far along, and what it found. */
function CountLine({ count, action }: { count: CountBrief; action: string }) {
  return (
    <li>
      <span className="grow">
        <strong>{count.title}</strong> <span className="mono muted">{count.count_no}</span>
        <small className="muted block">
          {count.started_by ?? 'Staff'} · {stamp(count.started_at)} · {count.counted} of {count.lines} counted
          {count.short_lines !== undefined && count.counted > 0 && (
            <>
              {' '}
              · <span className={count.short_lines ? 'due' : 'settled'}>{count.short_lines ? `${count.short_units} short, ${rupees(count.short_value ?? 0)}` : 'nothing short'}</span>
              {count.extra_lines ? ` · ${count.extra_units} extra` : ''}
            </>
          )}
        </small>
      </span>
      <Link className="btn small-btn" to={`/pharmacy/audit/counts/${count.id}`}>
        {action}
      </Link>
    </li>
  )
}

function OverviewTab({ go }: { go: (tab: Tab, extra?: Record<string, string>) => void }) {
  const [data, setData] = useState<Overview | null>(null)
  const navigate = useNavigate()
  const [starting, setStarting] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    api.get<Overview>('/pharmacy/audit/overview').then((res) => setData(res.data))
  }, [])

  const spotCheck = async () => {
    setStarting(true)
    setError('')
    try {
      const res = await api.post<CountFull>('/pharmacy/audit/counts', { scope: 'spot' })
      navigate(`/pharmacy/audit/counts/${res.data.id}`)
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'Could not start the check.')
      setStarting(false)
    }
  }

  if (!data) return <p className="loading">Loading the audit…</p>
  const covered = data.stock.value > 0 ? Math.round((data.coverage.counted_value / data.stock.value) * 100) : 0
  const change = data.this_month.unexplained_value - data.last_month.unexplained_value

  return (
    <div className="audit">
      <div className="tiles small audit-tiles">
        <div className="tile">
          <span>Missing this month, not explained</span>
          <strong className={data.this_month.unexplained_value > 0 ? 'due' : ''}>{rupees(data.this_month.unexplained_value)}</strong>
          <small className="muted">
            Last month {rupees(data.last_month.unexplained_value)}
            {change !== 0 && data.last_month.corrections > 0 ? ` · ${change > 0 ? 'up' : 'down'} ${rupees(Math.abs(change))}` : ''}
          </small>
        </div>
        <div className="tile">
          <span>All losses this month</span>
          <strong>{rupees(data.this_month.loss_value)}</strong>
          <small className="muted">Missing, unbilled, damaged and expired, at cost</small>
        </div>
        <div className="tile">
          <span>Stock counted in the last 30 days</span>
          <strong>{covered}%</strong>
          <small className={data.coverage.never_counted ? 'waiting' : 'muted'}>
            {data.coverage.counted_batches} of {data.stock.batches} batches{data.coverage.never_counted ? ` · ${data.coverage.never_counted} never counted` : ''}
          </small>
        </div>
        <div className="tile">
          <span>Stock on the shelf, at cost</span>
          <strong>{rupees(data.stock.value)}</strong>
          <small className="muted">
            {data.stock.units.toLocaleString('en-IN')} units in {data.stock.batches} batches
          </small>
        </div>
      </div>

      <div className="quick-actions">
        <button className="btn primary" disabled={starting} onClick={spotCheck} title="A few batches picked by the system: costly fast sellers and some at random">
          {starting ? 'Starting…' : "Start today's spot check"}
        </button>
        <button className="btn" onClick={() => go('counts')}>
          Start another count…
        </button>
        <button className="btn" onClick={() => go('missing')}>
          See what is missing
        </button>
      </div>
      {error && <p className="form-error">{error}</p>}

      <div className="two-up audit-lists">
        <div>
          <h3>
            Waiting for your approval <small className="count">{data.waiting.length}</small>
          </h3>
          <ul className="history">
            {data.waiting.map((c) => (
              <CountLine key={c.id} count={c} action="Check and approve" />
            ))}
            {data.waiting.length === 0 && <li className="muted">Nothing is waiting.</li>}
          </ul>
        </div>
        <div>
          <h3>
            Being counted now <small className="count">{data.in_progress.length}</small>
          </h3>
          <ul className="history">
            {data.in_progress.map((c) => (
              <CountLine key={c.id} count={c} action="Open" />
            ))}
            {data.in_progress.length === 0 && <li className="muted">No count is open.</li>}
          </ul>
        </div>
      </div>

      {data.adrift.length > 0 && (
        <>
          <h3>Stock records that do not add up</h3>
          <p className="form-error audit-warning">
            For these batches the number on record is not what the purchases, sales and corrections add up to. Something changed the stock outside the pharmacy screens. Count them and look at their history.
          </p>
          <div className="table-wrap">
            <table className="compact">
              <thead>
                <tr>
                  <th>Medicine</th>
                  <th>Batch</th>
                  <th className="num">On record</th>
                  <th className="num">Adds up to</th>
                  <th className="num">Difference</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {data.adrift.map((a) => (
                  <tr key={a.batch_id} className="static">
                    <td>{a.medicine ?? '—'}</td>
                    <td className="mono">{a.batch_no}</td>
                    <td className="num">{a.on_record}</td>
                    <td className="num">{a.ledger}</td>
                    <td className="num due">{a.on_record - a.ledger}</td>
                    <td className="num">
                      <button className="link" onClick={() => go('history', { medicine: String(a.medicine_id) })}>
                        History
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}

      <h3>Worth a second look, by person</h3>
      <p className="muted small">Since {longDate(data.since)}. None of these is wrong in itself. A pattern is the thing to look for: many deleted bills, many medicines taken back, or bills changed after they were made.</p>
      <div className="table-wrap">
        <table className="compact">
          <thead>
            <tr>
              <th>Person</th>
              <th className="num">Bills made</th>
              <th className="num">Bills deleted</th>
              <th className="num">Medicines taken back</th>
              <th className="num">Bills changed later</th>
              <th className="num">Stock quantities changed</th>
            </tr>
          </thead>
          <tbody>
            {data.signals.map((s) => (
              <tr key={s.user} className="static">
                <td>
                  <strong>{s.user}</strong>
                </td>
                <td className="num">{s.bills}</td>
                <td className={`num ${s.deleted ? 'due' : ''}`}>{s.deleted ? `${s.deleted} · ${rupees(s.deleted_value)}` : '—'}</td>
                <td className="num">{s.returns ? `${s.returns} · ${rupees(s.returns_value)}` : '—'}</td>
                <td className="num">{s.edits || '—'}</td>
                <td className="num">{s.reported || '—'}</td>
              </tr>
            ))}
            {data.signals.length === 0 && (
              <tr className="empty">
                <td colSpan={6}>No pharmacy activity in this time.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function CountsTab({ auditor }: { auditor: boolean }) {
  const navigate = useNavigate()
  const [counts, setCounts] = useState<CountBrief[] | null>(null)
  const [scopes, setScopes] = useState<Scopes | null>(null)
  const [scope, setScope] = useState<'spot' | 'rack' | 'letters' | 'all'>('spot')
  const [rack, setRack] = useState('')
  const [letters, setLetters] = useState({ from: 'A', to: 'C' })
  const [size, setSize] = useState('15')
  const [blind, setBlind] = useState(true)
  const [error, setError] = useState('')
  const [starting, setStarting] = useState(false)
  const [show, setShow] = useState<'open' | 'approved' | 'all'>('open')

  const load = useCallback(() => {
    api.get<CountBrief[]>('/pharmacy/audit/counts').then((res) => setCounts(res.data))
    api.get<Scopes>('/pharmacy/audit/scopes').then((res) => {
      setScopes(res.data)
      setSize(String(res.data.spot_size))
    })
  }, [])
  useEffect(load, [load])

  const start = async (e: FormEvent) => {
    e.preventDefault()
    setStarting(true)
    setError('')
    try {
      const body = { scope, blind, ...(scope === 'rack' ? { rack } : scope === 'letters' ? letters : scope === 'spot' ? { size: Number(size) } : {}) }
      const res = await api.post<CountFull>('/pharmacy/audit/counts', body)
      navigate(`/pharmacy/audit/counts/${res.data.id}`)
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'Could not start the count.')
      setStarting(false)
    }
  }

  const shown = (counts ?? []).filter((c) => (show === 'all' ? true : show === 'approved' ? c.status === 'approved' : c.status === 'counting' || c.status === 'submitted'))
  const covers = scope === 'all' ? scopes?.in_stock : scope === 'rack' ? (rack === '-' ? scopes?.no_rack : scopes?.racks.find((r) => r.rack === rack)?.batches) : scope === 'spot' ? Number(size) || 0 : undefined

  return (
    <div className="audit">
      <form className="count-start" onSubmit={start}>
        <strong>Start a count</strong>
        <div className="tabs flush" role="group" aria-label="What to count">
          {(
            [
              ['spot', 'Spot check'],
              ['rack', 'One rack'],
              ['letters', 'By first letter'],
              ['all', 'Whole pharmacy'],
            ] as const
          ).map(([key, label]) => (
            <button type="button" key={key} className={scope === key ? 'active' : ''} onClick={() => setScope(key)}>
              {label}
            </button>
          ))}
        </div>
        <div className="row count-choice">
          {scope === 'spot' && (
            <label className="inline-field">
              Batches to check
              <input className="qty" type="number" min={3} max={60} value={size} onChange={(e) => setSize(e.target.value)} />
            </label>
          )}
          {scope === 'rack' && (
            <select value={rack} onChange={(e) => setRack(e.target.value)} aria-label="Rack" required>
              <option value="">Choose the rack…</option>
              {scopes?.racks.map((r) => (
                <option key={r.rack} value={r.rack}>
                  Rack {r.rack} · {r.batches} batch{r.batches === 1 ? '' : 'es'}
                </option>
              ))}
              {scopes && scopes.no_rack > 0 && <option value="-">No rack noted · {scopes.no_rack} batch{scopes.no_rack === 1 ? '' : 'es'}</option>}
            </select>
          )}
          {scope === 'letters' && (
            <label className="inline-field">
              Medicines from
              <input className="letter" maxLength={1} value={letters.from} onChange={(e) => setLetters({ ...letters, from: e.target.value.toUpperCase() })} aria-label="From letter" required />
              to
              <input className="letter" maxLength={1} value={letters.to} onChange={(e) => setLetters({ ...letters, to: e.target.value.toUpperCase() })} aria-label="To letter" required />
            </label>
          )}
          <label className="check flush" title="The people counting are not shown what the records say, so the count is their own">
            <input type="checkbox" checked={blind} onChange={(e) => setBlind(e.target.checked)} />
            Blind count
          </label>
          <button className="btn primary" disabled={starting || (scope === 'rack' && !rack)}>
            {starting ? 'Starting…' : `Start${covers ? ` · ${covers} batch${covers === 1 ? '' : 'es'}` : ''}`}
          </button>
        </div>
        <small className="muted">
          {scope === 'spot' && 'A few batches picked by the system: half are costly fast sellers not counted in the last two weeks, half are picked at random. Takes a few minutes; do one every day.'}
          {scope === 'rack' && 'Everything in stock on one rack. A good size for one sitting.'}
          {scope === 'letters' && 'Every batch of the medicines whose names start in this range.'}
          {scope === 'all' && 'Every batch in stock. Best done with the counter closed, or over a quiet day.'}
          {scopes && scopes.on_open_counts > 0 && ` ${scopes.on_open_counts} batches are on a count that is still open and are left out.`}
        </small>
        {error && <p className="form-error">{error}</p>}
      </form>

      <div className="desk-filters">
        <div className="tabs flush" role="group" aria-label="Which counts to show">
          {(
            [
              ['open', 'Open'],
              ['approved', 'Approved'],
              ['all', 'All'],
            ] as const
          ).map(([key, label]) => (
            <button key={key} className={show === key ? 'active' : ''} onClick={() => setShow(key)}>
              {label}
            </button>
          ))}
        </div>
      </div>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Count</th>
              <th>Started</th>
              <th>Counted</th>
              {auditor && <th className="num">Short</th>}
              {auditor && <th className="num">Extra</th>}
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {shown.map((c) => (
              <tr key={c.id} className={c.status === 'approved' || c.status === 'cancelled' ? 'done' : ''} onClick={() => navigate(`/pharmacy/audit/counts/${c.id}`)}>
                <td className="fit-wrap">
                  <strong>{c.title}</strong>
                  <small className="muted block mono">{c.count_no}</small>
                </td>
                <td>
                  {stamp(c.started_at)}
                  <small className="muted block">{c.started_by ?? 'Staff'}</small>
                </td>
                <td>
                  {c.counted} of {c.lines}
                  {c.blind && <small className="muted block">Blind</small>}
                </td>
                {auditor && <td className={`num ${c.short_lines ? 'due' : ''}`}>{c.short_lines ? `${c.short_units} · ${rupees(c.short_value ?? 0)}` : '—'}</td>}
                {auditor && <td className="num">{c.extra_lines ? `${c.extra_units} · ${rupees(c.extra_value ?? 0)}` : '—'}</td>}
                <td>
                  <span className={`pill ${COUNT_STATUS_CLASS[c.status]}`}>{COUNT_STATUS[c.status]}</span>
                  {c.approved_by && <small className="muted block">by {c.approved_by}</small>}
                </td>
              </tr>
            ))}
            {counts && shown.length === 0 && (
              <tr className="empty">
                <td colSpan={auditor ? 6 : 4}>{show === 'open' ? 'No count is open. Start one above.' : 'No counts here yet.'}</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function MissingTab({ go }: { go: (tab: Tab, extra?: Record<string, string>) => void }) {
  const [range, setRange] = useState({ from: monthStart(), to: today() })
  const [data, setData] = useState<Missing | null>(null)

  useEffect(() => {
    api.get<Missing>('/pharmacy/audit/missing', { params: range }).then((res) => setData(res.data))
  }, [range])

  const presets: [string, { from: string; to: string }][] = [
    ['This month', { from: monthStart(), to: today() }],
    ['Last 30 days', { from: daysAgo(30), to: today() }],
    ['Last 90 days', { from: daysAgo(90), to: today() }],
  ]

  return (
    <div className="audit">
      <div className="toolbar">
        <input type="date" className="date" value={range.from} max={range.to} onChange={(e) => setRange({ ...range, from: e.target.value || monthStart() })} aria-label="From" />
        <span className="muted">to</span>
        <input type="date" className="date" value={range.to} min={range.from} max={today()} onChange={(e) => setRange({ ...range, to: e.target.value || today() })} aria-label="To" />
        {presets.map(([label, preset]) => (
          <button key={label} className={`chip ${range.from === preset.from && range.to === preset.to ? 'on' : ''}`} onClick={() => setRange(preset)}>
            {label}
          </button>
        ))}
        <span className="grow" />
        {data && data.rows.length > 0 && (
          <button
            className="btn small-btn"
            onClick={() =>
              downloadCsv(
                `stock-differences-${data.from}-to-${data.to}.csv`,
                ['Date', 'Medicine', 'Before', 'After', 'Units', 'Reason', 'Note', 'Value at cost', 'Stock count', 'Approved by'],
                data.rows.map((r) => [r.on, r.medicine, r.before, r.after, r.units, r.category_label, r.reason, r.value, r.count_no ?? '', r.by ?? '']),
              )
            }
          >
            Download
          </button>
        )}
      </div>

      {!data ? (
        <p className="loading">Loading…</p>
      ) : (
        <>
          <div className="tiles small audit-tiles reasons">
            {data.totals
              .filter((t) => t.times > 0 || t.category === 'missing')
              .map((t) => (
                <div key={t.category} className="tile">
                  <span>{t.label}</span>
                  <strong className={t.category === 'missing' && t.value > 0 ? 'due' : ''}>{rupees(t.value)}</strong>
                  <small className="muted">
                    {t.units} units · {t.times} time{t.times === 1 ? '' : 's'}
                  </small>
                </div>
              ))}
          </div>
          <p className="muted small">
            Values are at what the stock cost. Losses in this time come to <strong>{rupees(data.loss_value)}</strong>, of which <strong className={data.unexplained_value > 0 ? 'due' : ''}>{rupees(data.unexplained_value)}</strong> has no explanation.
          </p>

          <h3>Medicines that lose the most</h3>
          <div className="table-wrap">
            <table className="compact">
              <thead>
                <tr>
                  <th>Medicine</th>
                  <th className="num">Units lost</th>
                  <th className="num">Not explained</th>
                  <th className="num">Value</th>
                  <th className="num">Times</th>
                  <th>Last</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {data.by_medicine.map((m) => (
                  <tr key={m.medicine_id} className="static">
                    <td>
                      <strong>
                        <MedicineLink id={m.medicine_id}>{m.medicine}</MedicineLink>
                      </strong>
                    </td>
                    <td className="num">{m.units}</td>
                    <td className={`num ${m.unexplained ? 'due' : ''}`}>{m.unexplained || '—'}</td>
                    <td className="num">{rupees(m.value)}</td>
                    <td className={`num ${m.times > 1 ? 'due' : ''}`}>{m.times}</td>
                    <td>{stamp(m.last_on)}</td>
                    <td className="num">
                      <button className="link" onClick={() => go('history', { medicine: String(m.medicine_id) })}>
                        History
                      </button>
                    </td>
                  </tr>
                ))}
                {data.by_medicine.length === 0 && (
                  <tr className="empty">
                    <td colSpan={7}>No losses recorded in this time. Losses show here once a stock count is approved.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          <h3>Every difference</h3>
          <div className="table-wrap">
            <table className="compact">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Medicine</th>
                  <th className="num">Was</th>
                  <th className="num">Now</th>
                  <th className="num">Units</th>
                  <th>Reason</th>
                  <th className="num">Value</th>
                  <th>From</th>
                </tr>
              </thead>
              <tbody>
                {data.rows.map((r) => (
                  <tr key={r.id} className="static">
                    <td>{stamp(r.on)}</td>
                    <td>
                      <MedicineLink id={r.medicine_id}>{r.medicine}</MedicineLink>
                    </td>
                    <td className="num">{r.before}</td>
                    <td className="num">{r.after}</td>
                    <td className={`num ${r.units < 0 ? 'due' : 'settled'}`}>{r.units > 0 ? `+${r.units}` : r.units}</td>
                    <td className="wrap">
                      {r.category_label}
                      {r.reason && r.reason !== r.category_label && <small className="muted block">{r.reason.replace(`${r.category_label}: `, '')}</small>}
                    </td>
                    <td className="num">{rupees(r.value)}</td>
                    <td>
                      {r.count_no ?? 'Correction'}
                      <small className="muted block">{r.by ?? ''}</small>
                    </td>
                  </tr>
                ))}
                {data.rows.length === 0 && (
                  <tr className="empty">
                    <td colSpan={8}>Nothing was corrected in this time.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  )
}

function HistoryTab({ medicineId, onPick }: { medicineId: string | null; onPick: (id: number) => void }) {
  const [query, setQuery] = useState('')
  const [found, setFound] = useState<{ id: number; name: string; units_in_stock: number }[]>([])
  const [data, setData] = useState<History | null>(null)

  useEffect(() => {
    if (query.trim().length < 2) return
    const timer = setTimeout(() => api.get<{ id: number; name: string; units_in_stock: number }[]>('/pharmacy/medicines', { params: { q: query.trim(), all: 1 } }).then((res) => setFound(res.data.slice(0, 12))), 250)
    return () => clearTimeout(timer)
  }, [query])

  useEffect(() => {
    if (medicineId) api.get<History>('/pharmacy/audit/history', { params: { medicine_id: medicineId } }).then((res) => setData(res.data))
  }, [medicineId])

  const shown = medicineId ? data : null

  return (
    <div className="audit">
      <div className="toolbar">
        <input className="search" type="search" placeholder="Find a medicine to see everything that happened to its stock" value={query} onChange={(e) => setQuery(e.target.value)} autoFocus={!medicineId} />
      </div>
      {query.trim().length >= 2 && (
        <div className="chips gap-bottom">
          {found.map((m) => (
            <button
              key={m.id}
              className="chip"
              onClick={() => {
                onPick(m.id)
                setQuery('')
              }}
            >
              {m.name} · {m.units_in_stock}
            </button>
          ))}
          {found.length === 0 && <span className="muted">No medicine matches.</span>}
        </div>
      )}

      {!medicineId && <p className="muted">Every purchase, sale, return, deleted bill and correction is kept against the batch it touched, with who did it. Choose a medicine to read its story.</p>}
      {medicineId && !shown && <p className="loading">Loading the history…</p>}
      {shown && (
        <>
          <h3>
            {shown.medicine.name} {shown.medicine.composition && <small className="muted">{shown.medicine.composition}</small>}
          </h3>
          {shown.batches.length === 0 && <p className="muted">No stock of this medicine was ever received.</p>}
          {shown.batches.map((b) => (
            <details key={b.id} className="batch-history" open={b.units_in_stock > 0 || shown.batches.length === 1}>
              <summary>
                <strong className="mono">{b.batch_no}</strong>
                <span className="muted">
                  {b.expiry ? ` · expires ${expiryLabel(b.expiry)}` : ''}
                  {b.rack_no ? ` · rack ${b.rack_no}` : ''}
                  {b.invoice_no ? ` · invoice ${b.invoice_no}` : ''}
                </span>
                <span className="grow" />
                {!b.adds_up && <span className="pill off">Does not add up</span>}
                <strong>{b.units_in_stock} on record</strong>
              </summary>
              <div className="table-wrap">
                <table className="compact">
                  <thead>
                    <tr>
                      <th>When</th>
                      <th>What happened</th>
                      <th>For</th>
                      <th className="num">In</th>
                      <th className="num">Out</th>
                      <th className="num">Balance</th>
                      <th>By</th>
                    </tr>
                  </thead>
                  <tbody>
                    {b.movements.map((m) => (
                      <tr key={m.id} className={`static ${m.kind === 'count' || m.kind === 'correction' ? 'corrected' : ''}`}>
                        <td>{stamp(m.on)}</td>
                        <td className="wrap">
                          {m.label}
                          {m.note && <small className="muted block">{m.note}</small>}
                        </td>
                        <td className="mono">{m.reference ?? ''}</td>
                        <td className="num settled">{m.units > 0 ? m.units : ''}</td>
                        <td className="num due">{m.units < 0 ? -m.units : ''}</td>
                        <td className="num">
                          <strong>{m.balance_after}</strong>
                        </td>
                        <td>{m.by ?? ''}</td>
                      </tr>
                    ))}
                    {b.movements.length === 0 && (
                      <tr className="empty">
                        <td colSpan={7}>Nothing recorded for this batch.</td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </details>
          ))}
        </>
      )}
    </div>
  )
}
