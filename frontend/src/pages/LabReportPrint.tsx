import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { clock, longDate } from '../format'
import type { LabOrder } from '../types'

/** The lab report as given to the patient: only tests marked as reported are printed. */
export function LabReportPrint() {
  const { id } = useParams()
  const { user } = useAuth()
  const [order, setOrder] = useState<LabOrder | null>(null)

  useEffect(() => {
    api.get<LabOrder>(`/lab/orders/${id}`).then((res) => {
      setOrder(res.data)
      setTimeout(() => window.print(), 300)
    })
  }, [id])

  if (!order) return <p className="loading">Loading report…</p>
  const reported = order.items.filter((item) => item.reported_at)
  if (reported.length === 0) return <p className="loading">No test in this order has been marked as reported yet.</p>

  const signer = reported[reported.length - 1].reporter

  return (
    <div className="receipt prescription">
      <header>
        <h1>{user?.branch?.name ?? 'Prasanthi Hospitals'}</h1>
        {user?.branch?.city && <p>{user.branch.city}</p>}
        <h2>Laboratory report</h2>
      </header>

      <div className="receipt-meta">
        <div>
          <strong>{order.patient.name}</strong>
          <br />
          {order.patient.age}y / {order.patient.gender.charAt(0).toUpperCase()} · Patient ID: {order.patient.uhid}
          {order.referred_by && (
            <>
              <br />
              Referred by: {order.referred_by}
            </>
          )}
        </div>
        <div className="right">
          Order no: <strong>{order.order_no}</strong>
          <br />
          Collected: {longDate(order.ordered_at)} {clock(order.ordered_at)}
        </div>
      </div>

      {reported.map((item) => (
        <section key={item.id}>
          <h3>
            {item.test_name}
            {item.sample_type ? ` (${item.sample_type})` : ''}
          </h3>
          <table>
            <thead>
              <tr>
                <th>Parameter</th>
                <th>Result</th>
                <th>Unit</th>
                <th>Reference range</th>
              </tr>
            </thead>
            <tbody>
              {(item.results ?? []).map((r) => (
                <tr key={r.id}>
                  <td>
                    {r.parameter}
                    {r.method && <small className="block">{r.method}</small>}
                  </td>
                  <td>
                    {r.flag ? <strong>{r.value} {r.flag === 'high' ? '(H)' : '(L)'}</strong> : r.value}
                  </td>
                  <td>{r.unit}</td>
                  <td>{r.reference_range}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {item.remarks && <p>Remarks: {item.remarks}</p>}
        </section>
      ))}

      {reported.length < order.items.length && <p className="rx-vitals gap-top">{order.items.length - reported.length} test(s) in this order are still pending.</p>}

      <div className="signature">
        <strong>{signer?.signature_text || signer?.name}</strong>
        <br />
        <small>Laboratory</small>
      </div>
    </div>
  )
}
