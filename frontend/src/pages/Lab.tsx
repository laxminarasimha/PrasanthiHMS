import { useCallback, useEffect, useRef, useState } from 'react'
import { api, fieldErrors } from '../api'
import { canLeave } from '../unsaved'
import { useAuth } from '../auth'
import { BillPanel } from '../components/BillPanel'
import { LabDay } from '../components/LabDay'
import { LabOrderForm } from '../components/LabOrderForm'
import { LabResults } from '../components/LabResults'
import { LabTabs } from '../components/LabTabs'
import { clock, longDate, rupees, settlement, STAGE_CLASS, STAGE_LABEL, today } from '../format'
import { earliestStep, STEP_CLASS, STEP_LABEL, stepOf, STEPS, type Step } from './labSteps'
import type { LabCatalogueTest, LabOrder, LabPackage, LabSettings, Service } from '../types'

/** `day` is the window with all of one patient's orders of a day; its key names the patient and the day. */
type Panel = { kind: 'new' } | { kind: 'results'; orderId: number } | { kind: 'bill'; billId: number } | { kind: 'day'; key: string } | null

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
  const [settings, setSettings] = useState<LabSettings | null>(null)
  const approval = Boolean(settings?.approval_required)
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
    api.get<LabSettings>('/lab/settings').then((res) => setSettings(res.data))
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

  // One patient, one row: everything ordered for a patient on a day is worked on together, as on the front desk.
  const byPatient = new Map<string, LabOrder[]>()
  for (const order of orders ?? []) {
    const key = `${order.patient.id}-${order.ordered_at.slice(0, 10)}`
    byPatient.set(key, [...(byPatient.get(key) ?? []), order])
  }
  const rows = [...byPatient.entries()].map(([key, list]) => {
    const sorted = [...list].sort((a, b) => a.ordered_at.localeCompare(b.ordered_at) || a.id - b.id)
    const each = sorted.map((order) => stepOf(order, approval))
    return { key, orders: sorted, each, step: earliestStep(each) }
  })
  // A patient is listed under every step one of their orders is waiting at.
  const counts = Object.fromEntries(STEPS.map((s) => [s, rows.filter((r) => r.each.includes(s)).length])) as Record<Step, number>
  const shown = step ? rows.filter((r) => r.each.includes(step)) : rows
  const steps = STEPS.filter((s) => s !== 'approve' || approval || counts.approve > 0)
  const dayOrders = panel?.kind === 'day' ? rows.find((r) => r.key === panel.key)?.orders : undefined

  /** The sample for every test the patient is waiting to give, in one tap from the list. */
  const collect = async (waiting: LabOrder[]) => {
    setBusy(waiting[0].id)
    setNotice('')
    try {
      for (const order of waiting) await api.put(`/lab/orders/${order.id}/collect`, { all: true })
      setNotice(`Sample collected for ${waiting[0].patient.name} (${waiting.map((o) => o.order_no).join(', ')}).`)
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
      <section className={`card lab-list ${panel && panel.kind !== 'day' ? 'beside-panel' : ''}`}>
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
              {shown.map(({ key, orders: list, each, step: next }) => {
                const o = list[0]
                const many = list.length > 1
                // One order opens its results as before; several open the patient's window with all of them.
                const isOpen = many ? panel?.kind === 'day' && panel.key === key : panel?.kind === 'results' && panel.orderId === o.id
                const open = () => isOpen || (canLeave() && setPanel(many ? { kind: 'day', key } : { kind: 'results', orderId: o.id }))
                const items = list.flatMap((order) => order.items)
                const done = items.map((i) => i.reported_at).filter(Boolean).sort().pop()
                const toCollect = list.filter((_, i) => each[i] === 'collect')
                const pending = list.find((_, i) => each[i] !== 'ready') ?? o
                const bills = list.flatMap((order) => (order.bill ? [order.bill] : []))
                const total = bills.reduce((sum, b) => sum + b.total, 0)
                const due = Math.round(bills.reduce((sum, b) => sum + b.due, 0) * 100) / 100
                return (
                  <tr key={key} className={`${next === 'ready' ? 'done' : ''} ${isOpen ? 'selected' : ''}`} onClick={open}>
                    <td>
                      {list.map((order) => (
                        <span key={order.id} className="block">
                          <strong className="mono">{order.order_no}</strong>{' '}
                          <small className="muted">
                            {searching ? `${longDate(order.ordered_at)} ` : ''}
                            {clock(order.ordered_at)}
                          </small>
                        </span>
                      ))}
                    </td>
                    <td>
                      <strong>{o.patient.name}</strong>
                      <small className="muted block">
                        {o.patient.uhid} · {o.patient.age_label} / {o.patient.gender.charAt(0).toUpperCase()}
                        {many ? ` · ${list.length} orders` : ''}
                      </small>
                    </td>
                    <td className="wrap">
                      {items.map((i) => (
                        <span key={i.id} className="lab-line" title={STAGE_LABEL[i.stage]}>
                          <i className={`stage-dot ${STAGE_CLASS[i.stage]}`} aria-hidden="true" />
                          {i.test_name}
                          <span className="sr-only"> ({STAGE_LABEL[i.stage]})</span>
                        </span>
                      ))}
                    </td>
                    <td>
                      <span className={`pill ${STEP_CLASS[next]}`}>{STEP_LABEL[next]}</span>
                      {next !== 'ready' && !searching && date === today() && asOf > 0 && <small className="waiting block">Waiting {span(minutesBetween(pending.ordered_at, asOf))}</small>}
                      {next === 'ready' && done && <small className="muted block">Took {span(minutesBetween(o.ordered_at, new Date(done.replace(' ', 'T')).getTime()))}</small>}
                    </td>
                    <td className="num col-bill" onClick={(e) => e.stopPropagation()}>
                      {bills.length > 0 && (
                        <>
                          <button className="link" onClick={() => canLeave() && setPanel(many ? { kind: 'day', key } : { kind: 'bill', billId: bills[0].id })}>
                            {rupees(total)}
                          </button>
                          <small className={`block ${due > 0 ? 'due' : 'settled'}`}>{many ? (due > 0 ? `${rupees(due)} due` : 'Paid') : settlement(bills[0].due, bills[0].moved_to_ipd, bills[0].total)}</small>
                        </>
                      )}
                    </td>
                    <td className="num" onClick={(e) => e.stopPropagation()}>
                      {toCollect.length > 0 && canRun ? (
                        <button className="btn primary" disabled={busy === toCollect[0].id} onClick={() => collect(toCollect)} title="Marks the sample taken for every test this patient is waiting to give">
                          {busy === toCollect[0].id ? 'Saving…' : 'Mark collected'}
                        </button>
                      ) : (
                        <button className={next === 'ready' || !canRun ? 'btn' : 'btn primary'} onClick={open}>
                          {many ? 'Open all' : !canRun ? 'Results' : next === 'report' ? 'Enter results' : next === 'approve' ? 'Approve' : 'View / print'}
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
      {dayOrders && (
        <LabDay
          orders={dayOrders}
          settings={settings}
          canRun={canRun}
          onClose={() => setPanel(null)}
          onChange={load}
          onOpenResults={(orderId) => setPanel({ kind: 'results', orderId })}
          onOpenBill={(billId) => setPanel({ kind: 'bill', billId })}
        />
      )}
    </div>
    </>
  )
}
