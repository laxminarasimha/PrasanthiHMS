import { useState } from 'react'
import { longDate, today } from '../format'

type Props = {
  /** The day chosen, as YYYY-MM-DD. Today means an ordinary entry. */
  value: string
  onChange: (day: string) => void
  /** A bill being made, or money being taken. */
  what: 'bill' | 'money'
  /** The earliest day that makes sense here: money cannot come before its bill. */
  min?: string
}

const WORDS = {
  bill: { ask: 'Was this missed on an earlier day?', label: 'Bill date', note: 'This bill will be dated' },
  money: { ask: 'Was this money taken on an earlier day?', label: 'Date received', note: 'This money will be counted on' },
} as const

/** A year back, the furthest the server takes. */
const furthest = () => {
  const d = new Date()
  d.setDate(d.getDate() - 365)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/**
 * The day a bill or a payment belongs to. It is today unless the person says otherwise: entering something that
 * was missed on an earlier day is a step taken on purpose, and once taken the earlier day stays in plain sight
 * until it is saved, because the day's lists, the reports and the cash expected at that day's closing all change.
 */
export function EarlierDate({ value, onChange, what, min }: Props) {
  const [open, setOpen] = useState(false)
  const words = WORDS[what]
  const back = value !== today()

  if (!open && !back) {
    return (
      <p className="earlier-day ask">
        <button type="button" className="link" onClick={() => setOpen(true)}>
          {words.ask}
        </button>
      </p>
    )
  }

  return (
    <div className={`earlier-day${back ? ' on' : ''}`}>
      <label className="field">
        <span className="field-label">{words.label}</span>
        <input type="date" value={value} min={min && min > furthest() ? min : furthest()} max={today()} onChange={(e) => onChange(e.target.value || today())} />
      </label>
      {back ? (
        <p>
          <strong>
            {words.note} {longDate(value)}, not today.
          </strong>{' '}
          That day’s lists and reports will show it. If that day’s accounts are closed, an admin has to reopen the day first.{' '}
          <button
            type="button"
            className="link"
            onClick={() => {
              onChange(today())
              setOpen(false)
            }}
          >
            Use today instead
          </button>
        </p>
      ) : (
        <p className="muted">Choose the day it belongs to. Leave it on today for an ordinary entry.</p>
      )}
    </div>
  )
}
