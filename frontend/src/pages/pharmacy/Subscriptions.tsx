import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors } from '../../api'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { fullName, longDate, today } from '../../format'

type Subscription = {
  id: number
  title: string
  items: { medicine_id: number | null; name: string; quantity: number }[]
  day_of_month: number
  status: 'active' | 'paused' | 'cancelled'
  next_due_on: string
  last_prepared_on: string | null
  notes: string | null
  patient: { id: number; uhid: string; title?: string | null; name: string; mobile: string; age_label: string; gender: string }
  preparer?: { id: number; name: string } | null
}
type Listing = { subscriptions: Subscription[]; counts: { due: number; active: number; paused: number } }

const SHOW = { due: 'Due this week', active: 'All active', paused: 'Paused' } as const

/** Monthly medicine lists patients keep in the mobile app: what to make up, for whom, and when. */
export function PharmacySubscriptions() {
  const [show, setShow] = useState<keyof typeof SHOW>('due')
  const [data, setData] = useState<Listing | null>(null)
  const [message, setMessage] = useState({ text: '', bad: false })

  const load = useCallback(() => {
    api.get<Listing>('/pharmacy/subscriptions', { params: { show } }).then((res) => setData(res.data))
  }, [show])
  useEffect(load, [load])

  const prepared = async (s: Subscription) => {
    if (!window.confirm(`Mark the pack for ${s.patient.name} as made up? It will come due again next month.`)) return
    try {
      const res = await api.post<Subscription>(`/pharmacy/subscriptions/${s.id}/prepared`)
      setMessage({ text: `Done. ${s.patient.name}'s next pack is due on ${longDate(res.data.next_due_on)}.`, bad: false })
      load()
    } catch (err) {
      setMessage({ text: Object.values(fieldErrors(err))[0] ?? 'Could not save.', bad: true })
    }
  }

  return (
    <>
      <PharmacyTabs />
      <section className="card">
        <div className="toolbar">
          <h1>Monthly medicines</h1>
          <span className="muted grow">Lists patients have set up in the mobile app. Make up the pack, bill it as usual under Billing, then mark it done here.</span>
        </div>
        <div className="tabs">
          {(Object.keys(SHOW) as (keyof typeof SHOW)[]).map((key) => (
            <button key={key} className={show === key ? 'active' : ''} onClick={() => setShow(key)}>
              {SHOW[key]} <span>{data?.counts[key] ?? 0}</span>
            </button>
          ))}
        </div>
        {message.text && <p className={message.bad ? 'form-error' : 'notice'}>{message.text}</p>}
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Due</th>
                <th>Patient</th>
                <th>List</th>
                <th>Medicines for the month</th>
                <th>Last made up</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {data?.subscriptions.map((s) => (
                <tr key={s.id} className="static">
                  <td>
                    <strong className={s.status === 'active' && s.next_due_on <= today() ? 'due' : ''}>{longDate(s.next_due_on)}</strong>
                    <small className="muted block">Every month on day {s.day_of_month}</small>
                  </td>
                  <td>
                    <Link className="link" to={`/patients/${s.patient.id}`}>
                      {fullName(s.patient)}
                    </Link>
                    <small className="muted block">
                      {s.patient.uhid} · {s.patient.age_label} / {s.patient.gender.charAt(0).toUpperCase()} · {s.patient.mobile}
                    </small>
                  </td>
                  <td>
                    {s.title}
                    {s.status === 'paused' && <span className="pill off"> Paused</span>}
                    {s.notes && <small className="muted block">{s.notes}</small>}
                  </td>
                  <td>
                    {s.items.map((item, i) => (
                      <div key={i}>
                        {item.name} × <strong>{item.quantity}</strong>
                        {!item.medicine_id && <small className="muted"> (typed by the patient, not from our list)</small>}
                      </div>
                    ))}
                  </td>
                  <td>
                    {s.last_prepared_on ? longDate(s.last_prepared_on) : 'Never'}
                    {s.preparer && <small className="muted block">{s.preparer.name}</small>}
                  </td>
                  <td className="num">
                    {s.status === 'active' && show === 'due' && (
                      <button className="btn primary" onClick={() => prepared(s)}>
                        Pack made
                      </button>
                    )}
                  </td>
                </tr>
              ))}
              {data && data.subscriptions.length === 0 && (
                <tr className="empty">
                  <td colSpan={6}>{show === 'due' ? 'Nothing is due in the next seven days.' : 'No lists here yet. They appear when patients set them up in the mobile app.'}</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
    </>
  )
}
