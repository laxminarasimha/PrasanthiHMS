import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth'
import { api } from '../api'
import { DayClose } from '../components/DayClose'
import { FindPatients } from '../components/FindPatients'
import { OwnerFigures } from '../components/OwnerFigures'
import { ReportScreen } from '../components/ReportScreen'
import { modeLabel, rupees, today } from '../format'
import { MoneyByMode } from '../components/MoneyByMode'
import { useChoiceInAddress, useRangeInAddress } from '../rangeInAddress'

type Department = { label: string; billed: number; collected: Record<string, number>; collected_total: number; /** Bills made in the period (for the IPD, cases in the hospital) and what is still unpaid on them. */ bills?: number; due?: number }
type Summary = {
  from: string
  to: string
  modes: string[]
  departments: Record<string, Department>
  counts: Record<string, number>
  paid_out?: { expenses: number; suppliers: number; total: number; cash: number; by_category: Record<string, number> }
}

const COUNTS: [string, string][] = [
  ['new_patients', 'New patients'],
  ['appointments', 'Appointments'],
  ['consultations', 'Consultations completed'],
  ['visit_pads', 'With a visit pad written'],
  ['lab_orders', 'Lab orders'],
  ['pharmacy_bills', 'Pharmacy bills'],
  ['admissions', 'Admissions'],
  ['discharges', 'Discharges'],
  ['inpatients_now', 'Inpatients right now'],
]

/** What each department's count is a count of. */
const COUNTED: Record<string, [string, string]> = { consultation: ['bill', 'bills'], lab: ['lab bill', 'lab bills'], pharmacy: ['pharmacy bill', 'pharmacy bills'], ipd: ['inpatient case', 'inpatient cases'] }

const firstOfMonth = () => today().slice(0, 8) + '01'
const TABS = ['summary', 'detailed', 'find', 'close'] as const

export function Reports() {
  // The period and the tab are kept in the address, so a refresh or a shared link shows the same report.
  const [from, to, setRange] = useRangeInAddress('today')
  const [summary, setSummary] = useState<Summary | null>(null)
  const [error, setError] = useState('')
  const [tab, setTab] = useChoiceInAddress('tab', 'summary', TABS)
  const { user } = useAuth()
  const navigate = useNavigate()

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
  const due = Math.round(departments.reduce((sum, d) => sum + (d.due ?? 0), 0) * 100) / 100
  const byMode = (mode: string) => Math.round(departments.reduce((sum, d) => sum + (d.collected[mode] ?? 0), 0) * 100) / 100
  // Where each department's own detailed reports are, for a person who may open them.
  const reportsOf: Record<string, string | null> = {
    consultation: `/reports?tab=detailed&report=bills&from=${from}&to=${to}`,
    lab: `/reports?tab=detailed&report=lab-tests&from=${from}&to=${to}`,
    pharmacy: user?.can_view_pharmacy ? '/pharmacy/reports' : null,
    ipd: user?.can_ipd ? '/ipd/reports' : null,
  }

  const tabs = (
    <div className="tabs no-print">
      <button className={tab === 'summary' ? 'active' : ''} onClick={() => setTab('summary')}>
        Summary
      </button>
      <button className={tab === 'detailed' ? 'active' : ''} onClick={() => setTab('detailed')}>
        Front desk, doctor and lab reports
      </button>
      {user?.can_view_pharmacy && (
        <button type="button" onClick={() => navigate('/pharmacy/reports')} title="Opens the pharmacy’s own reports">
          Pharmacy reports
        </button>
      )}
      {user?.can_ipd && (
        <button type="button" onClick={() => navigate('/ipd/reports')} title="Opens the IPD’s own reports">
          IPD reports
        </button>
      )}
      <button className={tab === 'find' ? 'active' : ''} onClick={() => setTab('find')}>
        Find patients
      </button>
      <button className={tab === 'close' ? 'active' : ''} onClick={() => setTab('close')}>
        Day close
      </button>
    </div>
  )

  if (tab === 'close') {
    return (
      <>
        {tabs}
        <DayClose />
      </>
    )
  }

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
        <input type="date" className="date" value={from} max={to} onChange={(e) => setRange(e.target.value || today(), to)} aria-label="From" />
        <span className="muted">to</span>
        <input type="date" className="date" value={to} min={from} max={today()} onChange={(e) => setRange(from, e.target.value || today())} aria-label="To" />
        <button
          className="btn ghost"
          onClick={() => setRange(today(), today())}
        >
          Today
        </button>
        <button
          className="btn ghost"
          onClick={() => setRange(firstOfMonth(), today())}
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
          <div className="print-together">
            <h3>The whole hospital, and each department</h3>
            <div className="dept-cards">
              <div className="dept-card whole">
                <strong className="dept-name">Whole hospital</strong>
                <dl>
                  <dt>Billed</dt>
                  <dd>{rupees(billed)}</dd>
                  <dt className="strong">Collected</dt>
                  <dd className="strong">{rupees(collected)}</dd>
                  <dt className="sub">Cash</dt>
                  <dd className="sub">{rupees(byMode('cash'))}</dd>
                  <dt className="sub">UPI</dt>
                  <dd className="sub">{rupees(byMode('upi'))}</dd>
                  <dt className="sub">Card</dt>
                  <dd className="sub">{rupees(byMode('card'))}</dd>
                  <dt>Still unpaid</dt>
                  <dd className={due > 0 ? 'due' : ''}>{rupees(due)}</dd>
                </dl>
              </div>
              {Object.entries(summary.departments).map(([key, d]) => (
                <div className="dept-card" key={key}>
                  <strong className="dept-name">{d.label}</strong>
                  <dl>
                    <dt>Billed</dt>
                    <dd>{rupees(d.billed)}</dd>
                    <dt className="strong">Collected</dt>
                    <dd className="strong">{rupees(d.collected_total)}</dd>
                    <dt className="sub">Cash</dt>
                    <dd className="sub">{rupees(d.collected.cash ?? 0)}</dd>
                    <dt className="sub">UPI</dt>
                    <dd className="sub">{rupees(d.collected.upi ?? 0)}</dd>
                    <dt className="sub">Card</dt>
                    <dd className="sub">{rupees(d.collected.card ?? 0)}</dd>
                    <dt>Still unpaid</dt>
                    <dd className={(d.due ?? 0) > 0 ? 'due' : ''}>{rupees(d.due ?? 0)}</dd>
                  </dl>
                  <small className="muted">
                    {d.bills ?? 0} {COUNTED[key]?.[(d.bills ?? 0) === 1 ? 0 : 1] ?? 'bills'}
                    {reportsOf[key] && (
                      <>
                        {' · '}
                        <Link className="no-print" to={reportsOf[key]!}>
                          Its reports
                        </Link>
                      </>
                    )}
                  </small>
                </div>
              ))}
            </div>
            <p className="muted small">
              “Billed” is what was charged in these dates. “Collected” is money received in these dates, less refunds, whenever the bill was made. “Still unpaid” is what is owed today on the bills of these dates; for the IPD, on the cases that were in the
              hospital in these dates.
            </p>
          </div>

          <h3>What was done</h3>
          <div className="tiles">
            {COUNTS.map(([key, label]) => (
              <div className="tile" key={key}>
                <span>{label}</span>
                <strong>{summary.counts[key]}</strong>
                {/* An order can hold several tests: both are said, so the figure cannot be read as the other. */}
                {key === 'lab_orders' && summary.counts.lab_tests !== undefined && (
                  <small className="muted">
                    {summary.counts.lab_tests} {summary.counts.lab_tests === 1 ? 'test' : 'tests'} on them
                  </small>
                )}
              </div>
            ))}
          </div>

          <MoneyByMode
            amounts={Object.fromEntries(summary.modes.map((mode) => [mode, Math.round(departments.reduce((sum, d) => sum + d.collected[mode], 0) * 100) / 100]))}
            note="All departments together, net of refunds. The table below gives the same figures department by department."
          />

          {summary.paid_out && (
            <div className="print-together">
              <h3>Paid out</h3>
              <div className="tiles small">
                <div className="tile"><span>Expenses</span><strong>{rupees(summary.paid_out.expenses)}</strong></div>
                <div className="tile"><span>Paid to medicine suppliers</span><strong>{rupees(summary.paid_out.suppliers)}</strong></div>
                <div className="tile"><span>All paid out</span><strong>{rupees(summary.paid_out.total)}</strong></div>
                <div className="tile"><span>Collected less paid out</span><strong className={collected - summary.paid_out.total < 0 ? 'due' : 'settled'}>{rupees(Math.round((collected - summary.paid_out.total) * 100) / 100)}</strong></div>
              </div>
              {Object.keys(summary.paid_out.by_category).length > 0 && (
                <p className="expense-headings">
                  {Object.entries(summary.paid_out.by_category).map(([name, amount]) => (
                    <span key={name} className="chip">
                      {name} <strong>{rupees(amount)}</strong>
                    </span>
                  ))}
                </p>
              )}
            </div>
          )}

          <div className="print-together">
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
          </div>

          <OwnerFigures from={from} to={to} />
        </>
      )}
    </section>
    </>
  )
}
