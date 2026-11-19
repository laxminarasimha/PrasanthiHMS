import { useCallback, useState } from 'react'
import { api, fieldErrors } from '../api'
import { today } from '../format'
import { useLoadOnChange } from '../loading'
import { useChoiceInAddress } from '../rangeInAddress'
import { dutyLabel, shortTime, weekStart, type RosterData } from './dutyWords'

const addDays = (date: string, by: number) => {
  const d = new Date(`${date}T00:00:00`)
  d.setDate(d.getDate() + by)
  return d.toLocaleDateString('en-CA')
}
const dayHead = (date: string) => new Date(`${date}T00:00:00`).toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' })

/**
 * The duty roster, a week at a time: who is on morning duty, a long day or nights, and whose day off it is. A box
 * left on "Usual" follows the person's own shift and weekly off. A change is saved as soon as it is made.
 */
export function RosterView({ department }: { department: string }) {
  const [from, setFrom] = useChoiceInAddress<string>('week', weekStart(today()))
  const [data, setData] = useState<{ key: string; roster: RosterData } | null>(null)
  const [failed, setFailed] = useState('')
  const [notice, setNotice] = useState<{ text: string; bad?: boolean } | null>(null)
  const [busy, setBusy] = useState('')
  const key = `${from}|${department}`

  const load = useCallback(() => {
    api
      .get<RosterData>('/workforce/attendance/roster', { params: { from, days: 7, department_id: department || undefined } })
      .then((res) => {
        setData({ key, roster: res.data })
        setFailed('')
      })
      .catch(() => setFailed('Could not load the roster. Check the connection and try again.'))
  }, [from, department, key])
  useLoadOnChange(load)
  const r = data?.key === key ? data.roster : null

  const set = async (employeeId: number, date: string, duty: string) => {
    setBusy(`${employeeId}|${date}`)
    setNotice(null)
    try {
      await api.put('/workforce/attendance/roster', { lines: [{ employee_id: employeeId, from: date, duty }] })
      load()
    } catch (err) {
      setNotice({ text: Object.values(fieldErrors(err))[0] ?? 'That did not save. Try again.', bad: true })
    } finally {
      setBusy('')
    }
  }
  const copyLastWeek = async () => {
    if (!window.confirm('Copy last week’s duties onto this week? Any duties already set for this week are replaced.')) return
    setBusy('copy')
    setNotice(null)
    try {
      const res = await api.post<{ days_changed: number }>('/workforce/attendance/roster/copy', { from: addDays(from, -7), to: from, days: 7, department_id: department || undefined })
      setNotice({ text: res.data.days_changed ? `Copied. ${res.data.days_changed} ${res.data.days_changed === 1 ? 'day' : 'days'} changed.` : 'This week already matches last week.' })
      load()
    } catch (err) {
      setNotice({ text: Object.values(fieldErrors(err))[0] ?? 'That did not copy. Try again.', bad: true })
    } finally {
      setBusy('')
    }
  }

  const shiftName = (id: number | null) => r?.shifts.find((s) => s.id === id)
  return (
    <div className="wf-roster">
      <div className="toolbar minor">
        <button className="btn ghost no-print" onClick={() => setFrom(addDays(from, -7))} aria-label="The week before">
          ←
        </button>
        <strong>
          Week of {dayHead(from)} to {dayHead(addDays(from, 6))}
        </strong>
        <button className="btn ghost no-print" onClick={() => setFrom(addDays(from, 7))} aria-label="The week after">
          →
        </button>
        {from !== weekStart(today()) && (
          <button className="btn ghost no-print" onClick={() => setFrom(weekStart(today()))}>
            This week
          </button>
        )}
        <span className="grow" />
        {r?.can_edit && (
          <button className="btn no-print" disabled={busy !== ''} onClick={() => void copyLastWeek()}>
            Copy last week here
          </button>
        )}
        <button className="btn ghost no-print" onClick={() => window.print()}>
          Print
        </button>
      </div>
      {notice && <p className={notice.bad ? 'form-error' : 'notice'}>{notice.text}</p>}
      {failed && <div className="form-error">{failed}</div>}
      {r && r.shifts.length === 0 && <p className="notice">No shifts are set up yet. Add them under Workforce → Setup → Shifts (for example Morning 8:00–18:00, Long day 10:00–21:00, Night 20:00–08:00).</p>}
      <p className="muted small no-print">Each box is that person’s duty for the day. “Usual” follows their own shift and weekly off. A night duty runs into the next morning and counts for the day it starts.</p>

      <div className="table-wrap wf-roster-wrap">
        <table className="wf-month wf-roster-grid">
          <thead>
            <tr>
              <th className="wf-name">Staff</th>
              {(r?.dates ?? []).map((d) => (
                <th key={d} className={d === today() ? 'today' : ''}>
                  {dayHead(d)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {r?.rows.map((p) => (
              <tr key={p.id} className="static">
                <td className="wf-name">
                  {p.name}
                  <small>{[p.designation, p.department?.name].filter(Boolean).join(' · ')}</small>
                </td>
                {r.dates.map((d) => {
                  const duty = p.days[d]
                  if (!duty) return <td key={d} className="muted">—</td>
                  const shift = shiftName(duty.shift_id)
                  const value = duty.rostered ? (duty.off ? 'off' : String(duty.shift_id)) : 'usual'
                  const cls = duty.off ? 'off' : shift?.night ? 'night' : 'day'
                  return (
                    <td key={d} className={`wf-duty ${cls} ${duty.rostered ? 'rostered' : ''}`}>
                      {r.can_edit && p.can_edit ? (
                        <select value={value} disabled={busy === `${p.id}|${d}`} onChange={(e) => void set(p.id, d, e.target.value)} aria-label={`${p.name}, ${dayHead(d)}`} title={dutyLabel(duty, shift)}>
                          <option value="usual">Usual ({!duty.rostered && duty.off ? 'day off' : (shiftName(p.usual_shift_id)?.name ?? 'no shift set')})</option>
                          {r.shifts
                            .filter((s) => s.is_active || String(s.id) === value)
                            .map((s) => (
                              <option key={s.id} value={s.id}>
                                {s.name} {shortTime(s.starts_at)}–{shortTime(s.ends_at)}
                              </option>
                            ))}
                          <option value="off">Day off</option>
                        </select>
                      ) : null}
                      <span className="wf-duty-text">{dutyLabel(duty, shift)}</span>
                    </td>
                  )
                })}
              </tr>
            ))}
            {r && r.rows.length === 0 && (
              <tr className="empty">
                <td colSpan={8}>No staff here. Add them under Staff, or choose another department.</td>
              </tr>
            )}
          </tbody>
          {r && r.rows.length > 0 && r.shifts.length > 0 && (
            <tfoot>
              {r.shifts
                .filter((s) => s.is_active)
                .map((s) => (
                  <tr key={s.id} className="static">
                    <th className="wf-name">{s.name}</th>
                    {r.dates.map((d) => (
                      <td key={d} className={(r.cover[d]?.[String(s.id)] ?? 0) === 0 ? 'due' : ''}>
                        {r.cover[d]?.[String(s.id)] ?? 0}
                      </td>
                    ))}
                  </tr>
                ))}
            </tfoot>
          )}
        </table>
      </div>
      {r && r.rows.length > 0 && <p className="muted small">The lines at the foot count how many are on each duty that day. A nought is shown in red.</p>}
    </div>
  )
}
