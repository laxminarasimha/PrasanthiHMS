import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { SelectField, TextField } from '../components/Field'
import { clock, longDate, parseCsv, today } from '../format'
import { useLoadOnChange } from '../loading'
import { useChoiceInAddress } from '../rangeInAddress'
import { saveFile } from './sensitiveStep'
import { DAY_CLASS, DAY_LABEL, DAY_LETTER, REQUEST_CLASS, REQUEST_LABEL, addMonths, hours, monthName, readAttendanceSheet, thisMonth, useWorkforce, type Correction, type Day, type Spell, type StaffBrief, type Totals } from './shared'
import { WorkforceTabs } from './WorkforceTabs'

type DayRow = StaffBrief & { shift: string | null; day: Day; records: Spell[]; open_earlier: Spell[] }
type DayList = { date: string; rows: DayRow[]; counts: { staff: number; in: number; not_in: number; on_leave: number; off: number; late: number; still_in: number } }
type MonthList = { month: string; rows: (StaffBrief & { days: Day[]; totals: Totals })[] }
type Sheet = { id: number; file_name: string | null; status: string; summary: Record<string, number>; rows: { id: number; employee_ref: string | null; staff_name: string | null; work_date: string | null; check_in: string | null; check_out: string | null; state: string; note: string | null }[] }
type View = 'day' | 'month' | 'corrections' | 'sheet'
type Editing = { person: StaffBrief; date: string; record: Spell | null }

const VIEWS = ['day', 'month', 'corrections', 'sheet'] as const
const SHEET_STATE: Record<string, [string, string]> = {
  ready: ['Ready', 'ok'], applied: ['Brought in', 'ok'], duplicate: ['Already here', 'status-booked'], unmatched: ['No such staff', 'off'], missing_in: ['No check-in', 'status-arrived'],
  missing_out: ['No check-out', 'status-arrived'], invalid: ['Cannot be read', 'off'], linked: ['Linked', 'status-booked'],
}

/** Attendance: the day, the month, corrections waiting for a decision, and sheets brought in from a machine. */
export function WorkforceAttendance() {
  const { can } = useWorkforce()
  const [view, setView] = useChoiceInAddress<View>('view', 'day', VIEWS)
  const [department, setDepartment] = useChoiceInAddress<string>('department', '')
  const [departments, setDepartments] = useState<{ id: number; name: string }[]>([])
  useEffect(() => {
    api.get<{ departments: { id: number; name: string }[] }>('/workforce/options').then((res) => setDepartments(res.data.departments)).catch(() => undefined)
  }, [])

  return (
    <>
      <WorkforceTabs />
      <section className="card">
        <div className="toolbar">
          <h1>Attendance</h1>
          <div className="tabs flush" role="group" aria-label="What to show">
            <button className={view === 'day' ? 'active' : ''} onClick={() => setView('day')}>
              Day
            </button>
            <button className={view === 'month' ? 'active' : ''} onClick={() => setView('month')}>
              Month
            </button>
            <button className={view === 'corrections' ? 'active' : ''} onClick={() => setView('corrections')}>
              Corrections
            </button>
            {can('wf:attendance:create') && (
              <button className={view === 'sheet' ? 'active' : ''} onClick={() => setView('sheet')}>
                Bring in a sheet
              </button>
            )}
          </div>
          {(view === 'day' || view === 'month') && departments.length > 0 && (
            <select className="narrow" value={department} onChange={(e) => setDepartment(e.target.value)} aria-label="Department">
              <option value="">All departments</option>
              {departments.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </select>
          )}
        </div>
        {view === 'day' && <DayView department={department} />}
        {view === 'month' && <MonthView department={department} />}
        {view === 'corrections' && <Corrections />}
        {view === 'sheet' && <SheetImport />}
      </section>
    </>
  )
}

function DayView({ department }: { department: string }) {
  const { can } = useWorkforce()
  const [date, setDate] = useChoiceInAddress<string>('date', today())
  const [query, setQuery] = useState('')
  const [list, setList] = useState<DayList | null>(null)
  const [failed, setFailed] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(0)
  const [editing, setEditing] = useState<Editing | null>(null)

  const load = useCallback(() => {
    api
      .get<DayList>('/workforce/attendance/day', { params: { date, department_id: department || undefined, q: query || undefined } })
      .then((res) => {
        setList(res.data)
        setFailed('')
      })
      .catch(() => setFailed('Could not load the day. Check the connection and try again.'))
  }, [date, department, query])
  useLoadOnChange(load)
  const shown = list?.date === date ? list : null

  const punch = async (row: DayRow, open: Spell | undefined) => {
    setBusy(row.id)
    setNotice('')
    try {
      if (open) await api.post(`/workforce/attendance/${open.id}/check-out`)
      else await api.post('/workforce/attendance/check-in', { employee_id: row.id })
      setNotice(`${row.name} is checked ${open ? 'out' : 'in'}.`)
      load()
    } catch (err) {
      setNotice(Object.values(fieldErrors(err))[0] ?? 'That did not save. Try again.')
    } finally {
      setBusy(0)
    }
  }

  return (
    <>
      <div className="toolbar minor">
        <input type="date" className="date" value={date} max={today()} onChange={(e) => setDate(e.target.value || today())} aria-label="Day" />
        {date !== today() && (
          <button className="btn ghost" onClick={() => setDate(today())}>
            Today
          </button>
        )}
        <input className="search" type="search" placeholder="Name or employee number" value={query} onChange={(e) => setQuery(e.target.value)} />
        {shown && (
          <span className="muted small">
            {shown.counts.in} in · {shown.counts.not_in} not in · {shown.counts.on_leave} on leave · {shown.counts.late} late
          </span>
        )}
      </div>
      {notice && <p className="notice">{notice}</p>}
      {failed && <div className="form-error">{failed}</div>}
      <div className={editing ? 'split' : ''}>
        <div className="table-wrap grow">
          <table className="wf-day">
            <thead>
              <tr>
                <th>Name</th>
                <th>The day</th>
                <th>In</th>
                <th>Out</th>
                <th className="num">Worked</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {shown?.rows.map((r) => {
                const open = r.records.find((s) => s.state === 'open')
                const first = r.records[0]
                const last = r.records[r.records.length - 1]
                return (
                  <tr key={r.id} className="static">
                    <td>
                      <strong>{r.name}</strong>
                      <small className="muted block">{[r.employee_no, r.department?.name, r.shift].filter(Boolean).join(' · ')}</small>
                      {r.open_earlier.map((s) => (
                        <small key={s.id} className="due block">
                          Never checked out on {longDate(s.work_date)}.{' '}
                          {(can('wf:attendance:edit') || can('wf:attendance:create')) && (
                            <button className="link" onClick={() => setEditing({ person: r, date: s.work_date, record: s })}>
                              Put it right
                            </button>
                          )}
                        </small>
                      ))}
                    </td>
                    <td>
                      <span className={`pill ${DAY_CLASS[r.day.status] ?? ''}`}>{DAY_LABEL[r.day.status] ?? r.day.status}</span>
                      {r.day.late_minutes > 0 && <small className="due block">{r.day.late_minutes} min late</small>}
                      {(r.day.leave_type || r.day.holiday || r.day.note) && <small className="muted block">{r.day.leave_type ?? r.day.holiday ?? r.day.note}</small>}
                    </td>
                    <td className="mono">{first?.check_in ? clock(first.check_in) : '—'}</td>
                    <td className="mono">{last?.check_out ? clock(last.check_out) : open ? <span className="muted">still in</span> : '—'}</td>
                    <td className="num">{hours(r.day.worked_minutes) || '—'}</td>
                    <td className="num">
                      <span className="row end wrap">
                        {can('wf:attendance:create') && date === today() && (open || r.records.length === 0) && r.day.status !== 'on_leave' && (
                          <button className={open ? 'btn small-btn' : 'btn primary small-btn'} disabled={busy === r.id} onClick={() => void punch(r, open)}>
                            {busy === r.id ? 'Saving…' : open ? 'Check out' : 'Check in'}
                          </button>
                        )}
                        {(can('wf:attendance:create') || can('wf:attendance:edit')) && (
                          <button className="link" onClick={() => setEditing({ person: r, date, record: last ?? null })}>
                            {last ? 'Correct' : 'Enter times'}
                          </button>
                        )}
                      </span>
                    </td>
                  </tr>
                )
              })}
              {shown && shown.rows.length === 0 && (
                <tr className="empty">
                  <td colSpan={6}>{query || department ? 'No member of staff matches.' : 'No staff to show. Add staff under the Staff tab first.'}</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        {editing && (
          <SpellForm
            key={`${editing.person.id}-${editing.record?.id ?? 'new'}-${editing.date}`}
            editing={editing}
            onClose={() => setEditing(null)}
            onSaved={(message) => {
              setEditing(null)
              setNotice(message)
              load()
            }}
          />
        )}
      </div>
    </>
  )
}

/** Enters a spell of work by hand, corrects a saved one, or asks for it to be corrected, as the person is allowed. */
function SpellForm({ editing, onClose, onSaved }: { editing: Editing; onClose: () => void; onSaved: (message: string) => void }) {
  const { can } = useWorkforce()
  const { person, date, record } = editing
  const local = (value: string | null | undefined) => (value ? value.slice(0, 16).replace(' ', 'T') : '')
  const [checkIn, setCheckIn] = useState(local(record?.check_in) || `${date}T09:00`)
  const [checkOut, setCheckOut] = useState(local(record?.check_out))
  const [dayStatus, setDayStatus] = useState(record?.day_status ?? '')
  const [reason, setReason] = useState('')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  // Someone who may not change attendance directly sends it to be approved instead.
  const asks = Boolean(record) && !can('wf:attendance:edit')
  const needsReason = Boolean(record) || date !== today()

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    const body = { employee_id: person.id, attendance_id: record?.id, work_date: date, check_in: checkIn.replace('T', ' '), check_out: checkOut ? checkOut.replace('T', ' ') : null, day_status: dayStatus || null, reason: reason || null }
    try {
      if (!record) await api.post('/workforce/attendance', body)
      else if (asks) await api.post('/workforce/attendance/corrections', body)
      else await api.put(`/workforce/attendance/${record.id}`, body)
      onSaved(asks ? `The correction for ${person.name} was sent for approval. Nothing changes until it is approved.` : `${person.name}'s attendance for ${longDate(date)} was saved.`)
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }
  const other = Object.entries(errors).filter(([key]) => !['check_in', 'check_out', 'reason'].includes(key))

  return (
    <form className="card panel" onSubmit={save} noValidate>
      <div className="panel-head">
        <div>
          <h2>{record ? (asks ? 'Ask for a correction' : 'Correct attendance') : 'Enter attendance'}</h2>
          <span className="muted">
            {person.name} · {longDate(date)}
          </span>
        </div>
        <button type="button" className="btn ghost" onClick={onClose}>
          Close
        </button>
      </div>
      <TextField label="Checked in" required type="datetime-local" value={checkIn} onChange={setCheckIn} error={errors.check_in} max={`${today()}T23:59`} />
      <TextField label="Checked out" type="datetime-local" value={checkOut} onChange={setCheckOut} error={errors.check_out} max={`${today()}T23:59`} />
      <p className="muted small">For a night shift the check-out is on the next morning; the day stays the one the shift started.</p>
      <SelectField
        label="Count the day as"
        value={dayStatus}
        onChange={setDayStatus}
        options={[{ value: 'present', label: 'A full day' }, { value: 'half_day', label: 'Half a day' }, { value: 'absent', label: 'Absent' }]}
        placeholder="Work it out from the hours"
      />
      <TextField label={needsReason ? 'Reason' : 'Note (optional)'} required={needsReason} value={reason} onChange={setReason} error={errors.reason} placeholder={record ? 'What was wrong, and how you know' : ''} />
      {other.length > 0 && (
        <div className="form-error">
          {other.map(([key, message]) => (
            <div key={key}>{message}</div>
          ))}
        </div>
      )}
      <div className="panel-foot">
        <button className="btn primary" disabled={saving || (needsReason && !reason.trim())}>
          {saving ? 'Saving…' : asks ? 'Send for approval' : 'Save'}
        </button>
      </div>
    </form>
  )
}

function MonthView({ department }: { department: string }) {
  const { can } = useWorkforce()
  const [month, setMonth] = useChoiceInAddress<string>('month', thisMonth())
  const [list, setList] = useState<MonthList | null>(null)
  const [failed, setFailed] = useState('')

  useEffect(() => {
    let current = true
    api
      .get<MonthList>('/workforce/attendance/month', { params: { month, department_id: department || undefined } })
      .then((res) => {
        if (!current) return
        setList(res.data)
        setFailed('')
      })
      .catch(() => current && setFailed('Could not load the month. Check the connection and try again.'))
    return () => {
      current = false
    }
  }, [month, department])
  const shown = list?.month === month ? list : null
  const dates = shown?.rows[0]?.days.map((d) => d.date) ?? []

  return (
    <>
      <div className="toolbar minor">
        <button className="btn ghost" onClick={() => setMonth(addMonths(month, -1))} aria-label="The month before">
          ‹
        </button>
        <strong>{monthName(month)}</strong>
        <button className="btn ghost" onClick={() => setMonth(addMonths(month, 1))} disabled={month >= thisMonth()} aria-label="The month after">
          ›
        </button>
        <span className="grow" />
        {can('wf:attendance:export') && (
          <button className="btn ghost" onClick={() => void saveFile('/workforce/attendance/export', { month, department_id: department || undefined }, `attendance-${month}.csv`)}>
            Export
          </button>
        )}
      </div>
      {failed && <div className="form-error">{failed}</div>}
      {!shown && !failed && <p className="loading">Loading the month…</p>}
      {shown && shown.rows.length === 0 && <p className="muted">No staff to show for this month.</p>}
      {shown && shown.rows.length > 0 && (
        <>
          <div className="table-wrap wf-month-wrap">
            <table className="wf-month">
              <thead>
                <tr>
                  <th className="wf-name">Name</th>
                  {dates.map((d) => (
                    <th key={d} title={longDate(d)}>
                      {Number(d.slice(8))}
                      <small>{new Date(`${d}T00:00:00`).toLocaleDateString('en-IN', { weekday: 'narrow' })}</small>
                    </th>
                  ))}
                  <th className="num" title="Days present">P</th>
                  <th className="num" title="Days of leave">L</th>
                  <th className="num" title="Days absent">A</th>
                  <th className="num" title="Days late">Late</th>
                  <th className="num">Hours</th>
                </tr>
              </thead>
              <tbody>
                {shown.rows.map((r) => (
                  <tr key={r.id} className="static">
                    <td className="wf-name">
                      {r.name}
                      <small className="muted block">{r.employee_no}</small>
                    </td>
                    {r.days.map((d) => (
                      <td key={d.date} className={`wf-box ${d.status}`} title={`${longDate(d.date)}: ${DAY_LABEL[d.status] ?? ''}${d.late_minutes ? `, ${d.late_minutes} min late` : ''}${d.holiday ? `, ${d.holiday}` : ''}${d.leave_type ? `, ${d.leave_type}` : ''}${d.note ? `, ${d.note}` : ''}`}>
                        {DAY_LETTER[d.status] ?? ''}
                        {d.late_minutes > 0 && <i aria-hidden="true" />}
                      </td>
                    ))}
                    <td className="num">{r.totals.present}</td>
                    <td className="num">{r.totals.leave}</td>
                    <td className={`num ${r.totals.absent > 0 ? 'due' : ''}`}>{r.totals.absent}</td>
                    <td className="num">{r.totals.late_days}</td>
                    <td className="num">{Math.round(r.totals.worked_minutes / 60)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="muted small wf-key">
            P present · ½ half day · L leave · ½L half day leave · A absent · O weekly off · H holiday · In checked in, not out. A dot marks a day they came late.
          </p>
        </>
      )}
    </>
  )
}

function Corrections() {
  const [status, setStatus] = useChoiceInAddress<string>('show', 'pending')
  const [rows, setRows] = useState<{ status: string; list: Correction[] } | null>(null)
  const [failed, setFailed] = useState('')
  const [refusing, setRefusing] = useState<number | null>(null)
  const [note, setNote] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(0)

  const load = useCallback(() => {
    api
      .get<Correction[]>('/workforce/attendance/corrections', { params: { status } })
      .then((res) => {
        setRows({ status, list: res.data })
        setFailed('')
      })
      .catch(() => setFailed('Could not load the corrections. Check the connection and try again.'))
  }, [status])
  useEffect(load, [load])
  const shown = rows?.status === status ? rows.list : null

  const decide = async (c: Correction, approve: boolean) => {
    setBusy(c.id)
    setNotice('')
    try {
      await api.post(`/workforce/attendance/corrections/${c.id}/decide`, { approve, note: approve ? null : note })
      setNotice(approve ? `Approved. ${c.employee?.name ?? 'Their'} attendance for ${longDate(c.work_date)} is changed.` : 'Refused. Nothing was changed.')
      setRefusing(null)
      setNote('')
      load()
    } catch (err) {
      setNotice(Object.values(fieldErrors(err))[0] ?? 'That did not save. Try again.')
    } finally {
      setBusy(0)
    }
  }
  const times = (v: Record<string, string | null> | null) => (v ? `${v.check_in ? clock(v.check_in) : '—'} to ${v.check_out ? clock(v.check_out) : '—'}${v.day_status ? ` · counted as ${v.day_status.replace('_', ' ')}` : ''}` : 'No entry')

  return (
    <>
      <div className="toolbar minor">
        <div className="tabs flush" role="group" aria-label="Which corrections">
          {([['pending', 'Waiting'], ['all', 'All']] as const).map(([value, label]) => (
            <button key={value} className={status === value ? 'active' : ''} onClick={() => setStatus(value)}>
              {label}
            </button>
          ))}
        </div>
        <span className="muted small">A correction someone asked for changes nothing until it is approved. Every correction keeps what the entry was and what it became.</span>
      </div>
      {notice && <p className="notice">{notice}</p>}
      {failed && <div className="form-error">{failed}</div>}
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Who and when</th>
              <th>It was</th>
              <th>It should be</th>
              <th>Reason</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {shown?.map((c) => (
              <tr key={c.id} className="static">
                <td>
                  <strong>{c.employee?.name ?? '—'}</strong>
                  <small className="muted block">
                    {longDate(c.work_date)} · asked by {c.asked_by ?? '—'}
                  </small>
                </td>
                <td className="mono">{times(c.before)}</td>
                <td className="mono">{times(c.after)}</td>
                <td className="wrap">
                  {c.reason}
                  {c.decision_note && <small className="muted block">Decision: {c.decision_note}</small>}
                </td>
                <td className="num">
                  {c.status === 'pending' && c.can_decide ? (
                    refusing === c.id ? (
                      <span className="row end wrap">
                        <input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Why it is refused" aria-label="Why it is refused" />
                        <button className="btn small-btn" disabled={busy === c.id || !note.trim()} onClick={() => void decide(c, false)}>
                          Refuse
                        </button>
                      </span>
                    ) : (
                      <span className="row end wrap">
                        <button className="btn primary small-btn" disabled={busy === c.id} onClick={() => void decide(c, true)}>
                          Approve
                        </button>
                        <button className="link" onClick={() => setRefusing(c.id)}>
                          Refuse
                        </button>
                      </span>
                    )
                  ) : (
                    <span className={`pill ${REQUEST_CLASS[c.status]}`}>{REQUEST_LABEL[c.status]}</span>
                  )}
                </td>
              </tr>
            ))}
            {shown && shown.length === 0 && (
              <tr className="empty">
                <td colSpan={5}>{status === 'pending' ? 'No correction is waiting for a decision.' : 'No corrections yet.'}</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </>
  )
}

/** A sheet from an attendance machine or a spreadsheet: read here, looked over on the server, then brought in. */
function SheetImport() {
  const [sheet, setSheet] = useState<Sheet | null>(null)
  const [problem, setProblem] = useState('')
  const [busy, setBusy] = useState(false)

  const read = async (file: File | undefined) => {
    if (!file) return
    setProblem('')
    setSheet(null)
    const found = readAttendanceSheet(parseCsv(await file.text()))
    if ('problem' in found) return setProblem(found.problem)
    setBusy(true)
    try {
      setSheet((await api.post<Sheet>('/workforce/attendance/imports', { file_name: file.name, rows: found.rows })).data)
    } catch (err) {
      setProblem(Object.values(fieldErrors(err))[0] ?? 'The sheet could not be looked over. Try again.')
    } finally {
      setBusy(false)
    }
  }
  const apply = async () => {
    if (!sheet) return
    setBusy(true)
    try {
      setSheet((await api.post<Sheet>(`/workforce/attendance/imports/${sheet.id}/apply`)).data)
    } catch (err) {
      setProblem(Object.values(fieldErrors(err))[0] ?? 'The sheet could not be brought in. Try again.')
    } finally {
      setBusy(false)
    }
  }
  const n = (key: string) => sheet?.summary[key] ?? 0

  return (
    <>
      <p className="muted">
        Choose a CSV file with a heading row and these columns, in any order: <strong>Employee no</strong>, <strong>Date</strong>, <strong>Check in</strong>, <strong>Check out</strong>. Nothing is saved until you have looked it over and
        pressed “Bring in”. A line brought in once is recognised if it comes again, so the same sheet can never be counted twice.
      </p>
      <label className="btn ghost">
        {busy ? 'Looking it over…' : 'Choose a sheet'}
        <input
          type="file"
          hidden
          accept=".csv,text/csv,text/plain"
          disabled={busy}
          onChange={(e) => {
            void read(e.target.files?.[0])
            e.target.value = ''
          }}
        />
      </label>
      {problem && <div className="form-error gap-top">{problem}</div>}
      {sheet && (
        <>
          <div className="tiles small gap-top">
            <div className="tile">
              <span>{sheet.status === 'applied' ? 'Brought in' : 'Ready to bring in'}</span>
              <strong className="settled">{sheet.status === 'applied' ? n('applied') : n('ready')}</strong>
              <small className="muted">{sheet.summary.hours ?? 0} hours</small>
            </div>
            <div className="tile">
              <span>Already here</span>
              <strong>{n('already_here') + n('duplicate')}</strong>
            </div>
            <div className="tile">
              <span>Need a person to look</span>
              <strong className={n('unmatched') + n('invalid') + n('missing_in') + n('missing_out') > 0 ? 'due' : ''}>{n('unmatched') + n('invalid') + n('missing_in') + n('missing_out')}</strong>
              <small className="muted">left out, listed below</small>
            </div>
          </div>
          {sheet.status === 'preview' ? (
            <div className="row gap-top">
              <button className="btn primary" disabled={busy || n('ready') === 0} onClick={() => void apply()}>
                {busy ? 'Bringing in…' : `Bring in the ${n('ready')} ready ${n('ready') === 1 ? 'line' : 'lines'}`}
              </button>
              {n('ready') === 0 && <span className="muted">There is nothing new in this sheet to bring in.</span>}
            </div>
          ) : (
            <p className="notice gap-top">Brought in. The lines that were left out can be entered by hand under Day, with a reason.</p>
          )}
          <div className="table-wrap gap-top">
            <table className="compact">
              <thead>
                <tr>
                  <th>Employee</th>
                  <th>Date</th>
                  <th>In</th>
                  <th>Out</th>
                  <th>What was made of it</th>
                </tr>
              </thead>
              <tbody>
                {sheet.rows.map((r) => (
                  <tr key={r.id} className="static">
                    <td>
                      {r.staff_name ?? '—'}
                      <small className="muted block">{r.employee_ref ?? 'no number'}</small>
                    </td>
                    <td>{r.work_date ? longDate(r.work_date) : '—'}</td>
                    <td className="mono">{r.check_in ? clock(r.check_in) : '—'}</td>
                    <td className="mono">{r.check_out ? `${clock(r.check_out)}${r.work_date && r.check_out.slice(0, 10) !== r.work_date ? ' next day' : ''}` : '—'}</td>
                    <td>
                      <span className={`pill ${SHEET_STATE[r.state]?.[1] ?? ''}`}>{SHEET_STATE[r.state]?.[0] ?? r.state}</span>
                      {r.note && <small className="muted block">{r.note}</small>}
                    </td>
                  </tr>
                ))}
                {sheet.rows.length === 0 && (
                  <tr className="empty">
                    <td colSpan={5}>Every line in this sheet has been brought in before.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </>
      )}
    </>
  )
}
