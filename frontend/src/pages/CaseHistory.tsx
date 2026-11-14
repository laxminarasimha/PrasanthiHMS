import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'
import { HealthSummary } from '../components/HealthSummary'
import { ADMISSION_STATUS, clock, fullName, longDate } from '../format'
import type { Consultation, Patient } from '../types'

type LabEvent = { id: number; order_no: string; status: string; tests: { name: string; reported: boolean; results: { parameter: string; value: string; unit: string | null; flag: string | null }[] }[] }
type AdmissionEvent = {
  id: number
  ipd_no: string
  status: string
  reason: string | null
  doctor: { id: number; name: string } | null
  admitted_at: string
  discharged_at: string | null
  summary: { diagnosis: string | null; procedures: string | null; course: string | null; condition_at_discharge: string | null; advice: string | null; approved_at: string | null } | null
}
type HistoryEvent = { key: string; at: string; kind: 'visit' | 'admission' | 'lab'; appointment_id?: number; visit?: Consultation; admission?: AdmissionEvent; lab?: LabEvent }
type Point = { date: string; value: string; number: number | null; flag: string | null; order_id: number; order_no: string }
type TestTrend = { test: string; parameter: string; unit: string | null; reference_range: string | null; points: Point[] }
type Vitals = { date: string; weight_kg: number | null; height_cm: number | null; bp_systolic: number | null; bp_diastolic: number | null; pulse: number | null; temperature_f: number | null; spo2: number | null; resp_rate: number | null }
type Trends = { tests: TestTrend[]; vitals: Vitals[] }

const KINDS = { all: 'Everything', visit: 'Visits', admission: 'Admissions', lab: 'Lab' } as const
const shortDate = (date: string) => new Date(date.slice(0, 10) + 'T00:00:00').toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: '2-digit' })

/** A small line showing how the numbers moved; the last point is marked. */
function Spark({ values }: { values: number[] }) {
  if (values.length < 2) return null
  const low = Math.min(...values)
  const span = Math.max(...values) - low || 1
  const xy = values.map((v, i) => [4 + (i * 92) / (values.length - 1), 22 - ((v - low) / span) * 18] as const)
  const [lastX, lastY] = xy[xy.length - 1]
  return (
    <svg className="spark" viewBox="0 0 100 26" role="img" aria-label={`Trend: ${values.join(', ')}`}>
      <polyline points={xy.map((p) => p.join(',')).join(' ')} />
      <circle cx={lastX} cy={lastY} r="2.6" />
    </svg>
  )
}

function VisitNotes({ v }: { v: Consultation }) {
  const vitals = [
    v.bp_systolic ? `BP ${v.bp_systolic}/${v.bp_diastolic}` : '',
    v.pulse ? `Pulse ${v.pulse}` : '',
    v.temperature_f ? `Temp ${v.temperature_f}°F` : '',
    v.spo2 ? `SpO₂ ${v.spo2}%` : '',
    v.weight_kg ? `${v.weight_kg} kg` : '',
    v.height_cm ? `${v.height_cm} cm` : '',
  ].filter(Boolean)
  const lines: [string, string | null][] = [
    ['Complaints', v.complaints],
    ['History', v.history],
    ['Past medication', v.past_medication],
    ['Examination', [v.general_exam_notes, v.findings].filter(Boolean).join('\n') || null],
    ['Diagnosis', v.diagnosis],
    ['ICD-10', v.diagnosis_codes?.length ? v.diagnosis_codes.map((d) => `${d.code} ${d.name}`).join('; ') : null],
    ['Plan', v.assessment_plan],
    ['Tests advised', v.tests_advised],
    ['Advice', v.advice],
    ['Referred to', v.refer_to],
    ['Notes', v.quick_notes],
  ]
  return (
    <>
      {vitals.length > 0 && <p className="muted">{vitals.join(' · ')}</p>}
      {lines.map(([label, text]) => text && (
        <p key={label}>
          <b>{label}:</b> {text}
        </p>
      ))}
      {v.investigations?.length ? (
        <p>
          <b>Results brought:</b> {v.investigations.map((r) => `${r.name}${r.result ? ` ${r.result}` : ''}`).join('; ')}
        </p>
      ) : null}
      {v.medicines.length > 0 && (
        <ul>
          {v.medicines.map((m) => (
            <li key={m.id}>
              {m.medicine} {[m.dose, m.frequency, m.timing, m.duration].filter(Boolean).join(' · ')}
            </li>
          ))}
        </ul>
      )}
      {v.follow_up_on && <p className="muted">Next visit: {longDate(v.follow_up_on)}</p>}
      {v.amendments?.map((a) => (
        <p key={a.id}>
          <b>Amendment, {longDate(a.created_at)}{a.author ? `, ${a.author.name}` : ''}:</b> {a.body}
        </p>
      ))}
    </>
  )
}

/** Who opened or changed this patient's records, newest first. */
function RecordLog({ patientId }: { patientId: number }) {
  const [rows, setRows] = useState<{ id: number; when: string; who: string; action: string; details: string | null }[] | null>(null)
  useEffect(() => {
    api.get(`/patients/${patientId}/audit-log`).then((res) => setRows(res.data))
  }, [patientId])

  return (
    <section className="card">
      <div className="toolbar">
        <h2>Record log</h2>
        <span className="muted">Who opened or changed this patient's records. Entries cannot be edited or removed.</span>
      </div>
      <div className="table-wrap">
        <table className="compact">
          <thead>
            <tr>
              <th>When</th>
              <th>Who</th>
              <th>What they did</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
            {rows?.map((r) => (
              <tr key={r.id} className="static">
                <td>
                  {longDate(r.when)} <small className="muted">{clock(r.when)}</small>
                </td>
                <td>{r.who}</td>
                <td>{r.action}</td>
                <td>{r.details ?? ''}</td>
              </tr>
            ))}
            {rows?.length === 0 && (
              <tr className="empty">
                <td colSpan={4}>Nothing has been logged for this patient yet. The log starts from the day this feature was switched on.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </section>
  )
}

/** One patient's whole story for the doctor: every visit, admission and lab result, and how test values moved. */
export function CaseHistory() {
  const { id } = useParams()
  const { user } = useAuth()
  const navigate = useNavigate()
  const [tab, setTab] = useState<'summary' | 'timeline' | 'tests' | 'vitals' | 'log'>('summary')
  const [kind, setKind] = useState<keyof typeof KINDS>('all')
  const [data, setData] = useState<{ patient: Patient & { allergies?: string | null }; events: HistoryEvent[] } | null>(null)
  const [trends, setTrends] = useState<Trends | null>(null)
  const [filter, setFilter] = useState('')
  const [error, setError] = useState('')
  const [starting, setStarting] = useState(false)

  useEffect(() => {
    api.get(`/patients/${id}/case-history`).then((res) => setData(res.data)).catch((e) => setError(e.response?.data?.message ?? 'Could not load the history.'))
    api.get<Trends>(`/patients/${id}/trends`).then((res) => setTrends(res.data)).catch(() => {})
  }, [id])

  const startVisit = async () => {
    setStarting(true)
    try {
      const res = await api.post<{ id: number }>('/doctor/visits', { patient_id: Number(id) })
      navigate(`/visits/${res.data.id}`)
    } catch (e) {
      const response = (e as { response?: { data?: { message?: string } } }).response
      setError(response?.data?.message ?? 'Could not start the visit.')
      setStarting(false)
    }
  }

  if (error && !data) return <p className="form-error">{error}</p>
  if (!data) return <p className="loading">Loading history…</p>
  const p = data.patient
  const counts = { all: data.events.length, visit: 0, admission: 0, lab: 0 }
  data.events.forEach((e) => counts[e.kind]++)
  const events = data.events.filter((e) => kind === 'all' || e.kind === kind)
  const term = filter.trim().toLowerCase()
  const tests = trends?.tests.filter((t) => !term || t.test.toLowerCase().includes(term) || t.parameter.toLowerCase().includes(term)) ?? []

  return (
    <div className="stack">
      <section className="card">
        <div className="toolbar">
          <Link className="btn ghost" to={`/patients/${p.id}`}>
            ← Patient
          </Link>
          <div className="grow">
            <h1>{fullName(p)}</h1>
            <span className="muted">
              {p.uhid} · {p.age_label} / {p.gender.charAt(0).toUpperCase()} · {p.mobile}
              {p.blood_group ? ` · ${p.blood_group}` : ''}
            </span>
          </div>
          {p.allergies && <span className="pill off">Allergic to {p.allergies}</span>}
          {user?.role === 'doctor' && (
            <button className="btn primary" onClick={startVisit} disabled={starting}>
              Start a visit
            </button>
          )}
        </div>
        {error && <p className="form-error">{error}</p>}
        <div className="tabs flush gap-top">
          <button className={tab === 'summary' ? 'active' : ''} onClick={() => setTab('summary')}>
            Health summary
          </button>
          <button className={tab === 'timeline' ? 'active' : ''} onClick={() => setTab('timeline')}>
            Case history <span>{data.events.length}</span>
          </button>
          <button className={tab === 'tests' ? 'active' : ''} onClick={() => setTab('tests')}>
            Test results over time <span>{trends?.tests.length ?? 0}</span>
          </button>
          <button className={tab === 'vitals' ? 'active' : ''} onClick={() => setTab('vitals')}>
            Vitals <span>{trends?.vitals.length ?? 0}</span>
          </button>
          {user?.can_view_reports && (
            <button className={tab === 'log' ? 'active' : ''} onClick={() => setTab('log')}>
              Record log
            </button>
          )}
        </div>
      </section>

      {tab === 'summary' && (
        <section className="card">
          <HealthSummary patientId={p.id} canEdit={Boolean(user?.can_consult)} />
        </section>
      )}

      {tab === 'timeline' && (
        <section className="card">
          <div className="tabs">
            {(Object.keys(KINDS) as (keyof typeof KINDS)[]).map((k) => (
              <button key={k} className={`square ${kind === k ? 'active' : ''}`} onClick={() => setKind(k)}>
                {KINDS[k]} <span>{counts[k]}</span>
              </button>
            ))}
          </div>
          {events.length === 0 && <p className="muted">Nothing recorded yet.</p>}
          <ol className="timeline">
            {events.map((e) => (
              <li key={e.key} className={`kind-${e.kind}`}>
                <div className="when">
                  <strong>{longDate(e.at)}</strong>
                  <small className="muted">{clock(e.at)}</small>
                </div>
                <div className="what past">
                  {e.visit && (
                    <>
                      <div className="head">
                        <span className="pill status-ongoing">Visit</span>
                        <strong>{e.visit.diagnosis?.split('\n')[0] || 'No diagnosis noted'}</strong>
                        <span className="muted">{e.visit.doctor?.name}</span>
                        {!e.visit.completed_at && <span className="pill status-arrived">Not finished</span>}
                        <a className="link push" href={`/visits/${e.appointment_id}/print`} target="_blank" rel="noreferrer">
                          Prescription
                        </a>
                      </div>
                      <VisitNotes v={e.visit} />
                    </>
                  )}
                  {e.admission && (
                    <>
                      <div className="head">
                        <span className="pill off">Admission</span>
                        <strong>{e.admission.summary?.diagnosis?.split('\n')[0] || e.admission.reason || 'Admitted'}</strong>
                        <span className="muted">
                          {e.admission.doctor?.name} · {ADMISSION_STATUS[e.admission.status] ?? e.admission.status}
                          {e.admission.discharged_at ? ` on ${longDate(e.admission.discharged_at)}` : ''}
                        </span>
                        <Link className="link push" to={`/ipd/${e.admission.id}`}>
                          {e.admission.ipd_no}
                        </Link>
                      </div>
                      {e.admission.reason && <p><b>Reason:</b> {e.admission.reason}</p>}
                      {e.admission.summary?.procedures && <p><b>Procedures:</b> {e.admission.summary.procedures}</p>}
                      {e.admission.summary?.course && <p><b>Course in hospital:</b> {e.admission.summary.course}</p>}
                      {e.admission.summary?.condition_at_discharge && <p><b>Condition at discharge:</b> {e.admission.summary.condition_at_discharge}</p>}
                      {e.admission.summary?.advice && <p><b>Advice:</b> {e.admission.summary.advice}</p>}
                    </>
                  )}
                  {e.lab && (
                    <>
                      <div className="head">
                        <span className="pill ok">Lab</span>
                        <strong>{e.lab.tests.map((t) => t.name).join(', ')}</strong>
                        {e.lab.status !== 'pending' && (
                          <a className="link push" href={`/lab/orders/${e.lab.id}/print`} target="_blank" rel="noreferrer">
                            {e.lab.order_no}
                          </a>
                        )}
                      </div>
                      {e.lab.tests.map((t, i) => (
                        <p key={i}>
                          <b>{t.name}:</b>{' '}
                          {!t.reported ? (
                            <span className="muted">awaiting result</span>
                          ) : t.results.length === 0 ? (
                            <span className="muted">reported, see the report</span>
                          ) : (
                            t.results.map((r, j) => (
                              <span key={j} className={r.flag ? `value ${r.flag}` : 'value'}>
                                {r.parameter} {r.value}
                                {r.unit ? ` ${r.unit}` : ''}
                                {j < t.results.length - 1 ? '; ' : ''}
                              </span>
                            ))
                          )}
                        </p>
                      ))}
                    </>
                  )}
                </div>
              </li>
            ))}
          </ol>
        </section>
      )}

      {tab === 'tests' && (
        <section className="card">
          <div className="toolbar">
            <h2>Test results over time</h2>
            <input className="search" type="search" placeholder="Filter by test" value={filter} onChange={(e) => setFilter(e.target.value)} />
            <span className="muted">Oldest on the left, newest on the right. Only reported results.</span>
          </div>
          <div className="table-wrap">
            <table className="compact results-trend">
              <thead>
                <tr>
                  <th>Test</th>
                  <th>Normal range</th>
                  <th>Trend</th>
                  <th>Results</th>
                </tr>
              </thead>
              <tbody>
                {tests.map((t) => {
                  const shown = t.points.slice(-8)
                  return (
                    <tr key={`${t.test}|${t.parameter}`} className="static">
                      <td>
                        {t.parameter}
                        {t.parameter.toLowerCase() !== t.test.toLowerCase() && <small className="muted block">{t.test}</small>}
                      </td>
                      <td className="muted">
                        {t.reference_range ?? '—'}
                        {t.unit ? ` ${t.unit}` : ''}
                      </td>
                      <td>
                        <Spark values={t.points.map((pt) => pt.number).filter((n): n is number => n !== null)} />
                      </td>
                      <td>
                        <div className="points">
                          {t.points.length > shown.length && <small className="muted">{t.points.length - shown.length} older…</small>}
                          {shown.map((pt, i) => (
                            <a key={i} className={`point ${pt.flag ?? ''}`} href={`/lab/orders/${pt.order_id}/print`} target="_blank" rel="noreferrer" title={`${pt.order_no}: open the report`}>
                              <strong>{pt.value}</strong>
                              <small>{shortDate(pt.date)}</small>
                            </a>
                          ))}
                        </div>
                      </td>
                    </tr>
                  )
                })}
                {tests.length === 0 && (
                  <tr className="empty">
                    <td colSpan={4}>{trends?.tests.length ? 'No test matches that filter.' : 'No lab results reported for this patient yet.'}</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </section>
      )}

      {tab === 'log' && <RecordLog patientId={p.id} />}

      {tab === 'vitals' && (
        <section className="card">
          <h2>Vitals at each visit</h2>
          <div className="table-wrap gap-top">
            <table className="compact">
              <thead>
                <tr>
                  <th>Date</th>
                  <th className="num">Weight (kg)</th>
                  <th className="num">Height (cm)</th>
                  <th className="num">BMI</th>
                  <th className="num">BP</th>
                  <th className="num">Pulse</th>
                  <th className="num">Temp (°F)</th>
                  <th className="num">SpO₂</th>
                  <th className="num">Resp. rate</th>
                </tr>
              </thead>
              <tbody>
                {[...(trends?.vitals ?? [])].reverse().map((v, i) => (
                  <tr key={i} className="static">
                    <td>{longDate(v.date)}</td>
                    <td className="num">{v.weight_kg ?? '—'}</td>
                    <td className="num">{v.height_cm ?? '—'}</td>
                    <td className="num">{v.weight_kg && v.height_cm ? (v.weight_kg / (v.height_cm / 100) ** 2).toFixed(1) : '—'}</td>
                    <td className="num">{v.bp_systolic ? `${v.bp_systolic}/${v.bp_diastolic}` : '—'}</td>
                    <td className="num">{v.pulse ?? '—'}</td>
                    <td className="num">{v.temperature_f ?? '—'}</td>
                    <td className="num">{v.spo2 ? `${v.spo2}%` : '—'}</td>
                    <td className="num">{v.resp_rate ?? '—'}</td>
                  </tr>
                ))}
                {!trends?.vitals.length && (
                  <tr className="empty">
                    <td colSpan={9}>No vitals recorded yet.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
          {(trends?.vitals.filter((v) => v.weight_kg).length ?? 0) > 1 && (
            <p className="muted gap-top">
              Weight: <Spark values={trends!.vitals.map((v) => v.weight_kg).filter((n): n is number => n !== null)} />
            </p>
          )}
        </section>
      )}
    </div>
  )
}
