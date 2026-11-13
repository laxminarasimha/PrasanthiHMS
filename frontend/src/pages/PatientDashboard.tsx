import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../api'
import { DepositBox } from '../components/DepositBox'
import { AttachmentsPanel } from '../components/FrontDeskPanels'
import { ADMISSION_STATUS, STATUS_LABELS, clock, longDate, rupees, settlement, stampDate } from '../format'
import type { PatientOverview } from '../types'

const KIND = { appointment: 'Appointment', lab: 'Lab', other: 'Other services' } as const

/** Everything the hospital holds on one patient, on one page. */
export function PatientDashboard() {
  const { id } = useParams()
  const [data, setData] = useState<PatientOverview | null>(null)

  useEffect(() => {
    api.get<PatientOverview>(`/patients/${id}/overview`).then((res) => setData(res.data))
  }, [id])

  if (!data) return <p className="loading">Loading patient…</p>
  const p = data.patient

  return (
    <div className="stack">
      <section className="card">
        <div className="toolbar">
          <Link className="btn ghost" to="/patients">
            ← Patients
          </Link>
          <div className="grow">
            <h1>{p.name}</h1>
            <span className="muted">
              {p.uhid} · {p.age_label} / {p.gender.charAt(0).toUpperCase()} · {p.mobile}
              {p.blood_group ? ` · ${p.blood_group}` : ''}
              {p.city ? ` · ${p.city}` : ''}
            </span>
          </div>
          {p.allergies && <span className="pill off">Allergic to {p.allergies}</span>}
          <div className="tile inline">
            <span>Outstanding</span>
            <strong className={data.outstanding > 0 ? 'due' : ''}>{rupees(data.outstanding)}</strong>
          </div>
        </div>
        {p.is_temporary && <p className="notice">Temporary patient: booked with a name and number only. Complete the registration under Patients.</p>}
        <DepositBox patientId={p.id} balance={data.deposit_balance} history={data.deposits} onChange={(balance, deposits) => setData({ ...data, deposit_balance: balance, deposits })} />
      </section>

      <div className="two-up">
        <section className="card">
          <h2>Visits</h2>
          <table className="compact gap-top">
            <tbody>
              {data.visits.map((v) => (
                <tr key={v.id} className="static">
                  <td>
                    {longDate(v.scheduled_at)} <small className="muted">{clock(v.scheduled_at)}</small>
                  </td>
                  <td>
                    {v.doctor.name}
                    <small className="muted block">{v.consultation?.diagnosis?.split('\n')[0] || v.service?.name || ''}</small>
                  </td>
                  <td>
                    <span className={`pill status-${v.status}`}>{STATUS_LABELS[v.status]}</span>
                  </td>
                  <td className="num">
                    {v.consultation && (
                      <a className="link" href={`/visits/${v.id}/print`} target="_blank" rel="noreferrer">
                        Prescription
                      </a>
                    )}
                  </td>
                </tr>
              ))}
              {data.visits.length === 0 && (
                <tr className="empty">
                  <td>No visits yet.</td>
                </tr>
              )}
            </tbody>
          </table>
        </section>

        <section className="card">
          <h2>Bills</h2>
          <table className="compact gap-top">
            <tbody>
              {data.bills.map((b) => (
                <tr key={b.id} className="static">
                  <td className="mono">{b.bill_no}</td>
                  <td>
                    {stampDate(b.created_at)}
                    <small className="muted block">
                      {KIND[b.kind]}
                      {b.lab_order ? ` · ${b.lab_order.order_no}` : ''}
                    </small>
                  </td>
                  <td className="num">{rupees(b.total)}</td>
                  <td className={`num ${b.due > 0 ? 'due' : 'settled'}`}>{settlement(b.due, b.moved_to_ipd)}</td>
                  <td className="num">
                    <a className="link" href={`/bills/${b.id}/print`} target="_blank" rel="noreferrer">
                      Print
                    </a>
                  </td>
                </tr>
              ))}
              {data.bills.length === 0 && (
                <tr className="empty">
                  <td>No bills yet.</td>
                </tr>
              )}
            </tbody>
          </table>
        </section>

        <section className="card">
          <h2>Lab orders</h2>
          <table className="compact gap-top">
            <tbody>
              {data.lab_orders.map((o) => (
                <tr key={o.id} className="static">
                  <td className="mono">{o.order_no}</td>
                  <td>
                    {longDate(o.ordered_at)}
                    <small className="muted block">{o.items.map((i) => i.test_name).join(', ')}</small>
                  </td>
                  <td>
                    <span className={`pill ${o.status === 'completed' ? 'ok' : 'status-arrived'}`}>{o.status === 'completed' ? 'Ready' : o.status === 'partial' ? 'Partly ready' : 'Pending'}</span>
                  </td>
                  <td className="num">
                    {o.status !== 'pending' && (
                      <a className="link" href={`/lab/orders/${o.id}/print`} target="_blank" rel="noreferrer">
                        Report
                      </a>
                    )}
                  </td>
                </tr>
              ))}
              {data.lab_orders.length === 0 && (
                <tr className="empty">
                  <td>No lab orders yet.</td>
                </tr>
              )}
            </tbody>
          </table>
        </section>

        <section className="card">
          <h2>Pharmacy bills and admissions</h2>
          <table className="compact gap-top">
            <tbody>
              {data.pharmacy_bills.map((s) => (
                <tr key={`p${s.id}`} className="static">
                  <td className="mono">{s.bill_no}</td>
                  <td>{longDate(s.sold_at)}</td>
                  <td className="num">{rupees(s.total)}</td>
                  <td className={`num ${s.due > 0 ? 'due' : 'settled'}`}>{s.due > 0 ? `${rupees(s.due)} due` : 'Settled'}</td>
                </tr>
              ))}
              {data.admissions.map((a) => (
                <tr key={`a${a.id}`} className="static">
                  <td className="mono">
                    <Link className="link" to={`/ipd/${a.id}`}>
                      {a.ipd_no}
                    </Link>
                  </td>
                  <td>
                    {longDate(a.admitted_at)}
                    {a.discharged_at ? ` to ${longDate(a.discharged_at)}` : ''}
                    <small className="muted block">
                      {a.doctor.name}
                      {a.bed ? ` · ${a.bed.ward} bed ${a.bed.bed_no}` : ''}
                    </small>
                  </td>
                  <td>{ADMISSION_STATUS[a.status]}</td>
                  <td className={`num ${a.due > 0 ? 'due' : ''}`}>{a.due > 0 ? `${rupees(a.due)} due` : ''}</td>
                </tr>
              ))}
              {data.pharmacy_bills.length + data.admissions.length === 0 && (
                <tr className="empty">
                  <td>Nothing yet.</td>
                </tr>
              )}
            </tbody>
          </table>
        </section>
      </div>

      <section className="card">
        <h2>Attachments</h2>
        <AttachmentsPanel patient={p} />
      </section>
    </div>
  )
}
