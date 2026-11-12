import { monthStart, today } from '../format'

type Props = { from: string; to: string; onChange: (from: string, to: string) => void }

/** From/to date pickers with Today and This month shortcuts. */
export function DateRange({ from, to, onChange }: Props) {
  return (
    <>
      <input type="date" className="date" value={from} max={to} onChange={(e) => onChange(e.target.value || today(), to)} aria-label="From" />
      <span className="muted">to</span>
      <input type="date" className="date" value={to} min={from} max={today()} onChange={(e) => onChange(from, e.target.value || today())} aria-label="To" />
      <button type="button" className="btn ghost" onClick={() => onChange(today(), today())}>
        Today
      </button>
      <button type="button" className="btn ghost" onClick={() => onChange(monthStart(), today())}>
        This month
      </button>
    </>
  )
}
