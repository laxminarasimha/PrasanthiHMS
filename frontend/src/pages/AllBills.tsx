import { FrontDeskTabs } from '../components/FrontDeskTabs'
import { MoneyByMode } from '../components/MoneyByMode'
import { useCallback, useEffect, useState } from 'react'
import { useLoadOnChange } from '../loading'
import { useRangeInAddress } from '../rangeInAddress'
import { useSearchParams } from 'react-router-dom'
import { api } from '../api'
import { BillPanel } from '../components/BillPanel'
import { DateRange } from '../components/DateRange'
import { fullName, rupees, settlement, stampDate, stampTime } from '../format'
import type { BillKind, Patient, Service } from '../types'
import { PatientLink } from '../components/PatientLink'

type Row = {
  id: number
  bill_no: string
  kind: BillKind
  total: number
  paid: number
  due: number
  moved_to_ipd: number | null
  created_at: string
  cancelled_at?: string | null
  cancel_reason?: string | null
  patient: Pick<Patient, 'id' | 'uhid' | 'name' | 'title' | 'mobile'> | null
  appointment: { doctor: { name: string } | null } | null
  lab_order: { order_no: string } | null
}

const KINDS: [BillKind | '', string][] = [['', 'All'], ['appointment', 'Appointments'], ['lab', 'Lab'], ['other', 'Other services']]
const KIND_LABEL: Record<BillKind, string> = { appointment: 'Appointment', lab: 'Lab', other: 'Other services' }

/** Every front desk and lab bill in a date range, or found by a search across all dates. */
export function AllBills() {
  const [from, to, setRange] = useRangeInAddress('today')
  const [query, setQuery] = useState('')
  const [kind, setKind] = useState<BillKind | ''>('')
  const [dueOnly, setDueOnly] = useState(false)
  const [bills, setBills] = useState<Row[] | null>(null)
  const [services, setServices] = useState<Service[]>([])
  // Coming from a patient's page, the bill named in the address opens straight away.
  const [params] = useSearchParams()
  const [open, setOpen] = useState<number | null>(Number(params.get('open')) || null)
  const searching = query.trim().length >= 2

  const load = useCallback(() => {
    api.get<Row[]>('/bills', { params: { ...(searching ? { q: query.trim() } : { from, to }), kind: kind || undefined, due: dueOnly ? 1 : undefined } }).then((res) => setBills(res.data))
  }, [from, to, query, searching, kind, dueOnly])

  // At once when the screen opens; after a short pause when a search or filter changes.
  useLoadOnChange(load)
  useEffect(() => {
    api.get<Service[]>('/services').then((res) => setServices(res.data))
  }, [])
  // Money that came in at the desk in the dates shown, by how it was paid. A search runs over all dates, so it has none.
  const [received, setReceived] = useState<{ key: string; amounts: Record<string, number> } | null>(null)
  useEffect(() => {
    api.get<{ received: Record<string, number> }>('/front-desk/money', { params: { from, to } }).then((res) => setReceived({ key: `${from}|${to}`, amounts: res.data.received })).catch(() => undefined)
  }, [from, to, bills])

  const sum = (key: 'total' | 'paid' | 'due') => Math.round((bills ?? []).reduce((total, b) => total + b[key], 0) * 100) / 100

  return (
    <>
    <FrontDeskTabs />
    <div className="split">
      <section className="card">
        <div className="toolbar">
          <h1>All bills</h1>
          {!searching && <DateRange from={from} to={to} onChange={setRange} />}
          <input className="search" type="search" placeholder="Find by bill no., patient name, mobile or ID (all dates)" value={query} onChange={(e) => setQuery(e.target.value)} />
        </div>
        <div className="tabs">
          {KINDS.map(([value, label]) => (
            <button key={value} className={kind === value ? 'active' : ''} onClick={() => setKind(value)}>
              {label}
            </button>
          ))}
          <label className="check flush push">
            <input type="checkbox" checked={dueOnly} onChange={(e) => setDueOnly(e.target.checked)} />
            Only bills with money due
          </label>
        </div>
        <div className="tiles small">
          <div className="tile"><span>Bills</span><strong>{bills?.length ?? 0}</strong></div>
          <div className="tile"><span>Billed</span><strong>{rupees(sum('total'))}</strong></div>
          <div className="tile"><span>Paid</span><strong>{rupees(sum('paid'))}</strong></div>
          <div className="tile"><span>Due</span><strong className={sum('due') > 0 ? 'due' : ''}>{rupees(sum('due'))}</strong></div>
        </div>
        {!searching && received?.key === `${from}|${to}` && (
          <MoneyByMode amounts={received.amounts} title="Money received in these dates, by how it was paid" note="At the front desk and lab, on bills and as deposits, less refunds. It counts money by the day it came in, so it can differ from “Paid”, which is about the bills listed." />
        )}
        <div className="table-wrap">
          {/* Long names wrap inside their column, so the list fits the screen without scrolling sideways. */}
          <table className="fit-table">
            <thead>
              <tr>
                <th>Bill</th>
                <th>Patient</th>
                <th>For</th>
                <th className="num">Total</th>
                <th className="num">Paid</th>
                <th className="num">Due</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {bills?.map((b) => (
                <tr key={b.id} className={`static ${open === b.id ? 'selected' : ''}`}>
                  <td>
                    <button className="link mono" onClick={() => setOpen(b.id)}>
                      {b.bill_no}
                    </button>
                    <small className="muted block">
                      {stampDate(b.created_at)} {stampTime(b.created_at)}
                    </small>
                  </td>
                  <td className="fit-wrap">
                    {b.patient ? (
                      <>
                        <strong>
                          <PatientLink id={b.patient.id} bills>
                            {fullName(b.patient)}
                          </PatientLink>
                        </strong>
                        <small className="muted block">
                          {b.patient.uhid} · {b.patient.mobile}
                        </small>
                      </>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td className="fit-wrap">
                    {KIND_LABEL[b.kind]}
                    <small className="muted block">{b.lab_order ? `Order ${b.lab_order.order_no}` : (b.appointment?.doctor?.name ?? '')}</small>
                  </td>
                  <td className="num">{rupees(b.total)}</td>
                  <td className="num">{rupees(b.paid)}</td>
                  <td className={`num ${b.due > 0 ? 'due strong' : 'settled'}`}>{b.cancelled_at ? <span className="pill off" title={b.cancel_reason ?? ''}>Cancelled</span> : settlement(b.due, b.moved_to_ipd, b.total)}</td>
                  <td className="num actions">
                    <a className="link" href={`/bills/${b.id}/print`} target="_blank" rel="noreferrer">
                      Print
                    </a>
                  </td>
                </tr>
              ))}
              {bills && bills.length === 0 && (
                <tr className="empty">
                  <td colSpan={7}>{searching ? 'No bills match this search.' : 'No bills in this period.'}</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        {bills && bills.length === 500 && <p className="muted small">Showing the newest 500. Narrow the dates or search to see others.</p>}
      </section>

      {open && <BillPanel key={open} billId={open} services={services} onClose={() => setOpen(null)} onChange={load} />}
    </div>
    </>
  )
}
