import { useEffect, useState } from 'react'
import { api } from '../../api'
import { longDate } from '../../format'

type Chart = {
  dates: string[]
  rows: { test: string; parameter: string; unit: string | null; reference_range: string | null; values: Record<string, { value: string; flag: 'high' | 'low' | null }> }[]
}

/** A small line showing how a value moved across the days, when the values are numbers. */
function Trend({ values }: { values: (number | null)[] }) {
  const points = values.map((v, i) => (v === null ? null : { i, v })).filter((p): p is { i: number; v: number } => p !== null)
  if (points.length < 2) return null
  const min = Math.min(...points.map((p) => p.v))
  const max = Math.max(...points.map((p) => p.v))
  const x = (i: number) => (values.length === 1 ? 30 : 4 + (i * 52) / (values.length - 1))
  const y = (v: number) => (max === min ? 10 : 17 - ((v - min) * 14) / (max - min))
  return (
    <svg className="trend" viewBox="0 0 60 20" role="img" aria-label={`From ${points[0].v} to ${points[points.length - 1].v}`}>
      <polyline fill="none" stroke="currentColor" strokeWidth="1.5" points={points.map((p) => `${x(p.i)},${y(p.v)}`).join(' ')} />
      {points.map((p) => (
        <circle key={p.i} cx={x(p.i)} cy={y(p.v)} r="1.8" fill="currentColor" />
      ))}
    </svg>
  )
}

/** Lab results during the stay: one row for each test value, one column for each day. */
export function IpdInvestigations({ admissionId }: { admissionId: number }) {
  const [chart, setChart] = useState<Chart | null>(null)

  useEffect(() => {
    api.get<Chart>(`/ipd/admissions/${admissionId}/investigations`).then((res) => setChart(res.data))
  }, [admissionId])

  if (!chart) return <p className="loading">Loading results…</p>
  if (chart.rows.length === 0) return <p className="muted">No lab results have been reported during this stay. Tests ordered for the patient in Lab appear here once reported.</p>

  return (
    <div className="table-wrap">
      <table className="compact">
        <thead>
          <tr>
            <th>Test</th>
            {chart.dates.map((d) => (
              <th key={d} className="num">
                {longDate(d).replace(/ \d{4}$/, '')}
              </th>
            ))}
            <th>Trend</th>
            <th>Unit</th>
            <th>Normal range</th>
          </tr>
        </thead>
        <tbody>
          {chart.rows.map((row) => (
            <tr key={`${row.test}|${row.parameter}`} className="static">
              <td>
                <strong>{row.parameter}</strong>
                {row.parameter !== row.test && <small className="muted block">{row.test}</small>}
              </td>
              {chart.dates.map((d) => {
                const cell = row.values[d]
                return (
                  <td key={d} className={`num ${cell?.flag ? 'due strong' : ''}`}>
                    {cell ? `${cell.value}${cell.flag === 'high' ? ' ↑' : cell.flag === 'low' ? ' ↓' : ''}` : '—'}
                  </td>
                )
              })}
              <td>
                <Trend values={chart.dates.map((d) => (row.values[d] && !Number.isNaN(Number(row.values[d].value)) ? Number(row.values[d].value) : null))} />
              </td>
              <td>{row.unit ?? ''}</td>
              <td className="wrap">{row.reference_range ?? ''}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
