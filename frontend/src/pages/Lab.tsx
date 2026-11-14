import { useCallback, useEffect, useState } from 'react'
import { api } from '../api'
import { BillPanel } from '../components/BillPanel'
import { LabOrderForm } from '../components/LabOrderForm'
import { LabResults } from '../components/LabResults'
import { LabTabs } from '../components/LabTabs'
import { clock, longDate, rupees, settlement, STAGE_CLASS, STAGE_LABEL, today } from '../format'
import type { LabCatalogueTest, LabOrder, LabPackage, Service } from '../types'

type Panel = { kind: 'new' } | { kind: 'results'; orderId: number } | { kind: 'bill'; billId: number } | null

const STATUS = { pending: 'Pending', partial: 'Partly reported', completed: 'Completed' } as const
const STATUS_CLASS = { pending: 'status-arrived', partial: 'status-ongoing', completed: 'ok' } as const

export function Lab() {
  const [date, setDate] = useState(today())
  const [query, setQuery] = useState('')
  const [orders, setOrders] = useState<LabOrder[] | null>(null)
  const [tests, setTests] = useState<LabCatalogueTest[]>([])
  const [services, setServices] = useState<Service[]>([])
  const [packages, setPackages] = useState<LabPackage[]>([])
  const [showTests, setShowTests] = useState(true)
  const [panel, setPanel] = useState<Panel>(null)
  const [notice, setNotice] = useState('')

  const searching = query.trim().length >= 2

  const load = useCallback(() => {
    api.get<LabOrder[]>('/lab/orders', { params: searching ? { q: query.trim() } : { date } }).then((res) => setOrders(res.data))
  }, [date, query, searching])

  useEffect(() => {
    const timer = setTimeout(load, 250)
    return () => clearTimeout(timer)
  }, [load])

  useEffect(() => {
    api.get<LabCatalogueTest[]>('/lab/tests').then((res) => setTests(res.data))
    api.get<LabPackage[]>('/lab/packages').then((res) => setPackages(res.data))
    api.get<Service[]>('/services').then((res) => setServices(res.data))
  }, [])

  return (
    <>
    <LabTabs />
    <div className="split">
      <section className="card">
        <div className="toolbar">
          <h1>Lab</h1>
          <input type="date" className="date" value={date} disabled={searching} onChange={(e) => setDate(e.target.value || today())} aria-label="Day" />
          {date !== today() && !searching && (
            <button className="btn ghost" onClick={() => setDate(today())}>
              Today
            </button>
          )}
          <input className="search" type="search" placeholder="Find reports by patient name, mobile, ID or order no. (all dates)" value={query} onChange={(e) => setQuery(e.target.value)} />
          <button className="btn ghost" onClick={() => setShowTests((s) => !s)}>
            {showTests ? 'Hide tests' : 'Show tests'}
          </button>
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

        {notice && <p className="notice">{notice}</p>}

        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Order</th>
                <th>Patient</th>
                <th>Tests</th>
                <th>Status</th>
                <th className="num">Bill</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {orders?.map((o) => (
                <tr key={o.id} className={`static ${panel?.kind === 'results' && panel.orderId === o.id ? 'selected' : ''}`}>
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
                    {showTests ? (
                      o.items.map((i) => (
                        <span key={i.id} className="lab-line">
                          {i.test_name} <span className={`pill ${STAGE_CLASS[i.stage]}`}>{STAGE_LABEL[i.stage]}</span>
                        </span>
                      ))
                    ) : (
                      <>
                        {o.items.length} test{o.items.length === 1 ? '' : 's'}
                        <small className="muted block">{o.items.filter((i) => i.stage === 'approved').length} approved</small>
                      </>
                    )}
                  </td>
                  <td>
                    <span className={`pill ${STATUS_CLASS[o.status]}`}>{STATUS[o.status]}</span>
                  </td>
                  <td className="num">
                    {o.bill && (
                      <>
                        <button className="link" onClick={() => setPanel({ kind: 'bill', billId: o.bill!.id })}>
                          {rupees(o.bill.total)}
                        </button>
                        <small className={`block ${o.bill.due > 0 ? 'due' : 'settled'}`}>{settlement(o.bill.due, o.bill.moved_to_ipd, o.bill.total)}</small>
                      </>
                    )}
                  </td>
                  <td className="num">
                    <button className={o.status === 'completed' ? 'btn ghost' : 'btn primary'} onClick={() => setPanel({ kind: 'results', orderId: o.id })}>
                      Results
                    </button>
                  </td>
                </tr>
              ))}
              {orders && orders.length === 0 && (
                <tr className="empty">
                  <td colSpan={6}>{searching ? 'No lab orders match this search.' : 'No lab orders for this day.'}</td>
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
      {panel?.kind === 'results' && <LabResults key={panel.orderId} orderId={panel.orderId} onClose={() => setPanel(null)} onChange={load} />}
      {panel?.kind === 'bill' && <BillPanel key={panel.billId} billId={panel.billId} services={services} onClose={() => setPanel(null)} onChange={load} />}
    </div>
    </>
  )
}
