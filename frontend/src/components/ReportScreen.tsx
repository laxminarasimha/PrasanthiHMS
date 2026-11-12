import { useEffect, useState, type ReactNode } from 'react'
import { api } from '../api'
import { downloadCsv, monthStart, rupees, today } from '../format'
import type { PharmacyReport as Report, ReportColumn } from '../types'
import { DateRange } from './DateRange'

const cell = (column: ReportColumn, value: string | number | null) =>
  value === null || value === undefined || value === '' ? '—' : column.type === 'money' ? rupees(Number(value)) : String(value)

type Props = {
  /** Where the reports live on the server, e.g. `/pharmacy/reports`. */
  base: string
  /** The report shown first, and its name until the full list arrives. */
  first: string
  firstLabel: string
  /** Extra controls placed before the report picker, such as a back link. */
  lead?: ReactNode
}

/** One screen for a family of reports: choose the report and the dates, read it, export or print it. */
export function ReportScreen({ base, first, firstLabel, lead }: Props) {
  const [type, setType] = useState(first)
  const [from, setFrom] = useState(monthStart())
  const [to, setTo] = useState(today())
  const [report, setReport] = useState<Report | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    api
      .get<Report>(`${base}/${type}`, { params: { from, to } })
      .then((res) => {
        setReport(res.data)
        setError('')
      })
      .catch(() => setError('Could not load this report. Choose a range of one year or less.'))
  }, [base, type, from, to])

  const reports = report?.reports ?? { [first]: firstLabel }

  return (
    <section className="card">
      <div className="toolbar no-print">
        {lead}
        <select className="narrow report-picker" value={type} onChange={(e) => setType(e.target.value)} aria-label="Report">
          {Object.entries(reports).map(([key, label]) => (
            <option key={key} value={key}>
              {label}
            </option>
          ))}
        </select>
        <DateRange from={from} to={to} onChange={(f, t) => { setFrom(f); setTo(t) }} />
        <span className="grow" />
        <button className="btn ghost" disabled={!report} onClick={() => report && downloadCsv(`${report.type}-${report.from}-to-${report.to}.csv`, report.columns.map((c) => c.label), report.rows.map((row) => report.columns.map((c) => row[c.key])))}>
          Export
        </button>
        <button className="btn ghost" onClick={() => window.print()}>
          Print
        </button>
      </div>
      {error && <p className="form-error">{error}</p>}
      {report && (
        <>
          <h1>{report.title}</h1>
          <p className="muted small">
            {report.from} to {report.to} · {report.rows.length} row(s)
          </p>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  {report.columns.map((c) => (
                    <th key={c.key} className={c.type === 'text' ? '' : 'num'}>
                      {c.label}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {report.rows.map((row, i) => (
                  <tr key={i} className="static">
                    {report.columns.map((c) => (
                      <td key={c.key} className={c.type === 'text' ? 'wrap' : 'num'}>
                        {cell(c, row[c.key])}
                      </td>
                    ))}
                  </tr>
                ))}
                {report.rows.length === 0 && (
                  <tr className="empty">
                    <td colSpan={report.columns.length}>Nothing to report for these dates.</td>
                  </tr>
                )}
              </tbody>
              {report.rows.length > 0 && Object.keys(report.totals).length > 0 && (
                <tfoot>
                  <tr>
                    {report.columns.map((c, i) => (
                      <td key={c.key} className={c.type === 'text' ? '' : 'num'}>
                        {c.key in report.totals ? cell(c, report.totals[c.key]) : i === 0 ? 'Total' : ''}
                      </td>
                    ))}
                  </tr>
                </tfoot>
              )}
            </table>
          </div>
        </>
      )}
    </section>
  )
}
