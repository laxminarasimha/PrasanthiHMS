import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../api'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { clock, today } from '../../format'
import type { PrescriptionOrder } from '../../types'

/** The day's prescriptions from the doctors, so the pharmacy can see who is coming and who has been served. */
export function PharmacyOrders() {
  const [date, setDate] = useState(today())
  const [orders, setOrders] = useState<PrescriptionOrder[] | null>(null)

  useEffect(() => {
    const load = () => api.get<PrescriptionOrder[]>('/pharmacy/orders', { params: { date } }).then((res) => setOrders(res.data))
    load()
    const timer = setInterval(load, 30000)
    return () => clearInterval(timer)
  }, [date])

  const pending = orders?.filter((o) => !o.bill).length ?? 0

  return (
    <>
      <PharmacyTabs />
      <section className="card">
        <div className="toolbar">
          <h1>Prescriptions</h1>
          <input type="date" className="date" value={date} max={today()} onChange={(e) => setDate(e.target.value || today())} aria-label="Day" />
          <span className="muted grow">
            {pending} waiting · {(orders?.length ?? 0) - pending} billed
          </span>
        </div>
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Time</th>
                <th>Patient</th>
                <th>Doctor</th>
                <th>Medicines prescribed</th>
                <th>Status</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {orders?.map((o) => (
                <tr key={o.id} className="static">
                  <td className="mono">{clock(o.completed_at)}</td>
                  <td>
                    <strong>{o.patient.name}</strong>
                    <small className="muted block">
                      {o.patient.uhid} · {o.patient.age_label} / {o.patient.gender.charAt(0).toUpperCase()}
                    </small>
                  </td>
                  <td>{o.doctor}</td>
                  <td className="wrap">
                    {o.medicines.map((m) => (
                      <div key={m.id}>
                        {m.medicine}
                        <small className="muted"> {[m.dose, m.frequency, m.duration].filter(Boolean).join(' · ')}</small>
                      </div>
                    ))}
                  </td>
                  <td>{o.bill ? <span className="pill ok">Billed {o.bill.bill_no}</span> : <span className="pill status-arrived">Waiting</span>}</td>
                  <td className="num">
                    {o.bill ? (
                      <a className="btn ghost" href={`/pharmacy/bills/${o.bill.id}/print`} target="_blank" rel="noreferrer">
                        Print bill
                      </a>
                    ) : (
                      <Link className="btn primary" to={`/pharmacy?patient=${o.patient.id}`}>
                        Bill
                      </Link>
                    )}
                  </td>
                </tr>
              ))}
              {orders && orders.length === 0 && (
                <tr className="empty">
                  <td colSpan={6}>No prescriptions with medicines for this day.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
    </>
  )
}
