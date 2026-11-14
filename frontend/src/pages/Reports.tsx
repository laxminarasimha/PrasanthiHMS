import { useEffect, useState } from 'react'
import { api } from '../api'
import { FindPatients } from '../components/FindPatients'
import { ReportScreen } from '../components/ReportScreen'
import { modeLabel, rupees, today } from '../format'

type Department = { label: string; billed: number; collected: Record<string, number>; collected_total: number }
type Summary = {
  from: string
  to: string
  modes: string[]
  departments: Record<string, Department>
  counts: Record<string, number>
}

const COUNTS: [string, string][] = [
  ['new_patients', 'New patients'],
  ['appointments', 'Appointments'],
  ['consultations', 'Consultations completed'],
  ['lab_orders', 'Lab orders'],
  ['pharmacy_bills', 'Pharmacy bills'],
  ['admissions', 'Admissions'],
  ['discharges', 'Discharges'],
  ['inpatients_now', 'Inpatients right now'],
]

const firstOfMonth = () => today().slice(0, 8) + '01'

export function Reports() {
  const [from, setFrom] = useState(today())
  const [to, setTo] = useState(today())
  const [summary, setSummary] = useState<Summary | null>(null)
  const [error, setError] = useState('')
  const [tab, setTab] = useState<'summary' | 'detailed' | 'find'>('summary')

  useEffect(() => {
    if (from > to) return
    api
      .get<Summary>('/reports/summary', { params: { from, to } })
      .then((res) => {
        setSummary(res.data)
        setError('')
      })
      .catch(() => setError('Could not load the report. Choose a range of one year or less.'))
  }, [from, to])

  const departments = summary ? Object.values(summary.departments) : []
  const billed = departments.reduce((sum, d) => sum + d.billed, 0)
  const collected = departments.reduce((sum, d) => sum + d.collected_total, 0)

  const tabs = (
    <div className="tabs no-print">
      <button className={tab === 'summary' ? 'active' : ''} onClick={() => setTab('summary')}>
        Summary
      </button>
      <button className={tab === 'detailed' ? 'active' : ''} onClick={() => setTab('detailed')}>
        Front desk and doctor reports
      </button>
      <button className={tab === 'find' ? 'active' : ''} onClick={() => setTab('find')}>
        Find patients
      </button>
    </div>
  )

  if (tab === 'find') {
    return (
      <>
        {tabs}
        <FindPatients />
      </>
    )
  }

  if (tab === 'detailed') {
    return (
      <>
        {tabs}
        <ReportScreen base="/reports/list" first="bills" firstLabel="Front desk: bill report" />
      </>
    )
  }

  return (
    <>
    {tabs}
    <section className="card">
      <div className="toolbar">
        <h1>Organisation report</h1>
        <input type="date" className="date" value={from} max={to} onChange={(e) => setFrom(e.target.value || today())} aria-label="From" />
        <span className="muted">to</span>
        <input type="date" className="date" value={to} min={from} max={today()} onChange={(e) => setTo(e.target.value || today())} aria-label="To" />
        <button
          className="btn ghost"
          onClick={() => {
            setFrom(today())
            setTo(today())
          }}
        >
          Today
        </button>
        <button
          className="btn ghost"
          onClick={() => {
            setFrom(firstOfMonth())
            setTo(today())
          }}
        >
          This month
        </button>
        <span className="grow" />
        <button className="btn ghost no-print" onClick={() => window.print()}>
          Print
        </button>
      </div>

      {from > to && <p className="form-error">The "from" date is after the "to" date.</p>}
      {error && <p className="form-error">{error}</p>}

      {summary && (
        <>
          <div className="tiles">
            <div className="tile">
              <span>Total billed</span>
              <strong>{rupees(billed)}</strong>
            </div>
            <div className="tile">
              <span>Total collected</span>
              <strong>{rupees(collected)}</strong>
            </div>
            {COUNTS.map(([key, label]) => (
              <div className="tile" key={key}>
                <span>{label}</span>
                <strong>{summary.counts[key]}</strong>
              </div>
            ))}
          </div>

          <h3>Billing and collections by department</h3>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th />
                  {departments.map((d) => (
                    <th key={d.label} className="num">
                      {d.label}
                    </th>
                  ))}
                  <th className="num">All departments</th>
                </tr>
              </thead>
              <tbody>
                <tr className="static">
                  <td>
                    <strong>Billed</strong>
                  </td>
                  {departments.map((d) => (
                    <td key={d.label} className="num">
                      {rupees(d.billed)}
                    </td>
                  ))}
                  <td className="num strong">{rupees(billed)}</td>
                </tr>
                <tr className="static">
                  <td>
                    <strong>Collected</strong>
                  </td>
                  {departments.map((d) => (
                    <td key={d.label} className="num">
                      {rupees(d.collected_total)}
                    </td>
                  ))}
                  <td className="num strong">{rupees(collected)}</td>
                </tr>
                {summary.modes.map((mode) => (
                  <tr key={mode} className="static sub">
                    <td>{modeLabel(mode)}</td>
                    {departments.map((d) => (
                      <td key={d.label} className="num">
                        {rupees(d.collected[mode])}
                      </td>
                    ))}
                    <td className="num">{rupees(departments.reduce((sum, d) => sum + d.collected[mode], 0))}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="muted small">
            "Billed" is what was charged in this period. "Collected" is money actually received in this period, net of refunds, whenever the bill was made — so the two need not match.
          </p>
        </>
      )}
    </section>
    </>
  )
}
