import { useState } from 'react'

export type Reading = { recorded_at: string; temperature_f: number | null; pulse: number | null; bp_systolic: number | null; bp_diastolic: number | null; resp_rate: number | null; spo2: number | null; blood_sugar: number | null }

/** Colours checked for colour-blind separation and contrast on a white surface (dataviz palette slots 1 and 2). */
const BLUE = '#2a78d6'
const ORANGE = '#eb6834'

type Series = { key: keyof Reading; label: string; color: string }
const PANELS: { title: string; unit: string; series: Series[]; digits?: number }[] = [
  { title: 'Temperature', unit: '°F', digits: 1, series: [{ key: 'temperature_f', label: 'Temperature', color: BLUE }] },
  { title: 'Pulse', unit: '/min', series: [{ key: 'pulse', label: 'Pulse', color: BLUE }] },
  { title: 'Blood pressure', unit: 'mmHg', series: [{ key: 'bp_systolic', label: 'Systolic', color: BLUE }, { key: 'bp_diastolic', label: 'Diastolic', color: ORANGE }] },
  { title: 'SpO₂', unit: '%', series: [{ key: 'spo2', label: 'SpO₂', color: BLUE }] },
  { title: 'Respiration', unit: '/min', series: [{ key: 'resp_rate', label: 'Respiration', color: BLUE }] },
  { title: 'Blood sugar', unit: 'mg/dL', series: [{ key: 'blood_sugar', label: 'Blood sugar', color: BLUE }] },
]

const W = 360
const H = 150
const PAD = { left: 34, right: 36, top: 10, bottom: 22 }
const time = (value: string) => new Date(value.replace(' ', 'T')).getTime()
const stamp = (ms: number, withDay: boolean) => {
  const d = new Date(ms)
  const hour = d.getHours() % 12 || 12
  const t = `${hour}${d.getMinutes() ? ':' + String(d.getMinutes()).padStart(2, '0') : ''} ${d.getHours() < 12 ? 'AM' : 'PM'}`
  return withDay ? `${d.getDate()} ${d.toLocaleString('en-IN', { month: 'short' })}, ${t}` : t
}

/** Round, evenly spaced values that cover the data, for the y axis. */
function ticks(low: number, high: number): number[] {
  const span = high - low || 1
  const rough = span / 3
  const power = 10 ** Math.floor(Math.log10(rough))
  const step = [1, 2, 5, 10].map((m) => m * power).find((s) => s >= rough) ?? rough
  const first = Math.floor(low / step) * step
  const out: number[] = []
  for (let v = first; v <= high + step * 0.999; v += step) out.push(Number(v.toFixed(4)))
  return out
}

function Panel({ panel, readings, from, to, hover, onHover }: { panel: (typeof PANELS)[number]; readings: Reading[]; from: number; to: number; hover: number | null; onHover: (t: number | null) => void }) {
  const lines = panel.series.map((s) => ({ ...s, points: readings.filter((r) => r[s.key] !== null).map((r) => ({ t: time(r.recorded_at), v: Number(r[s.key]) })) }))
  const values = lines.flatMap((l) => l.points.map((p) => p.v))
  if (values.length === 0) return null

  const marks = ticks(Math.min(...values), Math.max(...values))
  const low = marks[0]
  const high = marks[marks.length - 1]
  const x = (t: number) => PAD.left + ((t - from) / (to - from || 1)) * (W - PAD.left - PAD.right)
  const y = (v: number) => PAD.top + (1 - (v - low) / (high - low || 1)) * (H - PAD.top - PAD.bottom)
  const show = (v: number) => (panel.digits ? v.toFixed(panel.digits) : String(v))

  // The reading nearest to where the pointer is, shared across every panel so they line up.
  const times = [...new Set(lines.flatMap((l) => l.points.map((p) => p.t)))].sort((a, b) => a - b)
  const nearest = hover === null ? null : times.reduce((best, t) => (Math.abs(t - hover) < Math.abs(best - hover) ? t : best), times[0])
  const picked = nearest === null ? [] : lines.map((l) => ({ ...l, point: l.points.find((p) => p.t === nearest) })).filter((l) => l.point)
  const multiDay = new Date(from).toDateString() !== new Date(to).toDateString()

  const move = (e: React.PointerEvent<SVGSVGElement>) => {
    const box = e.currentTarget.getBoundingClientRect()
    const px = ((e.clientX - box.left) / box.width) * W
    onHover(from + ((px - PAD.left) / (W - PAD.left - PAD.right)) * (to - from))
  }

  return (
    <figure className="obs-panel">
      <figcaption>
        <strong>{panel.title}</strong> <span className="muted">{panel.unit}</span>
        {lines.length > 1 && (
          <span className="obs-legend">
            {lines.map((l) => (
              <span key={l.label}>
                <i style={{ background: l.color }} />
                {l.label}
              </span>
            ))}
          </span>
        )}
      </figcaption>
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={`${panel.title} over time: ${lines.map((l) => `${l.label} ${l.points.map((p) => show(p.v)).join(', ')}`).join('; ')}`} onPointerMove={move} onPointerLeave={() => onHover(null)}>
        {marks.map((m) => (
          <g key={m}>
            <line x1={PAD.left} x2={W - PAD.right} y1={y(m)} y2={y(m)} className="obs-grid" />
            <text x={PAD.left - 6} y={y(m) + 3.5} textAnchor="end" className="obs-tick">
              {m}
            </text>
          </g>
        ))}
        <text x={PAD.left} y={H - 5} className="obs-tick">
          {stamp(from, multiDay)}
        </text>
        {to > from && (
          <text x={W - PAD.right} y={H - 5} textAnchor="end" className="obs-tick">
            {stamp(to, multiDay)}
          </text>
        )}
        {nearest !== null && picked.length > 0 && <line x1={x(nearest)} x2={x(nearest)} y1={PAD.top} y2={H - PAD.bottom} className="obs-cross" />}
        {lines.map((l) => (
          <g key={l.label}>
            {l.points.length > 1 && <polyline points={l.points.map((p) => `${x(p.t)},${y(p.v)}`).join(' ')} fill="none" stroke={l.color} strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />}
            {l.points.map((p) => (
              <circle key={p.t} cx={x(p.t)} cy={y(p.v)} r={p.t === nearest ? 5 : 4} fill={l.color} stroke="#fff" strokeWidth={2} />
            ))}
            {/* The latest value is written at the end of its line; the rest are in the table and on hover. */}
            {l.points.length > 0 && (
              <text x={x(l.points[l.points.length - 1].t) + 8} y={y(l.points[l.points.length - 1].v) + 4} className="obs-end">
                {show(l.points[l.points.length - 1].v)}
              </text>
            )}
          </g>
        ))}
      </svg>
      {nearest !== null && picked.length > 0 && (
        <div className="obs-tip" style={{ left: `${(x(nearest) / W) * 100}%` }} data-side={x(nearest) > W / 2 ? 'left' : 'right'}>
          <small>{stamp(nearest, true)}</small>
          {picked.map((l) => (
            <span key={l.label}>
              <i style={{ background: l.color }} />
              <b>
                {show(l.point!.v)} {panel.unit}
              </b>{' '}
              {lines.length > 1 ? l.label : ''}
            </span>
          ))}
        </div>
      )}
    </figure>
  )
}

/** Each observation drawn over the stay, one small graph per measure on the same time line. */
export function ObservationGraphs({ readings }: { readings: Reading[] }) {
  const [hover, setHover] = useState<number | null>(null)
  if (readings.length < 2) return null
  const ordered = [...readings].sort((a, b) => time(a.recorded_at) - time(b.recorded_at))
  const from = time(ordered[0].recorded_at)
  const to = time(ordered[ordered.length - 1].recorded_at)

  return (
    <div className="obs-graphs">
      {PANELS.map((panel) => (
        <Panel key={panel.title} panel={panel} readings={ordered} from={from} to={to} hover={hover} onHover={setHover} />
      ))}
    </div>
  )
}
