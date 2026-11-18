import { useCallback, useEffect, useRef, useState } from 'react'
import { api, fieldErrors } from '../api'
import { canLeave } from '../unsaved'
import { useAuth } from '../auth'
import { BillPanel } from '../components/BillPanel'
import { LabOrderForm } from '../components/LabOrderForm'
import { LabResults } from '../components/LabResults'
import { LabTabs } from '../components/LabTabs'
import { clock, longDate, rupees, settlement, STAGE_CLASS, STAGE_LABEL, today } from '../format'
import type { LabCatalogueTest, LabOrder, LabPackage, LabSettings, Service } from '../types'

type Panel = { kind: 'new' } | { kind: 'results'; orderId: number } | { kind: 'bill'; billId: number } | null

/** The one thing an order is waiting for next. */
type Step = 'collect' | 'report' | 'approve' | 'ready'
const STEPS: Step[] = ['collect', 'report', 'approve', 'ready']
const STEP_LABEL = { collect: 'Sample to collect', report: 'Results to enter', approve: 'To approve', ready: 'Ready' } as const
const STEP_CLASS = { collect: 'status-arrived', report: 'status-ongoing', approve: 'status-booked', ready: 'ok' } as const

const stepOf = (order: LabOrder, approval: boolean): Step =>
  order.items.some((i) => i.stage === 'booked')
    ? 'collect'
    : order.items.some((i) => i.stage === 'collected')
      ? 'report'
      : approval && order.items.some((i) => i.stage === 'reported')
        ? 'approve'
        : 'ready'

const minutesBetween = (from: string, to: number) => Math.max(0, Math.round((to - new Date(from.replace(' ', 'T')).getTime()) / 60000))
const span = (minutes: number) => (minutes < 60 ? `${minutes} min` : minutes < 1440 ? `${Math.floor(minutes / 60)} h ${minutes % 60} min` : `${Math.floor(minutes / 1440)} d ${Math.floor((minutes % 1440) / 60)} h`)

export function Lab() {
  const { user } = useAuth()
  const canRun = Boolean(user?.can_run_lab)
  const [date, setDate] = useState(today())
  const [query, setQuery] = useState('')
  const [orders, setOrders] = useState<LabOrder[] | null>(null)
  const [tests, setTests] = useState<LabCatalogueTest[]>([])
  const [services, setServices] = useState<Service[]>([])
  const [packages, setPackages] = useState<LabPackage[]>([])
  const [approval, setApproval] = useState(false)
  const [step, setStep] = useState<Step | ''>('')
  const [panel, setPanel] = useState<Panel>(null)
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(0)
  // The time the list was last fetched: waiting times are counted up to it.
  const [asOf, setAsOf] = useState(0)
  const searchBox = useRef<HTMLInputElement>(null)

  const searching = query.trim().length >= 2

  const load = useCallback(() => {
    api.get<LabOrder[]>('/lab/orders', { params: searching ? { q: query.trim() } : { date } }).then((res) => {
      setOrders(res.data)
      setAsOf(Date.now())
    })
  }, [date, query, searching])

  useEffect(() => {
    const timer = setTimeout(load, 250)
    // Keep in step with the front desk and the doctors, who add orders from their own screens.
    const refresh = setInterval(load, 60000)
    return () => {
      clearTimeout(timer)
      clearInterval(refresh)
    }
  }, [load])

  useEffect(() => {
    api.get<LabCatalogueTest[]>('/lab/tests').then((res) => setTests(res.data))
    api.get<LabPackage[]>('/lab/packages').then((res) => setPackages(res.data))
    api.get<Service[]>('/services').then((res) => setServices(res.data))
    api.get<LabSettings>('/lab/settings').then((res) => setApproval(res.data.approval_required))
  }, [])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const typing = e.target instanceof Element && e.target.closest('input, textarea, select, [contenteditable]')
      if (e.key === '/' && !typing && !e.ctrlKey && !e.metaKey) {
        e.preventDefault()
        searchBox.current?.focus()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  const rows = (orders ?? []).map((order) => ({ order, step: stepOf(order, approval) }))
  const counts = Object.fromEntries(STEPS.map((s) => [s, rows.filter((r) => r.step === s).length])) as Record<Step, number>
  const shown = step ? rows.filter((r) => r.step === step) : rows
  const steps = STEPS.filter((s) => s !== 'approve' || approval || counts.approve > 0)

  /** The sample for every test on the order, in one tap from the list. */
  const collect = async (order: LabOrder) => {
    setBusy(order.id)
    setNotice('')
    try {
      await api.put(`/lab/orders/${order.id}/collect`, { all: true })
      setNotice(`Sample collected for ${order.patient.name} (${order.order_no}).`)
      load()
    } catch (err) {
      setNotice(Object.values(fieldErrors(err))[0] ?? 'Could not save. Please try again.')
    } finally {
      setBusy(0)
    }
  }

  return (
    <>
    <LabTabs />
    <div className="split">
      <section className={`card lab-list ${panel ? 'beside-panel' : ''}`}>
        <div className="toolbar">
          <h1>Lab</h1>
          <input type="date" className="date" value={date} disabled={searching} onChange={(e) => setDate(e.target.value || today())} aria-label="Day" />
          {date !== today() && !searching && (
            <button className="btn ghost" onClick={() => setDate(today())}>
              Today
            </button>
          )}
          <input ref={searchBox} className="search" type="search" placeholder="Find reports by patient name, mobile, ID or order no. (all dates) — press /" value={query} onChange={(e) => setQuery(e.target.value)} />
          <button
            className="btn primary"
            onClick={() => {
              setNotice('')
              setPanel({ kind: 'new' })
            }}
          >
            + New lab order
          </button>
        </div>

        <div className="desk-filters">
          <div className="tabs flush" role="group" aria-label="What the order is waiting for">
            <button className={step === '' ? 'active' : ''} onClick={() => setStep('')}>
              All <span>{rows.length}</span>
            </button>
            {steps.map((s) => (
              <button key={s} className={step === s ? 'active' : ''} onClick={() => setStep(s)}>
                {STEP_LABEL[s]} <span>{counts[s]}</span>
              </button>
            ))}
          </div>
        </div>

        {notice && <p className="notice">{notice}</p>}

        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Order</th>
                <th>Patient</th>
                <th>Tests</th>
                <th>Next step</th>
                <th className="num col-bill">Bill</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {shown.map(({ order: o, step: next }) => {
                const open = () => (panel?.kind === 'results' && panel.orderId === o.id) || (canLeave() && setPanel({ kind: 'results', orderId: o.id }))
                const done = o.items.map((i) => i.reported_at).filter(Boolean).sort().pop()
                return (
                  <tr key={o.id} className={`${next === 'ready' ? 'done' : ''} ${panel?.kind === 'results' && panel.orderId === o.id ? 'selected' : ''}`} onClick={open}>
                    <td>
                      <strong className="mono">{o.order_no}</strong>
                      <small className="muted block">
                        {searching ? `${longDate(o.ordered_at)} ` : ''}
                        {clock(o.ordered_at)}
                      </small>
                    </td>
                    <td>
                      <strong>{o.patient.name}</strong>
                      <small className="muted block">
                        {o.patient.uhid} · {o.patient.age_label} / {o.patient.gender.charAt(0).toUpperCase()}
                      </small>
                    </td>
                    <td className="wrap">
                      {o.items.map((i) => (
                        <span key={i.id} className="lab-line" title={STAGE_LABEL[i.stage]}>
                          <i className={`stage-dot ${STAGE_CLASS[i.stage]}`} aria-hidden="true" />
                          {i.test_name}
                          <span className="sr-only"> ({STAGE_LABEL[i.stage]})</span>
                        </span>
                      ))}
                    </td>
                    <td>
                      <span className={`pill ${STEP_CLASS[next]}`}>{STEP_LABEL[next]}</span>
                      {next !== 'ready' && !searching && date === today() && asOf > 0 && <small className="waiting block">Waiting {span(minutesBetween(o.ordered_at, asOf))}</small>}
                      {next === 'ready' && done && <small className="muted block">Took {span(minutesBetween(o.ordered_at, new Date(done.replace(' ', 'T')).getTime()))}</small>}
                    </td>
                    <td className="num col-bill" onClick={(e) => e.stopPropagation()}>
                      {o.bill && (
                        <>
                          <button className="link" onClick={() => canLeave() && setPanel({ kind: 'bill', billId: o.bill!.id })}>
                            {rupees(o.bill.total)}
                          </button>
                          <small className={`block ${o.bill.due > 0 ? 'due' : 'settled'}`}>{settlement(o.bill.due, o.bill.moved_to_ipd, o.bill.total)}</small>
                        </>
                      )}
                    </td>
                    <td className="num" onClick={(e) => e.stopPropagation()}>
                      {next === 'collect' && canRun ? (
                        <button className="btn primary" disabled={busy === o.id} onClick={() => collect(o)} title="Marks the sample taken for every test on this order">
                          {busy === o.id ? 'Saving…' : 'Mark collected'}
                        </button>
                      ) : (
                        <button className={next === 'ready' || !canRun ? 'btn' : 'btn primary'} onClick={open}>
                          {!canRun ? 'Results' : next === 'report' ? 'Enter results' : next === 'approve' ? 'Approve' : 'View / print'}
                        </button>
                      )}
                    </td>
                  </tr>
                )
              })}
              {orders && shown.length === 0 && (
                <tr className="empty">
                  <td colSpan={6}>{searching ? 'No lab orders match this search.' : step && rows.length > 0 ? `Nothing is waiting under "${STEP_LABEL[step]}".` : 'No lab orders for this day.'}</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>

      {panel?.kind === 'new' && (
        <LabOrderForm
          tests={tests}
          packages={packages}
          onClose={() => setPanel(null)}
          onCreated={(order) => {
            setDate(today())
            setQuery('')
            setNotice(`Order ${order.order_no} created for ${order.patient.name}.`)
            // Go straight to the bill so payment can be collected before the sample is taken.
            setPanel(order.bill ? { kind: 'bill', billId: order.bill.id } : null)
            load()
          }}
        />
      )}
      {panel?.kind === 'results' && <LabResults key={panel.orderId} orderId={panel.orderId} onClose={() => canLeave() && setPanel(null)} onChange={load} />}
      {panel?.kind === 'bill' && <BillPanel key={panel.billId} billId={panel.billId} services={services} onClose={() => setPanel(null)} onChange={load} />}
    </div>
    </>
  )
}
