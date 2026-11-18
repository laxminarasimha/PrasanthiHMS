import { useEffect, useState, type ReactNode } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { AppAccountBox } from '../components/AppAccountBox'
import { DepositBox } from '../components/DepositBox'
import { PastRecordsPanel } from '../components/PastRecords'
import { PatientDocuments } from '../components/PatientDocuments'
import { AttachmentsPanel } from '../components/FrontDeskPanels'
import { ADMISSION_STATUS, STATUS_LABELS, clock, fullName, longDate, nowLocal, rupees, settlement, stampDate } from '../format'
import type { PatientOverview } from '../types'

const KIND = { appointment: 'Appointment', lab: 'Lab', other: 'Other services' } as const
/** How many rows of each list show before "Show all". */
const FIRST = 5

const initials = (name: string) =>
  name
    .split(/\s+/)
    .filter((word) => /^[a-z]/i.test(word))
    .slice(0, 2)
    .map((word) => word[0].toUpperCase())
    .join('')

/** One list of the patient's past, with its count, the latest few rows, and the rest behind "Show all". */
function History<T>({ title, rows, empty, children }: { title: string; rows: T[]; empty: string; children: (row: T) => ReactNode }) {
  const [all, setAll] = useState(false)
  return (
    <section className="card">
      <h2>
        {title} {rows.length > 0 && <small className="count">{rows.length}</small>}
      </h2>
      <table className="compact gap-top">
        <tbody>
          {(all ? rows : rows.slice(0, FIRST)).map(children)}
          {rows.length === 0 && (
            <tr className="empty">
              <td>{empty}</td>
            </tr>
          )}
        </tbody>
      </table>
      {rows.length > FIRST && (
        <button type="button" className="link show-all" onClick={() => setAll(!all)}>
          {all ? `Show only the latest ${FIRST}` : `Show all ${rows.length}`}
        </button>
      )}
    </section>
  )
}

/** Everything the hospital holds on one patient, on one page. */
export function PatientDashboard() {
  const { id } = useParams()
  const { user } = useAuth()
  const [data, setData] = useState<PatientOverview | null>(null)

  useEffect(() => {
    api.get<PatientOverview>(`/patients/${id}/overview`).then((res) => setData(res.data))
  }, [id])

  // Coming from the old-papers list, the page opens at the scanning section.
  const ready = data !== null
  useEffect(() => {
    if (ready && window.location.hash === '#old-papers') document.getElementById('old-papers')?.scrollIntoView()
  }, [ready])

  if (!data) return <p className="loading">Loading patient…</p>
  const p = data.patient

  const now = nowLocal().replace('T', ' ')
  // Seen means the patient came: a visit only booked, or cancelled, does not count.
  const seen = data.visits.filter((v) => v.status === 'arrived' || v.status === 'ongoing' || v.status === 'reviewed')
  const coming = data.visits.filter((v) => v.status === 'booked' && v.scheduled_at.slice(0, 16) > now).at(-1)
  const admitted = data.admissions.find((a) => a.status === 'admitted')
  const pendingLab = data.lab_orders.filter((o) => o.status !== 'completed').length
  const facts: [string, ReactNode][] = [
    ['Mobile', <>
      <a className="link" href={`tel:${p.mobile}`}>{p.mobile}</a>
      {p.mobile_alt && <span className="muted"> · {p.mobile_alt}</span>}
    </>],
    ['Blood group', p.blood_group],
    ['Address', [p.address, p.city, p.pincode].filter(Boolean).join(', ')],
    ['Care of', p.care_of],
    ['Language', p.preferred_language],
    ['Referred by', p.referred_by],
    ['Old file ID', p.existing_id],
    ['Registered', stampDate(p.created_at)],
  ]
  // What goes with the patient to another screen: only what its forms show.
  const brief = { id: p.id, uhid: p.uhid, name: p.name, title: p.title, gender: p.gender, age: p.age, age_label: p.age_label, mobile: p.mobile }

  return (
    <div className="stack">
      <section className="card">
        <div className="toolbar">
          <Link className="btn ghost" to="/patients">
            ← Patients
          </Link>
          <span className="patient-avatar" aria-hidden="true">
            {initials(p.name) || '?'}
          </span>
          <div className="grow">
            <h1>
              {fullName(p)} {p.is_temporary && <span className="pill status-arrived">Temporary</span>}
            </h1>
            <span className="muted">
              <span className="mono">{p.uhid}</span> · {p.age_label} / {p.gender.charAt(0).toUpperCase()}
              {p.occupation ? ` · ${p.occupation}` : ''}
            </span>
          </div>
          <Link className="btn" to={`/patients?edit=${p.id}`}>
            {p.is_temporary ? 'Complete registration' : 'Edit details'}
          </Link>
          {(user?.can_consult || user?.can_run_lab) && (
            <Link className="btn" to={`/patients/${p.id}/history`}>
              Case history and test trends
            </Link>
          )}
        </div>

        {p.allergies && (
          <p className="allergy-banner">
            <strong>Allergic to</strong> {p.allergies}
          </p>
        )}
        {admitted && (
          <p className="notice">
            Admitted now · {admitted.ipd_no}
            {admitted.bed ? ` · ${admitted.bed.ward} bed ${admitted.bed.bed_no}` : ''} · since {longDate(admitted.admitted_at)} ·{' '}
            <Link className="link" to={`/ipd/${admitted.id}`}>
              Open the case
            </Link>
          </p>
        )}
        {p.is_temporary && <p className="notice">Temporary patient: booked with a name and number only. Complete the registration to add the rest.</p>}

        <dl className="case-facts">
          {facts
            .filter(([, value]) => value)
            .map(([label, value]) => (
              <div key={label}>
                <dt>{label}</dt>
                <dd>{value}</dd>
              </div>
            ))}
        </dl>

        {(user?.can_front_desk || user?.can_ipd) && (
          <div className="quick-actions">
            {user?.can_front_desk && (
              <>
                <Link className="btn primary" to="/front-desk" state={{ book: brief }}>
                  + Appointment
                </Link>
                <Link className="btn" to="/front-desk" state={{ bill: { id: p.id, name: p.name, uhid: p.uhid } }}>
                  + Bill services or lab tests
                </Link>
              </>
            )}
            {user?.can_ipd && !admitted && !p.is_temporary && (
              <Link className="btn" to="/ipd" state={{ admit: p }}>
                Admit
              </Link>
            )}
          </div>
        )}

        <div className="tiles small patient-stats">
          <div className="tile">
            <span>Last seen</span>
            <strong>{seen[0] ? longDate(seen[0].scheduled_at) : 'Not yet'}</strong>
            <small className="muted">{seen[0] ? `${seen[0].doctor.name} · ${seen.length} visit${seen.length === 1 ? '' : 's'}` : 'No visits so far'}</small>
          </div>
          <div className="tile">
            <span>Next appointment</span>
            <strong>{coming ? longDate(coming.scheduled_at) : 'None booked'}</strong>
            <small className="muted">{coming ? `${clock(coming.scheduled_at)} · ${coming.doctor.name}` : ' '}</small>
          </div>
          <div className="tile">
            <span>Lab orders</span>
            <strong>{data.lab_orders.length}</strong>
            <small className={pendingLab ? 'waiting' : 'muted'}>{pendingLab ? `${pendingLab} not ready yet` : data.lab_orders.length ? 'All reported' : ' '}</small>
          </div>
          <div className="tile">
            <span>Outstanding</span>
            <strong className={data.outstanding > 0 ? 'due' : ''}>{rupees(data.outstanding)}</strong>
            <small className="muted">{data.deposit_balance > 0 ? `${rupees(data.deposit_balance)} on deposit` : 'No deposit held'}</small>
          </div>
        </div>

        <AppAccountBox patientId={p.id} canManage={Boolean(user?.can_front_desk)} />
        <DepositBox patientId={p.id} balance={data.deposit_balance} history={data.deposits} onChange={(balance, deposits) => setData({ ...data, deposit_balance: balance, deposits })} />
      </section>

      <div className="two-up">
        <History title="Visits" rows={data.visits} empty="No visits yet.">
          {(v) => (
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
          )}
        </History>

        <History title="Bills" rows={data.bills} empty="No bills yet.">
          {(b) => (
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
              <td className={`num ${b.due > 0 ? 'due' : 'settled'}`}>{settlement(b.due, b.moved_to_ipd, b.total)}</td>
              <td className="num">
                <a className="link" href={`/bills/${b.id}/print`} target="_blank" rel="noreferrer">
                  Print
                </a>
              </td>
            </tr>
          )}
        </History>

        <History title="Lab orders" rows={data.lab_orders} empty="No lab orders yet.">
          {(o) => (
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
          )}
        </History>

        <History title="Admissions" rows={data.admissions} empty="Never admitted.">
          {(a) => (
            <tr key={a.id} className="static">
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
              <td>
                <span className={`pill ${a.status === 'admitted' ? 'status-ongoing' : a.status === 'discharged' ? 'ok' : 'off'}`}>{ADMISSION_STATUS[a.status]}</span>
              </td>
              <td className={`num ${a.due > 0 ? 'due' : ''}`}>{a.due > 0 ? `${rupees(a.due)} due` : ''}</td>
            </tr>
          )}
        </History>

        <History title="Pharmacy bills" rows={data.pharmacy_bills} empty="No pharmacy bills yet.">
          {(sale) => (
            <tr key={sale.id} className="static">
              <td className="mono">{sale.bill_no}</td>
              <td>{longDate(sale.sold_at)}</td>
              <td className="num">{rupees(sale.total)}</td>
              <td className={`num ${sale.due > 0 ? 'due' : 'settled'}`}>{sale.due > 0 ? `${rupees(sale.due)} due` : 'Settled'}</td>
            </tr>
          )}
        </History>
      </div>

      <section className="card">
        <h2>Certificates and letters</h2>
        <PatientDocuments patient={p} canIssue={Boolean(user?.can_approve_ipd)} />
      </section>

      {user?.can_file_past_records && (
        <section className="card" id="old-papers">
          <div className="toolbar">
            <h2>Old paper records</h2>
            <span className="muted grow">Prescriptions, reports and summaries the patient brings from before. Scan them in; once checked, they join the case history.</span>
            <Link className="btn ghost small-btn" to="/past-records">
              All papers to check
            </Link>
          </div>
          <PastRecordsPanel patient={p} />
        </section>
      )}

      <section className="card">
        <h2>Attachments</h2>
        <AttachmentsPanel patient={p} />
      </section>
    </div>
  )
}
