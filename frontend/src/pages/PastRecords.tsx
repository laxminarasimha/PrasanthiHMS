import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api } from '../api'
import { PatientPicker } from '../components/PatientPicker'
import { ReadingNote, paperStage } from '../components/PastRecords'
import type { Paper, Reading } from '../components/PastRecords'
import { clock, fullName, longDate } from '../format'

type List = { records: Paper[]; counts: { to_check: number; filed: number }; reading: Reading }

/**
 * Old papers across all patients: the pile still to be checked, oldest first, and what was filed lately.
 * One person can scan a bundle at the desk and another can work through the checking here.
 */
export function PastRecords() {
  const [status, setStatus] = useState<'to_check' | 'filed'>('to_check')
  const [data, setData] = useState<List | null>(null)
  const navigate = useNavigate()

  useEffect(() => {
    const load = () => api.get<List>('/past-records', { params: { status } }).then((res) => setData(res.data))
    void load()
    // The pile changes as the reading computer works and as others file papers.
    const timer = window.setInterval(load, 20000)
    return () => window.clearInterval(timer)
  }, [status])

  return (
    <div className="stack">
      <section className="card">
        <div className="toolbar">
          <div className="grow">
            <h1>Old paper records</h1>
            <span className="muted">Papers patients bring from before: scanned, read into a draft, checked by a person, then filed in their record.</span>
          </div>
        </div>
        <div className="scan-for">
          <span className="field-label">To scan papers in, find the patient:</span>
          <PatientPicker patient={null} onChange={(p) => p && navigate(`/patients/${p.id}#old-papers`)} />
        </div>
        {data && <ReadingNote reading={data.reading} />}
        <div className="tabs flush gap-top">
          <button className={status === 'to_check' ? 'active' : ''} onClick={() => setStatus('to_check')}>
            Waiting to be checked <span>{data?.counts.to_check ?? ''}</span>
          </button>
          <button className={status === 'filed' ? 'active' : ''} onClick={() => setStatus('filed')}>
            Filed <span>{data?.counts.filed ?? ''}</span>
          </button>
        </div>
      </section>

      <section className="card">
        <div className="table-wrap">
          <table className="compact">
            <thead>
              <tr>
                <th>Patient</th>
                <th>Paper</th>
                <th>{status === 'filed' ? 'Filed' : 'Scanned'}</th>
                <th>Where it stands</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {data?.records.map((p) => {
                const stage = paperStage(p, data.reading)
                const when = status === 'filed' ? p.checked_at : p.created_at
                const who = status === 'filed' ? p.checker : p.creator
                return (
                  <tr key={p.id} className="static">
                    <td>
                      {p.patient ? fullName(p.patient) : ''}
                      <small className="muted block">{p.patient?.uhid}</small>
                    </td>
                    <td>
                      {p.kind_label}
                      <small className="muted block">
                        {p.record_date ? longDate(p.record_date) : 'date not yet known'}
                        {p.hospital_name ? ` · ${p.hospital_name}` : ''} · {p.pages.length} {p.pages.length === 1 ? 'file' : 'files'}
                      </small>
                    </td>
                    <td>
                      {when ? `${longDate(when)} ${clock(when)}` : ''}
                      <small className="muted block">{who?.name}</small>
                    </td>
                    <td>
                      <span className={`pill ${stage.pill}`}>{stage.words}</span>
                    </td>
                    <td className="num">
                      <Link className="btn ghost small-btn" to={`/past-records/${p.id}`}>
                        {status === 'filed' ? 'Open' : 'Check'}
                      </Link>
                    </td>
                  </tr>
                )
              })}
              {data?.records.length === 0 && (
                <tr className="empty">
                  <td colSpan={5}>{status === 'filed' ? 'No old papers have been filed yet.' : 'Nothing is waiting to be checked.'}</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        {!data && <p className="loading">Loading…</p>}
      </section>
    </div>
  )
}
