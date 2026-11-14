import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors, type FieldErrors } from '../api'
import { clock, downloadCsv, longDate, modeLabel, rupees, today } from '../format'

type Row = { text: string; detail?: string | null; to?: string | null }
type Check = { key: string; label: string; blocking: boolean; help: string; count: number; rows: Row[] }
type Figure = { label: string; value: number; money: boolean }
type Section = { key: string; label: string; figures: Figure[]; checks: Check[] }
type Department = { label: string; billed: number; collected: Record<string, number>; collected_total: number; refunded: number; due: number }
type Closing = {
  id: number
  closed_by_name: string
  closed_at: string
  expected_cash: number
  counted_cash: number
  variance: number
  variance_note: string | null
  exceptions: number
  override_reason: string | null
  reopened_by_name: string | null
  reopened_at: string | null
  reopen_reason: string | null
}
type Book = {
  day: string
  sections: Section[]
  modes: string[]
  departments: Record<string, Department>
  expected_cash: number
  blocking: number
  notes: number
  closed: Closing | null
  history: Closing[]
  today: string
  may_close: boolean
  can_close: boolean
  can_reopen: boolean
  recent: { day: string; closed: boolean }[]
}

/** A cash difference in words: "short by ₹10", "over by ₹10". */
const gap = (variance: number) => `${variance < 0 ? 'short' : 'over'} by ${rupees(Math.abs(variance))}`
const when = (stamp: string) => `${longDate(stamp)}, ${clock(stamp)}`
const shortDay = (day: string) => new Date(day + 'T00:00:00').toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' })

/**
 * The manager's end-of-day screen. It shows the work each department left unfinished, the money by
 * department and payment mode, and the cash count, and it closes the day. A closed day's money
 * records cannot be changed until an admin reopens it.
 */
export function DayClose() {
  // Until a day is picked, the server says which day it is: the hospital's date, not this computer's.
  const [picked, setDay] = useState<string | null>(null)
  const [book, setBook] = useState<Book | null>(null)
  const [error, setError] = useState('')
  const [counted, setCounted] = useState('')
  const [varianceNote, setVarianceNote] = useState('')
  const [override, setOverride] = useState('')
  const [reopenReason, setReopenReason] = useState('')
  const [reopening, setReopening] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    api
      .get<Book>('/day-close', { params: picked ? { day: picked } : {} })
      .then((res) => {
        setBook(res.data)
        setError('')
      })
      .catch((e) => setError(fieldErrors(e)._ ?? fieldErrors(e).day ?? 'Could not load the day.'))
  }, [picked])

  useEffect(load, [load])

  const day = picked ?? book?.day ?? ''
  const hospitalToday = book?.today ?? today()

  /** Moves to another day with a clean form, so one day's count is never sent for another. */
  const pick = (next: string) => {
    if (next === day) return
    setDay(next === hospitalToday ? null : next)
    setBook(null)
    setErrors({})
    setCounted('')
    setVarianceNote('')
    setOverride('')
    setReopening(false)
    setReopenReason('')
  }

  const variance = book && counted !== '' ? Math.round((Number(counted) - book.expected_cash) * 100) / 100 : null

  const close = () => {
    if (!book || !window.confirm(`Close the accounts for ${longDate(book.day)}? After this, money for that day cannot be added or changed unless an admin reopens it.`)) return
    setBusy(true)
    api
      .post<Book>('/day-close', { day, counted_cash: counted === '' ? null : Number(counted), variance_note: varianceNote, override_reason: override })
      .then((res) => {
        setBook(res.data)
        setErrors({})
      })
      .catch((e) => setErrors(fieldErrors(e)))
      .finally(() => setBusy(false))
  }

  const reopen = () => {
    if (!book?.closed) return
    setBusy(true)
    api
      .post<Book>(`/day-close/${book.closed.id}/reopen`, { reason: reopenReason })
      .then((res) => {
        setBook(res.data)
        setErrors({})
        setReopening(false)
        setReopenReason('')
      })
      .catch((e) => setErrors(fieldErrors(e)))
      .finally(() => setBusy(false))
  }

  const print = () => {
    // A folded list would print as its heading only.
    document.querySelectorAll<HTMLDetailsElement>('.day-check').forEach((d) => (d.open = true))
    window.print()
  }

  const exportCsv = () => {
    if (!book) return
    const lines: (string | number)[][] = []
    Object.values(book.departments).forEach((d) => {
      lines.push(['Money', d.label, 'Billed', d.billed], ['Money', d.label, 'Collected', d.collected_total])
      book.modes.forEach((m) => lines.push(['Money', d.label, `Collected by ${modeLabel(m)}`, d.collected[m]]))
      lines.push(['Money', d.label, 'Refunded', d.refunded], ['Money', d.label, 'Due on the day’s bills', d.due])
    })
    lines.push(['Cash', 'Drawer', 'Cash expected', book.expected_cash])
    if (book.closed) {
      lines.push(['Cash', 'Drawer', 'Cash counted', book.closed.counted_cash], ['Cash', 'Drawer', 'Difference', book.closed.variance])
      lines.push(['Closing', book.closed.closed_by_name, 'Closed at', book.closed.closed_at])
    }
    book.sections.forEach((s) => {
      s.figures.forEach((f) => lines.push([s.label, 'Figures', f.label, f.value]))
      s.checks.forEach((c) => c.rows.forEach((r) => lines.push([s.label, `${c.label} (${c.blocking ? 'to settle' : 'to note'})`, r.text, r.detail ?? ''])))
    })
    downloadCsv(`day-close-${book.day}.csv`, ['Part', 'Heading', 'Item', 'Value'], lines)
  }

  const departments = book ? Object.values(book.departments) : []
  const total = (pick: (d: Department) => number) => departments.reduce((sum, d) => sum + pick(d), 0)

  return (
    <section className="card">
      <div className="toolbar">
        <h1>Day close</h1>
        <input type="date" className="date no-print" value={day} max={hospitalToday} onChange={(e) => pick(e.target.value || hospitalToday)} aria-label="Day" />
        <button className="btn ghost no-print" onClick={() => pick(hospitalToday)}>
          Today
        </button>
        <span className="print-only">{longDate(day)}</span>
        <span className="grow" />
        <button className="btn ghost no-print" disabled={!book} onClick={exportCsv}>
          Export
        </button>
        <button className="btn ghost no-print" disabled={!book} onClick={print}>
          Print
        </button>
      </div>

      {error && <p className="form-error">{error}</p>}
      {!book && !error && <p className="loading">Loading…</p>}

      {book && (
        <>
          <div className="day-strip no-print" aria-label="The last seven days">
            {[...book.recent].reverse().map((r) => (
              <button key={r.day} className={`day-chip ${r.closed ? 'closed' : 'open'} ${r.day === day ? 'current' : ''}`} onClick={() => pick(r.day)}>
                {shortDay(r.day)} · {r.closed ? 'closed' : 'not closed'}
              </button>
            ))}
          </div>

          {book.closed ? (
            <div className="notice day-state">
              <strong>Closed</strong> by {book.closed.closed_by_name} on {when(book.closed.closed_at)}. Cash expected {rupees(book.closed.expected_cash)}, counted{' '}
              {rupees(book.closed.counted_cash)}
              {book.closed.variance !== 0 && (
                <>
                  , {gap(book.closed.variance)} ({book.closed.variance_note})
                </>
              )}
              .{book.closed.exceptions > 0 && <> Closed with {book.closed.exceptions} left unfinished: {book.closed.override_reason}.</>} Money records of this day are locked.
              {book.can_reopen && !reopening && (
                <>
                  {' '}
                  <button className="link no-print" onClick={() => setReopening(true)}>
                    Reopen the day
                  </button>
                </>
              )}
            </div>
          ) : (
            <p className={book.blocking > 0 ? 'form-error day-state' : 'notice day-state'}>
              <strong>Not closed.</strong>{' '}
              {book.blocking > 0
                ? `${book.blocking} ${book.blocking === 1 ? 'item has' : 'items have'} to be settled, or explained, before closing.`
                : 'Nothing is holding up the closing.'}
              {book.notes > 0 && ` ${book.notes} more to note.`}
              {!book.may_close && ' You can look at the day here; closing it is for staff who have been given "Close the day" under Setup → Staff.'}
            </p>
          )}

          {reopening && book.closed && (
            <div className="day-form no-print">
              <label className="field">
                <span className="field-label">Why is the day being reopened?</span>
                <textarea rows={2} value={reopenReason} onChange={(e) => setReopenReason(e.target.value)} />
                {errors.reason && <span className="field-error">{errors.reason}</span>}
              </label>
              {errors._ && <p className="form-error">{errors._}</p>}
              <div className="actions">
                <button className="btn ghost" onClick={() => setReopening(false)}>
                  Cancel
                </button>
                <button className="btn danger-btn" disabled={busy} onClick={reopen}>
                  Reopen the day
                </button>
              </div>
            </div>
          )}

          {book.sections.map((section) => (
            <div className="day-section" key={section.key}>
              <h3>{section.label}</h3>
              <div className="tiles small">
                {section.figures.map((f) => (
                  <div className="tile" key={f.label}>
                    <span>{f.label}</span>
                    <strong>{f.money ? rupees(f.value) : f.value}</strong>
                  </div>
                ))}
              </div>
              {section.checks.map((check) =>
                check.count === 0 ? (
                  <p className="day-clear" key={check.key}>
                    <span aria-hidden="true">✓</span> {check.label}: none
                  </p>
                ) : (
                  <details className={`day-check ${check.blocking ? 'blocking' : 'note'}`} key={check.key} open={check.blocking}>
                    <summary>
                      <strong>{check.label}</strong>
                      <span className={`pill ${check.blocking ? 'off' : 'warn'}`}>
                        {check.count} {check.blocking ? 'to settle' : 'to note'}
                      </span>
                      <span className="muted small">{check.help}</span>
                    </summary>
                    <ul>
                      {check.rows.map((row, i) => (
                        <li key={i}>
                          {row.to ? <Link to={row.to}>{row.text}</Link> : <span>{row.text}</span>}
                          {row.detail && <span className="muted">{row.detail}</span>}
                        </li>
                      ))}
                      {check.count > check.rows.length && <li className="muted">and {check.count - check.rows.length} more</li>}
                    </ul>
                  </details>
                ),
              )}
            </div>
          ))}

          <div className="day-section">
            <h3>Money by department and payment mode</h3>
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
                    <th className="num">All</th>
                  </tr>
                </thead>
                <tbody>
                  {(
                    [
                      ['Billed', (d: Department) => d.billed, true],
                      ['Collected', (d: Department) => d.collected_total, true],
                      ...book.modes.map((m) => [modeLabel(m), (d: Department) => d.collected[m], false] as const),
                      ['Refunded (already taken off)', (d: Department) => d.refunded, true],
                      ['Due on the day’s bills', (d: Department) => d.due, true],
                    ] as [string, (d: Department) => number, boolean][]
                  ).map(([label, pick, strong]) => (
                    <tr key={label} className={strong ? 'static' : 'static sub'}>
                      <td>{strong ? <strong>{label}</strong> : label}</td>
                      {departments.map((d) => (
                        <td key={d.label} className="num">
                          {rupees(pick(d))}
                        </td>
                      ))}
                      <td className={strong ? 'num strong' : 'num'}>{rupees(total(pick))}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {book.can_close && (
            <div className="day-section no-print">
              <h3>Cashier close</h3>
              <div className="day-form">
                <div className="cash-close">
                  <div className="tile">
                    <span>Cash expected in the drawer</span>
                    <strong>{rupees(book.expected_cash)}</strong>
                  </div>
                  <label className="field">
                    <span className="field-label">Cash counted</span>
                    <input type="number" min="0" step="0.01" inputMode="decimal" value={counted} onChange={(e) => setCounted(e.target.value)} />
                    {errors.counted_cash && <span className="field-error">{errors.counted_cash}</span>}
                  </label>
                  <div className="tile">
                    <span>Difference</span>
                    <strong className={variance ? 'due' : variance === 0 ? 'settled' : ''}>{variance === null ? '—' : variance === 0 ? 'None' : gap(variance)}</strong>
                  </div>
                </div>
                {variance !== null && variance !== 0 && (
                  <label className="field">
                    <span className="field-label">Why does the cash not match? ({gap(variance)})</span>
                    <textarea rows={2} value={varianceNote} onChange={(e) => setVarianceNote(e.target.value)} />
                    {errors.variance_note && <span className="field-error">{errors.variance_note}</span>}
                  </label>
                )}
                {book.blocking > 0 && (
                  <label className="field">
                    <span className="field-label">
                      {book.blocking} {book.blocking === 1 ? 'item is' : 'items are'} still unfinished. Why is the day being closed with {book.blocking === 1 ? 'it' : 'them'} left open?
                    </span>
                    <textarea rows={2} value={override} onChange={(e) => setOverride(e.target.value)} />
                    {errors.override_reason && <span className="field-error">{errors.override_reason}</span>}
                  </label>
                )}
                {(errors._ || errors.day) && <p className="form-error">{errors._ ?? errors.day}</p>}
                <div className="actions">
                  <button className="btn primary" disabled={busy || counted === ''} onClick={close}>
                    Close the day
                  </button>
                </div>
              </div>
            </div>
          )}

          {book.history.some((h) => h.reopened_at) && (
            <div className="day-section">
              <h3>Earlier closings of this day</h3>
              <ul className="day-history">
                {book.history
                  .filter((h) => h.reopened_at)
                  .map((h) => (
                    <li key={h.id}>
                      Closed by {h.closed_by_name} on {when(h.closed_at)} (cash counted {rupees(h.counted_cash)}{h.variance !== 0 && `, ${gap(h.variance)}`}). Reopened by {h.reopened_by_name} on{' '}
                      {when(h.reopened_at!)}: {h.reopen_reason}
                    </li>
                  ))}
              </ul>
            </div>
          )}
        </>
      )}
    </section>
  )
}
