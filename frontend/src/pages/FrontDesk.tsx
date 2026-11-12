import { useCallback, useEffect, useState } from 'react'
import { api } from '../api'
import { AppointmentForm } from '../components/AppointmentForm'
import { BillPanel } from '../components/BillPanel'
import { STATUS_LABELS, clock, rupees, today } from '../format'
import type { Appointment, AppointmentStatus, Bill, Doctor, Service } from '../types'

type DayList = { data: Appointment[]; counts: Partial<Record<AppointmentStatus, number>> }
type Panel = { kind: 'new' } | { kind: 'bill'; billId: number } | null

const STATUSES = Object.keys(STATUS_LABELS) as AppointmentStatus[]

export function FrontDesk() {
  const [date, setDate] = useState(today())
  const [status, setStatus] = useState<AppointmentStatus | ''>('')
  const [doctorId, setDoctorId] = useState('')
  const [query, setQuery] = useState('')
  const [list, setList] = useState<DayList | null>(null)
  const [doctors, setDoctors] = useState<Doctor[]>([])
  const [services, setServices] = useState<Service[]>([])
  const [panel, setPanel] = useState<Panel>(null)
  const [notice, setNotice] = useState('')

  const load = useCallback(() => {
    api
      .get<DayList>('/appointments', { params: { date, status: status || undefined, doctor_id: doctorId || undefined, q: query || undefined } })
      .then((res) => setList(res.data))
  }, [date, status, doctorId, query])

  useEffect(() => {
    const timer = setTimeout(load, 200)
    return () => clearTimeout(timer)
  }, [load])

  useEffect(() => {
    api.get<Doctor[]>('/doctors').then((res) => setDoctors(res.data))
    api.get<Service[]>('/services').then((res) => setServices(res.data))
  }, [])

  const changeStatus = async (appointment: Appointment, next: AppointmentStatus) => {
    await api.put(`/appointments/${appointment.id}`, { status: next })
    load()
  }

  const openBill = async (appointment: Appointment) => {
    setNotice('')
    if (appointment.bills.length > 0) {
      setPanel({ kind: 'bill', billId: appointment.bills[0].id })
      return
    }
    // Visits booked without a service have no bill yet; start an empty one.
    const res = await api.post<Bill>('/bills', { patient_id: appointment.patient.id, appointment_id: appointment.id })
    setPanel({ kind: 'bill', billId: res.data.id })
    load()
  }

  const total = list ? Object.values(list.counts).reduce((sum, n) => sum + (n ?? 0), 0) : 0

  return (
    <div className="split">
      <section className="card">
        <div className="toolbar">
          <h1>Front Desk</h1>
          <input type="date" className="date" value={date} onChange={(e) => setDate(e.target.value || today())} aria-label="Day" />
          {date !== today() && (
            <button className="btn ghost" onClick={() => setDate(today())}>
              Today
            </button>
          )}
          <input className="search" type="search" placeholder="Filter by patient name, mobile or ID" value={query} onChange={(e) => setQuery(e.target.value)} />
          {doctors.length > 1 && (
            <select className="narrow" value={doctorId} onChange={(e) => setDoctorId(e.target.value)} aria-label="Doctor">
              <option value="">All doctors</option>
              {doctors.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </select>
          )}
          <button
            className="btn primary"
            onClick={() => {
              setNotice('')
              setPanel({ kind: 'new' })
            }}
          >
            + New appointment
          </button>
        </div>

        <div className="tabs">
          <button className={status === '' ? 'active' : ''} onClick={() => setStatus('')}>
            All <span>{total}</span>
          </button>
          {STATUSES.map((s) => (
            <button key={s} className={status === s ? 'active' : ''} onClick={() => setStatus(s)}>
              {STATUS_LABELS[s]} <span>{list?.counts[s] ?? 0}</span>
            </button>
          ))}
        </div>

        {notice && <p className="notice">{notice}</p>}

        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Time</th>
                <th>Patient</th>
                <th>Doctor</th>
                <th>Service</th>
                <th>Status</th>
                <th className="num">Bill</th>
              </tr>
            </thead>
            <tbody>
              {list?.data.map((a) => {
                const due = a.bills.reduce((sum, b) => sum + b.due, 0)
                return (
                  <tr key={a.id} className={`static ${a.status === 'cancelled' ? 'faded' : ''}`}>
                    <td className="mono">{clock(a.scheduled_at)}</td>
                    <td>
                      <strong>{a.patient.name}</strong>
                      <small className="muted block">
                        {a.patient.uhid} · {a.patient.age}y / {a.patient.gender.charAt(0).toUpperCase()}
                      </small>
                    </td>
                    <td>{a.doctor.name}</td>
                    <td>{a.service?.name ?? '—'}</td>
                    <td>
                      <select
                        className={`status ${a.status}`}
                        value={a.status}
                        onChange={(e) => changeStatus(a, e.target.value as AppointmentStatus)}
                        aria-label={`Status for ${a.patient.name}`}
                      >
                        {STATUSES.map((s) => (
                          <option key={s} value={s}>
                            {STATUS_LABELS[s]}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td className="num">
                      {a.bills.length === 0 && (
                        <button className="link" onClick={() => openBill(a)}>
                          Create bill
                        </button>
                      )}
                      {a.bills.map((b) => (
                        <button key={b.id} className="link block-link" onClick={() => setPanel({ kind: 'bill', billId: b.id })}>
                          {b.lab_order ? `Lab ${b.lab_order.order_no} · ` : ''}
                          {rupees(b.total)}
                        </button>
                      ))}
                      {a.bills.length > 0 && (
                        <small className={`block ${due > 0 ? 'due' : 'settled'}`}>{due > 0 ? `${rupees(due)} due` : 'Paid'}</small>
                      )}
                    </td>
                  </tr>
                )
              })}
              {list && list.data.length === 0 && (
                <tr className="empty">
                  <td colSpan={6}>No appointments {status ? `marked ${STATUS_LABELS[status]} ` : ''}for this day.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>

      {panel?.kind === 'new' && (
        <AppointmentForm
          date={date}
          doctors={doctors}
          services={services}
          onClose={() => setPanel(null)}
          onBooked={(a) => {
            setDate(a.scheduled_at.slice(0, 10))
            setStatus('')
            setNotice(`Appointment booked for ${a.patient.name} at ${clock(a.scheduled_at)}.`)
            // Go straight to the bill so payment can be collected.
            setPanel(a.bills.length > 0 ? { kind: 'bill', billId: a.bills[0].id } : null)
            load()
          }}
        />
      )}
      {panel?.kind === 'bill' && <BillPanel key={panel.billId} billId={panel.billId} services={services} onClose={() => setPanel(null)} onChange={load} />}
    </div>
  )
}
