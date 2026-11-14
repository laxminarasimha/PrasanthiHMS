import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../../api'
import { ClinicFooter, ClinicHeader } from '../../components/ClinicHeader'
import { ObservationGraphs, type Reading } from '../../components/ObservationGraphs'
import { ADMISSION_STATUS, clock, fullName, longDate } from '../../format'
import type { Admission } from '../../types'

type Who = { name: string } | null
type Vital = Reading & { id: number; pain_score: number | null; notes: string | null; recorder: Who }
type Dose = { id: number; given_at: string; status: 'given' | 'held' | 'refused'; remarks: string | null; giver: Who }
type Order = { id: number; kind: string; title: string; dose: string | null; route: string | null; frequency: string | null; instructions: string | null; started_at: string; stopped_at: string | null; orderer: Who; stopper: Who; doses: Dose[] }
type Fluid = { id: number; recorded_at: string; direction: 'in' | 'out'; kind: string; amount_ml: number; notes: string | null; recorder: Who }
type Chart = { vitals: Vital[]; orders: Order[]; fluids: Fluid[]; fluid_days: { date: string; in: number; out: number; balance: number }[] }

const at = (value: string) => `${longDate(value)} ${clock(value)}`
const DOSE = { given: 'Given', held: 'Held', refused: 'Refused' } as const

/** The whole bedside chart of one stay on paper: observations with graphs, orders and doses, fluids. */
export function IpdChartPrint() {
  const { id } = useParams()
  const [a, setA] = useState<Admission | null>(null)
  const [chart, setChart] = useState<Chart | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    Promise.all([api.get<Admission>(`/ipd/admissions/${id}`), api.get<Chart>(`/ipd/admissions/${id}/chart`)])
      .then(([admission, c]) => {
        setA(admission.data)
        setChart(c.data)
        setTimeout(() => window.print(), 700)
      })
      .catch(() => setError('The chart could not be opened. It is for doctors and nurses.'))
  }, [id])

  if (error) return <p className="form-error">{error}</p>
  if (!a || !chart) return <p className="loading">Loading chart…</p>
  const oldestFirst = <T,>(rows: T[]) => [...rows].reverse()

  return (
    <div className="receipt prescription chart-print">
      <ClinicHeader>
        <h2>Inpatient chart</h2>
      </ClinicHeader>
      <div className="receipt-meta">
        <div>
          <strong>{fullName(a.patient)}</strong>
          <br />
          {a.patient.age_label} / {a.patient.gender.charAt(0).toUpperCase()} · Patient ID: {a.patient.uhid}
          <br />
          Doctor: {a.doctor.name}
        </div>
        <div className="right">
          IPD no: <strong>{a.ipd_no}</strong>
          <br />
          Admitted: {at(a.admitted_at)}
          <br />
          {a.discharged_at ? `${ADMISSION_STATUS[a.status]}: ${at(a.discharged_at)}` : `Still admitted${a.bed ? ` · ${a.bed.ward}, bed ${a.bed.bed_no}` : ''}`}
        </div>
      </div>

      <section>
        <h3>Observations</h3>
        <ObservationGraphs readings={chart.vitals} />
        {chart.vitals.length === 0 ? (
          <p>None recorded.</p>
        ) : (
          <table>
            <thead>
              <tr>
                <th>Time</th>
                <th className="num">Temp °F</th>
                <th className="num">Pulse</th>
                <th className="num">BP</th>
                <th className="num">Resp</th>
                <th className="num">SpO₂</th>
                <th className="num">Sugar</th>
                <th className="num">Pain</th>
                <th>Note</th>
                <th>By</th>
              </tr>
            </thead>
            <tbody>
              {oldestFirst(chart.vitals).map((v) => (
                <tr key={v.id}>
                  <td>{at(v.recorded_at)}</td>
                  <td className="num">{v.temperature_f ?? ''}</td>
                  <td className="num">{v.pulse ?? ''}</td>
                  <td className="num">{v.bp_systolic ? `${v.bp_systolic}/${v.bp_diastolic}` : ''}</td>
                  <td className="num">{v.resp_rate ?? ''}</td>
                  <td className="num">{v.spo2 ?? ''}</td>
                  <td className="num">{v.blood_sugar ?? ''}</td>
                  <td className="num">{v.pain_score ?? ''}</td>
                  <td>{v.notes ?? ''}</td>
                  <td>{v.recorder?.name ?? ''}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>

      <section>
        <h3>Orders and medicines given</h3>
        {chart.orders.length === 0 && <p>No orders written.</p>}
        {[...chart.orders].sort((x, y) => x.started_at.localeCompare(y.started_at)).map((o) => (
          <div key={o.id} className="print-order">
            <p>
              <strong>{o.title}</strong> {[o.dose, o.route, o.frequency].filter(Boolean).join(' · ')}
              {o.instructions ? ` · ${o.instructions}` : ''}
              <br />
              <small>
                Ordered {at(o.started_at)} by {o.orderer?.name ?? 'a doctor'}
                {o.stopped_at ? `; stopped ${at(o.stopped_at)} by ${o.stopper?.name ?? 'a doctor'}` : ''}
              </small>
            </p>
            {o.doses.length > 0 && (
              <table>
                <tbody>
                  {oldestFirst(o.doses).map((d) => (
                    <tr key={d.id}>
                      <td>{at(d.given_at)}</td>
                      <td>{DOSE[d.status]}</td>
                      <td>{d.remarks ?? ''}</td>
                      <td>{d.giver?.name ?? ''}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        ))}
      </section>

      <section>
        <h3>Intake and output</h3>
        {chart.fluids.length === 0 ? (
          <p>None recorded.</p>
        ) : (
          <>
            <table>
              <thead>
                <tr>
                  <th>Day</th>
                  <th className="num">Intake (ml)</th>
                  <th className="num">Output (ml)</th>
                  <th className="num">Balance (ml)</th>
                </tr>
              </thead>
              <tbody>
                {oldestFirst(chart.fluid_days).map((d) => (
                  <tr key={d.date}>
                    <td>{longDate(d.date)}</td>
                    <td className="num">{d.in}</td>
                    <td className="num">{d.out}</td>
                    <td className="num">
                      {d.balance > 0 ? '+' : ''}
                      {d.balance}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <table>
              <thead>
                <tr>
                  <th>Time</th>
                  <th>What</th>
                  <th className="num">Intake (ml)</th>
                  <th className="num">Output (ml)</th>
                  <th>Note</th>
                  <th>By</th>
                </tr>
              </thead>
              <tbody>
                {oldestFirst(chart.fluids).map((f) => (
                  <tr key={f.id}>
                    <td>{at(f.recorded_at)}</td>
                    <td>{f.kind}</td>
                    <td className="num">{f.direction === 'in' ? f.amount_ml : ''}</td>
                    <td className="num">{f.direction === 'out' ? f.amount_ml : ''}</td>
                    <td>{f.notes ?? ''}</td>
                    <td>{f.recorder?.name ?? ''}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </>
        )}
      </section>
      <ClinicFooter />
    </div>
  )
}
