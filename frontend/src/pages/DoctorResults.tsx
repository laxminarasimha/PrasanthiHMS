import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { DoctorTabs } from '../components/DoctorTabs'
import { PatientLink } from '../components/PatientLink'
import { clock, longDate } from '../format'
import { useNotice } from '../notices'

type Value = { test: string; parameter: string; value: string; unit: string | null; range: string | null; flag: 'high' | 'low' }

type Ready = {
  id: number
  order_no: string
  ready_at: string
  seen_at: string | null
  /** Whether these are the signed-in doctor's own, to tick off; otherwise they are shown to chase. */
  mine: boolean
  doctor: string | null
  patient: { id: number; uhid: string; title: string | null; name: string; gender: string; age_label: string }
  inpatient: { admission_id: number; ipd_no: string; bed: string | null } | null
  tests: string[]
  awaited: string[]
  abnormal: Value[]
}

type Abnormal = { order_id: number; order_no: string; reported_at: string; patient: Ready['patient']; results: Value[] }

const arrow = (flag: 'high' | 'low') => (flag === 'high' ? '↑' : '↓')

/** The values outside their normal range, each with its range, so the page can be read without opening the report. */
function Values({ rows }: { rows: Value[] }) {
  if (rows.length === 0) return <small className="muted block">All values within their normal range.</small>
  return (
    <ul className="result-values">
      {rows.map((r, i) => (
        <li key={i}>
          <span>
            {r.parameter}
            {r.parameter !== r.test && <small className="muted"> · {r.test}</small>}
          </span>
          <strong className="due">
            {r.value} {r.unit ?? ''} {arrow(r.flag)}
          </strong>
          {r.range && <small className="muted">normal {r.range}</small>}
        </li>
      ))}
    </ul>
  )
}

/**
 * Lab results for the doctor: first the reports that are ready and not looked at yet, then the week's results that
 * were outside their normal range. Opening a report, or pressing Seen, takes it off the waiting list.
 */
export function DoctorResults() {
  const [ready, setReady] = useState<Ready[] | null>(null)
  const [abnormal, setAbnormal] = useState<Abnormal[] | null>(null)

  const load = useCallback(() => {
    api.get<Ready[]>('/doctor/ready-results').then((res) => setReady(res.data))
    api.get<Abnormal[]>('/doctor/abnormal-results').then((res) => setAbnormal(res.data))
  }, [])
  useEffect(load, [load])
  // A report has just been made ready and this device was told: it shows at once.
  useNotice(['lab'], load)

  const seen = (o: Ready) => api.put(`/lab/orders/${o.id}/seen`).then(load)
  const waiting = ready?.filter((o) => !o.seen_at) ?? []
  const looked = ready?.filter((o) => o.seen_at) ?? []
  const others = ready !== null && ready.some((o) => !o.mine)

  const card = (o: Ready) => (
    <li key={o.id} className={o.seen_at ? 'seen' : ''}>
      <div className="grow">
        <strong>
          <PatientLink id={o.patient.id}>{o.patient.name}</PatientLink>
        </strong>{' '}
        <small className="muted">
          {o.patient.uhid} · {o.patient.age_label} / {o.patient.gender.charAt(0).toUpperCase()}
        </small>
        {o.inpatient && (
          <Link className="pill status-ongoing" to={`/ipd/${o.inpatient.admission_id}`} title="Open the IPD case">
            {o.inpatient.bed ?? `IPD ${o.inpatient.ipd_no}`}
          </Link>
        )}
        <small className="block">
          {o.tests.join(', ')}
          <span className="muted">
            {' '}
            · {o.order_no} · ready {longDate(o.ready_at).replace(/ \d{4}$/, '')} {clock(o.ready_at)}
            {!o.mine && o.doctor ? ` · for ${o.doctor}` : ''}
          </span>
        </small>
        {o.awaited.length > 0 && <small className="muted block">Still awaited: {o.awaited.join(', ')}</small>}
        <Values rows={o.abnormal} />
      </div>
      <div className="row">
        <a className="btn small-btn" href={`/lab/orders/${o.id}/print`} target="_blank" rel="noreferrer" onClick={() => o.mine && !o.seen_at && seen(o)}>
          Open report
        </a>
        {o.mine && !o.seen_at && (
          <button className="btn primary small-btn" onClick={() => seen(o)}>
            Seen
          </button>
        )}
      </div>
    </li>
  )

  return (
    <>
      <DoctorTabs />
      <section className="card">
        <div className="toolbar">
          <h1>Lab results</h1>
          <span className="muted">
            <strong className="loads">{waiting.length}</strong> ready to see
          </span>
        </div>
        <p className="muted">
          {others
            ? 'Reports that are ready and that the doctor has not looked at yet, for every doctor.'
            : 'Reports for the tests you asked for. They stay here until you have looked; a result that comes later from an outside lab brings the report back.'}
        </p>
        {ready === null ? (
          <p className="loading">Loading results…</p>
        ) : waiting.length === 0 ? (
          <p className="muted when-loaded">Nothing is waiting to be seen.</p>
        ) : (
          <ul className="result-list">{waiting.map(card)}</ul>
        )}

        {looked.length > 0 && (
          <>
            <h3>Seen in the last 3 days</h3>
            <ul className="result-list">{looked.map(card)}</ul>
          </>
        )}
      </section>

      <section className="card">
        <div className="toolbar">
          <h2>Outside the normal range this week</h2>
        </div>
        <p className="muted">{others ? 'For every patient.' : 'For the patients you have seen lately or have in a bed, whoever asked for the test.'}</p>
        {abnormal === null ? (
          <p className="loading">Loading…</p>
        ) : abnormal.length === 0 ? (
          <p className="muted when-loaded">No results outside their normal range in the last 7 days.</p>
        ) : (
          <ul className="result-list">
            {abnormal.map((o) => (
              <li key={o.order_id}>
                <div className="grow">
                  <strong>
                    <PatientLink id={o.patient.id}>{o.patient.name}</PatientLink>
                  </strong>{' '}
                  <small className="muted">
                    {o.patient.uhid} · {o.patient.age_label} / {o.patient.gender.charAt(0).toUpperCase()} · {o.order_no} · {longDate(o.reported_at).replace(/ \d{4}$/, '')} {clock(o.reported_at)}
                  </small>
                  <Values rows={o.results} />
                </div>
                <a className="btn small-btn" href={`/lab/orders/${o.order_id}/print`} target="_blank" rel="noreferrer">
                  Open report
                </a>
              </li>
            ))}
          </ul>
        )}
      </section>
    </>
  )
}
