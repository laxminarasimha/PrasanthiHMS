import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { BillPanel } from '../components/BillPanel'
import { DateRange } from '../components/DateRange'
import { clock, fullName, longDate, rupees, settlement, today } from '../format'
import type { BillKind, Patient, Service } from '../types'

type Row = {
  id: number
  bill_no: string
  kind: BillKind
  total: number
  paid: number
  due: number
  moved_to_ipd: number | null
  created_at: string
  patient: Pick<Patient, 'id' | 'uhid' | 'name' | 'title' | 'mobile'> | null
  appointment: { doctor: { name: string } | null } | null
  lab_order: { order_no: string } | null
}

const KINDS: [BillKind | '', string][] = [['', 'All'], ['appointment', 'Appointments'], ['lab', 'Lab'], ['other', 'Other services']]
const KIND_LABEL: Record<BillKind, string> = { appointment: 'Appointment', lab: 'Lab', other: 'Other services' }

/** Every front desk and lab bill in a date range, or found by a search across all dates. */
export function AllBills() {
  const [from, setFrom] = useState(today())
  const [to, setTo] = useState(today())
  const [query, setQuery] = useState('')
  const [kind, setKind] = useState<BillKind | ''>('')
  const [dueOnly, setDueOnly] = useState(false)
  const [bills, setBills] = useState<Row[] | null>(null)
  const [services, setServices] = useState<Service[]>([])
  const [open, setOpen] = useState<number | null>(null)
  const searching = query.trim().length >= 2

  const load = useCallback(() => {
    api.get<Row[]>('/bills', { params: { ...(searching ? { q: query.trim() } : { from, to }), kind: kind || undefined, due: dueOnly ? 1 : undefined } }).then((res) => setBills(res.data))
  }, [from, to, query, searching, kind, dueOnly])

  useEffect(() => {
    const timer = setTimeout(load, 250)
    return () => clearTimeout(timer)
  }, [load])
  useEffect(() => {
    api.get<Service[]>('/services').then((res) => setServices(res.data))
  }, [])

  const sum = (key: 'total' | 'paid' | 'due') => Math.round((bills ?? []).reduce((total, b) => total + b[key], 0) * 100) / 100

  return (
    <div className="split">
      <section className="card">
        <div className="toolbar">
          <Link className="btn ghost" to="/front-desk">
            ← Day list
          </Link>
          <h1>All bills</h1>
          {!searching && <DateRange from={from} to={to} onChange={(f, t) => { setFrom(f); setTo(t) }} />}
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
        <div className="table-wrap">
          <table>
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
                      {longDate(b.created_at)} {clock(b.created_at)}
                    </small>
                  </td>
                  <td>
                    {b.patient ? (
                      <>
                        <strong>{fullName(b.patient)}</strong>
                        <small className="muted block">
                          {b.patient.uhid} · {b.patient.mobile}
                        </small>
                      </>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td>
                    {KIND_LABEL[b.kind]}
                    <small className="muted block">{b.lab_order ? `Order ${b.lab_order.order_no}` : (b.appointment?.doctor?.name ?? '')}</small>
                  </td>
                  <td className="num">{rupees(b.total)}</td>
                  <td className="num">{rupees(b.paid)}</td>
                  <td className={`num ${b.due > 0 ? 'due strong' : 'settled'}`}>{settlement(b.due, b.moved_to_ipd, b.total)}</td>
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
  )
}
