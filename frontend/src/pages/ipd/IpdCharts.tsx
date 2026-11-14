import { useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../../api'
import { ObservationGraphs } from '../../components/ObservationGraphs'
import { clock, longDate, nowLocal, today } from '../../format'

type Who = { id: number; name: string } | null
type Vital = { id: number; recorded_at: string; temperature_f: number | null; pulse: number | null; bp_systolic: number | null; bp_diastolic: number | null; resp_rate: number | null; spo2: number | null; blood_sugar: number | null; pain_score: number | null; notes: string | null; recorder: Who; recorded_by: number | null; created_at: string }
type Dose = { id: number; given_at: string; status: 'given' | 'held' | 'refused'; remarks: string | null; giver: Who; given_by: number | null; created_at: string }
type Order = { id: number; kind: 'medicine' | 'instruction'; title: string; dose: string | null; route: string | null; frequency: string | null; instructions: string | null; started_at: string; stopped_at: string | null; orderer: Who; stopper: Who; doses: Dose[] }
type Fluid = { id: number; recorded_at: string; direction: 'in' | 'out'; kind: string; amount_ml: number; notes: string | null; recorder: Who; recorded_by: number | null; created_at: string }
type Chart = { open: boolean; vitals: Vital[]; orders: Order[]; fluids: Fluid[]; fluid_days: { date: string; in: number; out: number; balance: number }[]; fluid_kinds: { in: string[]; out: string[] } }

const blankVitals = () => ({ recorded_at: nowLocal(), temperature_f: '', pulse: '', bp_systolic: '', bp_diastolic: '', resp_rate: '', spo2: '', blood_sugar: '', pain_score: '', notes: '' })
const blankOrder = { kind: 'medicine' as Order['kind'], title: '', dose: '', route: '', frequency: '', instructions: '' }
const ROUTES = ['Oral', 'IV', 'IM', 'SC', 'Nebulised', 'Topical', 'PR', 'Sublingual']
const DOSE_LABEL = { given: 'Given', held: 'Held', refused: 'Refused' } as const

/** Whether the signed-in person may take back an entry: their own, made today. */
const mine = (userId: number | undefined, madeBy: number | null, createdAt: string) => madeBy === userId && new Date(createdAt).toDateString() === new Date().toDateString()
const at = (value: string) => `${value.slice(0, 10) === today() ? 'Today' : longDate(value)} ${clock(value)}`

type Props = { admissionId: number; userId?: number; canChart: boolean; canOrder: boolean }

/** The bedside chart: observations, the doctor's orders with every dose given, and fluids in and out. */
export function IpdCharts({ admissionId, userId, canChart, canOrder }: Props) {
  const [chart, setChart] = useState<Chart | null>(null)
  const [section, setSection] = useState<'vitals' | 'orders' | 'fluids'>('vitals')
  const [vitals, setVitals] = useState(blankVitals)
  const [order, setOrder] = useState(blankOrder)
  const [fluid, setFluid] = useState({ recorded_at: nowLocal(), direction: 'in' as 'in' | 'out', kind: 'Oral', amount_ml: '', notes: '' })
  const [giving, setGiving] = useState<{ order: Order; given_at: string; status: Dose['status']; remarks: string; repeat: boolean } | null>(null)
  const [error, setError] = useState('')
  const [denied, setDenied] = useState(false)
  const url = `/ipd/admissions/${admissionId}/chart`

  useEffect(() => {
    api.get<Chart>(url).then((res) => setChart(res.data)).catch(() => setDenied(true))
  }, [url])

  if (denied) return <p className="muted">The bedside chart is for doctors and nurses.</p>
  if (!chart) return <p className="loading">Loading chart…</p>

  const run = async (request: Promise<{ data: Chart }>) => {
    setError('')
    try {
      setChart((await request).data)
      return true
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'Could not save.')
      return false
    }
  }
  const stamp = (value: string) => value.replace('T', ' ')
  const write = canChart && chart.open

  const saveVitals = async (e: FormEvent) => {
    e.preventDefault()
    const body = Object.fromEntries(Object.entries(vitals).map(([k, v]) => [k, v === '' ? null : v]))
    if (await run(api.post(`${url}/vitals`, { ...body, recorded_at: stamp(vitals.recorded_at) }))) setVitals(blankVitals())
  }
  const saveOrder = async (e: FormEvent) => {
    e.preventDefault()
    if (await run(api.post(`${url}/orders`, { ...order, dose: order.dose || null, route: order.route || null, frequency: order.frequency || null, instructions: order.instructions || null }))) setOrder({ ...blankOrder, kind: order.kind })
  }
  const saveFluid = async (e: FormEvent) => {
    e.preventDefault()
    if (await run(api.post(`${url}/fluids`, { ...fluid, recorded_at: stamp(fluid.recorded_at), notes: fluid.notes || null }))) setFluid({ ...fluid, recorded_at: nowLocal(), amount_ml: '', notes: '' })
  }
  const saveDose = async (e: FormEvent) => {
    e.preventDefault()
    if (!giving) return
    setError('')
    try {
      setChart((await api.post<Chart>(`${url}/orders/${giving.order.id}/doses`, { given_at: stamp(giving.given_at), status: giving.status, remarks: giving.remarks || null, confirm_repeat: giving.repeat })).data)
      setGiving(null)
    } catch (err) {
      const fields = fieldErrors(err)
      setError(Object.values(fields)[0] ?? 'Could not save.')
      // A dose close to the last one needs a deliberate second press.
      if (fields.given_at?.includes('less than 30 minutes')) setGiving({ ...giving, repeat: true })
    }
  }

  const running = chart.orders.filter((o) => !o.stopped_at)
  const stopped = chart.orders.filter((o) => o.stopped_at)
  const num = (v: number | null, unit = '') => (v === null ? '—' : `${v}${unit}`)

  return (
    <div className="chart">
      <div className="tabs">
        <button className={`square ${section === 'vitals' ? 'active' : ''}`} onClick={() => setSection('vitals')}>
          Observations <span>{chart.vitals.length}</span>
        </button>
        <button className={`square ${section === 'orders' ? 'active' : ''}`} onClick={() => setSection('orders')}>
          Orders and medicines <span>{running.length}</span>
        </button>
        <button className={`square ${section === 'fluids' ? 'active' : ''}`} onClick={() => setSection('fluids')}>
          Intake and output <span>{chart.fluids.length}</span>
        </button>
        <a className="btn ghost small-btn push" href={`/ipd/${admissionId}/chart/print`} target="_blank" rel="noreferrer">
          Print chart
        </a>
      </div>
      {!chart.open && <p className="notice">This patient is no longer admitted, so the chart is closed. It can be read but not added to.</p>}
      {error && <p className="form-error">{error}</p>}

      {section === 'vitals' && (
        <>
          {write && (
            <form className="chart-form" onSubmit={saveVitals}>
              <label>
                Time
                <input type="datetime-local" required max={nowLocal()} value={vitals.recorded_at} onChange={(e) => setVitals({ ...vitals, recorded_at: e.target.value })} />
              </label>
              {([['temperature_f', 'Temp °F', '0.1'], ['pulse', 'Pulse', '1'], ['bp_systolic', 'BP sys', '1'], ['bp_diastolic', 'BP dia', '1'], ['resp_rate', 'Resp', '1'], ['spo2', 'SpO₂ %', '1'], ['blood_sugar', 'Sugar mg/dL', '1'], ['pain_score', 'Pain 0-10', '1']] as const).map(([key, label, step]) => (
                <label key={key} className="short">
                  {label}
                  <input type="number" step={step} inputMode="decimal" value={vitals[key]} onChange={(e) => setVitals({ ...vitals, [key]: e.target.value })} />
                </label>
              ))}
              <label className="grow">
                Note
                <input maxLength={255} value={vitals.notes} onChange={(e) => setVitals({ ...vitals, notes: e.target.value })} />
              </label>
              <button className="btn primary">Record</button>
            </form>
          )}
          <ObservationGraphs readings={chart.vitals} />
          <div className="table-wrap">
            <table className="compact">
              <thead>
                <tr>
                  <th>Time</th>
                  <th className="num">Temp</th>
                  <th className="num">Pulse</th>
                  <th className="num">BP</th>
                  <th className="num">Resp</th>
                  <th className="num">SpO₂</th>
                  <th className="num">Sugar</th>
                  <th className="num">Pain</th>
                  <th>Note</th>
                  <th>By</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {chart.vitals.map((v) => (
                  <tr key={v.id} className="static">
                    <td>{at(v.recorded_at)}</td>
                    <td className={`num ${v.temperature_f !== null && v.temperature_f >= 100.4 ? 'due' : ''}`}>{num(v.temperature_f, '°')}</td>
                    <td className={`num ${v.pulse !== null && (v.pulse > 110 || v.pulse < 50) ? 'due' : ''}`}>{num(v.pulse)}</td>
                    <td className={`num ${v.bp_systolic !== null && (v.bp_systolic >= 160 || v.bp_systolic < 90) ? 'due' : ''}`}>{v.bp_systolic ? `${v.bp_systolic}/${v.bp_diastolic}` : '—'}</td>
                    <td className="num">{num(v.resp_rate)}</td>
                    <td className={`num ${v.spo2 !== null && v.spo2 < 94 ? 'due' : ''}`}>{num(v.spo2, '%')}</td>
                    <td className={`num ${v.blood_sugar !== null && (v.blood_sugar < 70 || v.blood_sugar > 250) ? 'due' : ''}`}>{num(v.blood_sugar)}</td>
                    <td className="num">{num(v.pain_score)}</td>
                    <td>{v.notes ?? ''}</td>
                    <td>{v.recorder?.name ?? '—'}</td>
                    <td className="num">
                      {write && mine(userId, v.recorded_by, v.created_at) && (
                        <button className="link danger" onClick={() => window.confirm('Remove this entry? Only do this if it was entered by mistake.') && run(api.delete(`${url}/vitals/${v.id}`))}>
                          Remove
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
                {chart.vitals.length === 0 && (
                  <tr className="empty">
                    <td colSpan={11}>No observations recorded yet.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
          <p className="muted small">Readings shown in red are outside the usual range (fever from 100.4°F, pulse below 50 or above 110, systolic BP below 90 or from 160, SpO₂ below 94%, sugar below 70 or above 250).</p>
        </>
      )}

      {section === 'orders' && (
        <>
          {canOrder && chart.open && (
            <form className="chart-form" onSubmit={saveOrder}>
              <label className="short">
                Order
                <select value={order.kind} onChange={(e) => setOrder({ ...order, kind: e.target.value as Order['kind'] })}>
                  <option value="medicine">Medicine</option>
                  <option value="instruction">Instruction</option>
                </select>
              </label>
              <label className="grow">
                {order.kind === 'medicine' ? 'Medicine' : 'Instruction'}
                <input required maxLength={255} placeholder={order.kind === 'medicine' ? 'e.g. Inj Ceftriaxone 1g' : 'e.g. Soft diet; check sugar before meals'} value={order.title} onChange={(e) => setOrder({ ...order, title: e.target.value })} />
              </label>
              {order.kind === 'medicine' && (
                <>
                  <label className="short">
                    Dose
                    <input maxLength={60} placeholder="1 g" value={order.dose} onChange={(e) => setOrder({ ...order, dose: e.target.value })} />
                  </label>
                  <label className="short">
                    Route
                    <input list="order-routes" maxLength={30} value={order.route} onChange={(e) => setOrder({ ...order, route: e.target.value })} />
                    <datalist id="order-routes">
                      {ROUTES.map((r) => (
                        <option key={r} value={r} />
                      ))}
                    </datalist>
                  </label>
                  <label>
                    How often
                    <input maxLength={60} placeholder="8 hourly, or if fever" value={order.frequency} onChange={(e) => setOrder({ ...order, frequency: e.target.value })} />
                  </label>
                </>
              )}
              <label className="grow">
                Note
                <input maxLength={500} value={order.instructions} onChange={(e) => setOrder({ ...order, instructions: e.target.value })} />
              </label>
              <button className="btn primary">Add order</button>
            </form>
          )}
          {!canOrder && chart.open && <p className="muted small">Orders are written and stopped by a doctor. Nurses record each dose here.</p>}

          {chart.orders.length === 0 && <p className="muted">No orders written yet.</p>}
          {[...running, ...stopped].map((o) => (
            <div key={o.id} className={`order ${o.stopped_at ? 'stopped' : ''}`}>
              <div className="order-head">
                <span className="grow">
                  <strong>{o.title}</strong>
                  {o.kind === 'medicine' && <span> {[o.dose, o.route, o.frequency].filter(Boolean).join(' · ')}</span>}
                  {o.instructions && <span className="block muted">{o.instructions}</span>}
                  <span className="block muted small">
                    Ordered {at(o.started_at)} by {o.orderer?.name ?? 'a doctor'}
                    {o.stopped_at ? ` · stopped ${at(o.stopped_at)} by ${o.stopper?.name ?? 'a doctor'}` : ''}
                  </span>
                </span>
                {!o.stopped_at && o.kind === 'medicine' && write && (
                  <button className="btn" onClick={() => { setError(''); setGiving({ order: o, given_at: nowLocal(), status: 'given', remarks: '', repeat: false }) }}>
                    Record dose
                  </button>
                )}
                {!o.stopped_at && canOrder && chart.open && (
                  <button className="link danger" onClick={() => window.confirm(`Stop "${o.title}"? It stays on the chart as stopped.`) && run(api.post(`${url}/orders/${o.id}/stop`))}>
                    Stop
                  </button>
                )}
              </div>

              {giving?.order.id === o.id && (
                <form className="chart-form dose-form" onSubmit={saveDose}>
                  <label>
                    Time
                    <input type="datetime-local" required max={nowLocal()} value={giving.given_at} onChange={(e) => setGiving({ ...giving, given_at: e.target.value, repeat: false })} />
                  </label>
                  <label className="short">
                    What happened
                    <select value={giving.status} onChange={(e) => setGiving({ ...giving, status: e.target.value as Dose['status'] })}>
                      <option value="given">Given</option>
                      <option value="held">Held (not given)</option>
                      <option value="refused">Patient refused</option>
                    </select>
                  </label>
                  <label className="grow">
                    {giving.status === 'given' ? 'Note (optional)' : 'Reason *'}
                    <input maxLength={255} value={giving.remarks} onChange={(e) => setGiving({ ...giving, remarks: e.target.value })} />
                  </label>
                  <button className="btn primary">{giving.repeat ? 'Yes, a second dose was given' : 'Save'}</button>
                  <button type="button" className="link" onClick={() => setGiving(null)}>
                    Cancel
                  </button>
                </form>
              )}

              {o.doses.length > 0 && (
                <ul className="doses">
                  {o.doses.map((d) => (
                    <li key={d.id} className={d.status}>
                      <b>{DOSE_LABEL[d.status]}</b> {at(d.given_at)}
                      <span className="muted"> · {d.giver?.name ?? 'Staff'}</span>
                      {d.remarks && <span className="muted"> · {d.remarks}</span>}
                      {write && mine(userId, d.given_by, d.created_at) && (
                        <button className="link danger" onClick={() => window.confirm('Remove this entry? Only do this if it was entered by mistake.') && run(api.delete(`${url}/doses/${d.id}`))}>
                          Remove
                        </button>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          ))}
        </>
      )}

      {section === 'fluids' && (
        <>
          {write && (
            <form className="chart-form" onSubmit={saveFluid}>
              <label>
                Time
                <input type="datetime-local" required max={nowLocal()} value={fluid.recorded_at} onChange={(e) => setFluid({ ...fluid, recorded_at: e.target.value })} />
              </label>
              <label className="short">
                In or out
                <select value={fluid.direction} onChange={(e) => { const direction = e.target.value as 'in' | 'out'; setFluid({ ...fluid, direction, kind: chart.fluid_kinds[direction][0] }) }}>
                  <option value="in">Intake</option>
                  <option value="out">Output</option>
                </select>
              </label>
              <label className="short">
                What
                <select value={fluid.kind} onChange={(e) => setFluid({ ...fluid, kind: e.target.value })}>
                  {chart.fluid_kinds[fluid.direction].map((k) => (
                    <option key={k}>{k}</option>
                  ))}
                </select>
              </label>
              <label className="short">
                Amount (ml)
                <input type="number" required min={1} max={10000} inputMode="numeric" value={fluid.amount_ml} onChange={(e) => setFluid({ ...fluid, amount_ml: e.target.value })} />
              </label>
              <label className="grow">
                Note
                <input maxLength={255} value={fluid.notes} onChange={(e) => setFluid({ ...fluid, notes: e.target.value })} />
              </label>
              <button className="btn primary">Record</button>
            </form>
          )}
          {chart.fluid_days.length > 0 && (
            <div className="tiles small gap-bottom">
              {chart.fluid_days.slice(0, 4).map((d) => (
                <div key={d.date} className="tile">
                  <span>{d.date === today() ? 'Today' : longDate(d.date)}</span>
                  <strong>
                    {d.balance > 0 ? '+' : ''}
                    {d.balance} ml
                  </strong>
                  <small className="muted">
                    In {d.in} · Out {d.out}
                  </small>
                </div>
              ))}
            </div>
          )}
          <div className="table-wrap">
            <table className="compact">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>What</th>
                  <th className="num">Intake (ml)</th>
                  <th className="num">Output (ml)</th>
                  <th>Note</th>
                  <th>By</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {chart.fluids.map((f) => (
                  <tr key={f.id} className="static">
                    <td>{at(f.recorded_at)}</td>
                    <td>{f.kind}</td>
                    <td className="num">{f.direction === 'in' ? f.amount_ml : ''}</td>
                    <td className="num">{f.direction === 'out' ? f.amount_ml : ''}</td>
                    <td>{f.notes ?? ''}</td>
                    <td>{f.recorder?.name ?? '—'}</td>
                    <td className="num">
                      {write && mine(userId, f.recorded_by, f.created_at) && (
                        <button className="link danger" onClick={() => window.confirm('Remove this entry? Only do this if it was entered by mistake.') && run(api.delete(`${url}/fluids/${f.id}`))}>
                          Remove
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
                {chart.fluids.length === 0 && (
                  <tr className="empty">
                    <td colSpan={7}>Nothing recorded yet.</td>
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
