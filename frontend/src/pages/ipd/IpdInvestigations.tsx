import { useCallback, useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { api } from '../../api'
import { LabOrderForm } from '../../components/LabOrderForm'
import { clock, longDate } from '../../format'
import { testLabel } from '../../labName'
import type { Admission, LabCatalogueTest, LabOrder, LabPackage } from '../../types'
import { LabOrderLink } from '../../components/RecordLinks'

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

type Props = {
  admission: Pick<Admission, 'id' | 'ipd_no' | 'admitted_at' | 'status' | 'bill_approved_at' | 'patient' | 'doctor'>
  /** Whether this person may order tests for the patient from here. */
  canOrder: boolean
  /** After an order: its bill has gone on the IPD bill, so the case is read again. */
  onOrdered: () => void
}

/**
 * Lab work during the stay: tests are ordered from here (their bill goes on the IPD bill), what is still with the lab
 * is listed, and the results are laid out one row for each value, one column for each day.
 */
export function IpdInvestigations({ admission, canOrder, onOrdered }: Props) {
  const [chart, setChart] = useState<Chart | null>(null)
  const [orders, setOrders] = useState<LabOrder[]>([])
  const [choices, setChoices] = useState<{ tests: LabCatalogueTest[]; packages: LabPackage[] } | null>(null)
  const [ordering, setOrdering] = useState(false)
  const [notice, setNotice] = useState('')

  const load = useCallback(() => {
    api.get<Chart>(`/ipd/admissions/${admission.id}/investigations`).then((res) => setChart(res.data))
    api.get<LabOrder[]>('/lab/orders', { params: { patient_id: admission.patient.id } }).then((res) => setOrders(res.data))
  }, [admission.id, admission.patient.id])
  useEffect(load, [load])

  const startOrdering = async () => {
    if (!choices) {
      const [tests, packages] = await Promise.all([
        api.get<LabCatalogueTest[]>('/lab/tests', { params: { brief: 1 } }),
        api.get<LabPackage[]>('/lab/packages').catch(() => ({ data: [] as LabPackage[] })),
      ])
      setChoices({ tests: tests.data, packages: packages.data })
    }
    setNotice('')
    setOrdering(true)
  }

  // What the lab still has to do for this stay.
  const awaited = orders.filter((o) => o.ordered_at.slice(0, 10) >= admission.admitted_at.slice(0, 10) && o.items.some((i) => !i.reported_at))
  const open = admission.status === 'admitted'
  const approved = Boolean(admission.bill_approved_at)

  return (
    <>
      {((canOrder && open) || notice) && (
        <div className="toolbar minor">
          {canOrder && open && (
            <button
              className="btn primary"
              onClick={startOrdering}
              disabled={approved}
              title={approved ? 'The IPD bill has been approved, so nothing more can go on it. Withdraw the approval first.' : 'The lab is told, and the lab bill goes on this IPD bill'}
            >
              + Order lab tests
            </button>
          )}
          {notice && <span className="pill ok">{notice}</span>}
        </div>
      )}

      {awaited.length > 0 && (
        <div className="ipd-awaited">
          <h3>With the lab</h3>
          <ul>
            {awaited.map((o) => (
              <li key={o.id}>
                <strong className="mono">
                  <LabOrderLink id={o.id}>{o.order_no}</LabOrderLink>
                </strong>
                <small className="muted">
                  {longDate(o.ordered_at).replace(/ \d{4}$/, '')} {clock(o.ordered_at)}
                </small>
                <span className="grow">
                  {o.items.map((i) => (
                    <span
                      key={i.id}
                      className={`pill ${i.reported_at ? 'ok' : i.collected_at ? 'status-ongoing' : 'off'}`}
                      title={i.reported_at ? 'Result entered' : i.collected_at ? 'Sample taken, result awaited' : 'Sample not taken yet'}
                    >
                      {testLabel(i)}
                    </span>
                  ))}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}

      {!chart ? (
        <p className="loading">Loading results…</p>
      ) : chart.rows.length === 0 ? (
        <p className="muted">No lab results have been reported during this stay. They appear here as the lab enters them.</p>
      ) : (
        <ResultsTable chart={chart} />
      )}

      {ordering &&
        choices &&
        createPortal(
          <div className="modal-back" onMouseDown={(e) => e.target === e.currentTarget && setOrdering(false)}>
            <LabOrderForm
              tests={choices.tests}
              packages={choices.packages}
              admission={{ id: admission.id, ipdNo: admission.ipd_no, patient: admission.patient, doctor: admission.doctor.name }}
              onClose={() => setOrdering(false)}
              onCreated={(order) => {
                setOrdering(false)
                setNotice(`${order.order_no} sent to the lab and added to the IPD bill.`)
                load()
                onOrdered()
              }}
            />
          </div>,
          document.body,
        )}
    </>
  )
}

/** One row for each test value, one column for each day. */
function ResultsTable({ chart }: { chart: Chart }) {
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
