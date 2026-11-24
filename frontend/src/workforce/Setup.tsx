import { Popup } from '../components/Popup'
import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { SelectField, TextField } from '../components/Field'
import { longDate } from '../format'
import { WEEKDAYS, hours } from './shared'
import { WorkforceTabs } from './WorkforceTabs'

type Shift = { id: number; name: string; starts_at: string; ends_at: string; grace_minutes: number; early_leave_minutes: number; break_minutes: number; half_day_minutes: number | null; full_day_minutes: number | null; overtime_after_minutes: number | null; is_active: boolean }
type Calendar = { id: number; name: string; is_default: boolean; holidays: { id: number; on: string; name: string; is_optional: boolean }[] }
type Kind = { id: number; code: string; name: string; is_paid: boolean; annual_allowance: number; accrual: string; carry_forward_max: number; carry_forward_expires_months: number | null; allow_half_day: boolean; needs_approval: boolean; is_active: boolean }
type Settings = { payroll: { month_days: string; round_net_to: string; deduct_absence: boolean; daily_paid_days_off: boolean; salary_needs_approval: boolean }; numbering: { employee_prefix: string; employee_digits: number }; attendance: { self_check_in: boolean; self_check_in_networks: string[]; default_weekly_off: string[] }; leave: { year_starts_month: number; count_days_off: boolean; allow_negative: boolean } }
type All = { shifts: Shift[]; calendars: Calendar[]; leave_types: Kind[]; settings: Settings; departments: { id: number; name: string; manager_ids: number[] }[]; logins: { id: number; name: string; role: string }[]; /** The internet connection this computer is on. */ your_address?: string | null }
type Editing = { what: 'shift' | 'calendar' | 'holiday' | 'leave'; id: number | null; calendarId?: number }

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const ACCRUAL: Record<string, string> = { yearly: 'All at the start of the year', monthly: 'A twelfth each month', none: 'Not earned (no allowance)' }

/** The lists and rules the module runs on. Nothing here is fixed in the program: each hospital sets its own. */
export function WorkforceSetup() {
  const [all, setAll] = useState<All | null>(null)
  const [failed, setFailed] = useState('')
  const [notice, setNotice] = useState('')
  const [editing, setEditing] = useState<Editing | null>(null)
  const [form, setForm] = useState<Record<string, string | boolean>>({})
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  const load = useCallback(
    () =>
      api
        .get<All>('/workforce/setup')
        .then((res) => {
          setAll(res.data)
          setFailed('')
        })
        .catch(() => setFailed('Could not load the setup. Check the connection and try again.')),
    [],
  )
  useEffect(() => {
    void load()
  }, [load])

  const open = (next: Editing, values: Record<string, string | boolean>) => {
    setEditing(next)
    setForm(values)
    setErrors({})
    setNotice('')
  }
  const text = (key: string) => String(form[key] ?? '')
  const set = (key: string) => (value: string) => setForm((f) => ({ ...f, [key]: value }))
  const tick = (key: string, label: string) => (
    <label className="check">
      <input type="checkbox" checked={Boolean(form[key])} onChange={(e) => setForm((f) => ({ ...f, [key]: e.target.checked }))} />
      {label}
    </label>
  )

  const save = async (e: FormEvent) => {
    e.preventDefault()
    if (!editing) return
    setSaving(true)
    setErrors({})
    const body = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]))
    const base = { shift: '/workforce/setup/shifts', calendar: '/workforce/setup/calendars', leave: '/workforce/setup/leave-types', holiday: `/workforce/setup/calendars/${editing.calendarId}/holidays` }[editing.what]
    try {
      await (editing.id ? api.put(`${base}/${editing.id}`, body) : api.post(base, body))
      setNotice('Saved.')
      setEditing(null)
      await load()
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }
  const saveSettings = async (change: Partial<{ [G in keyof Settings]: Partial<Settings[G]> }>) => {
    setNotice('')
    try {
      const res = await api.put<Settings>('/workforce/setup/settings', change)
      setAll((a) => a && { ...a, settings: res.data })
      setNotice('Saved.')
    } catch (err) {
      setNotice(Object.values(fieldErrors(err))[0] ?? 'That did not save. Try again.')
    }
  }
  const removeHoliday = async (calendarId: number, id: number) => {
    await api.delete(`/workforce/setup/calendars/${calendarId}/holidays/${id}`).catch(() => undefined)
    await load()
  }
  const setManagers = async (departmentId: number, userIds: number[]) => {
    await api.put(`/workforce/departments/${departmentId}/managers`, { user_ids: userIds }).catch(() => setNotice('That did not save. Try again.'))
    await load()
  }

  const other = Object.entries(errors).filter(([key]) => !(key in form))
  const time = (t: string) => t.slice(0, 5)
  const numbers = (keys: [string, string][]) => keys.map(([key, label]) => <TextField key={key} label={label} type="number" min={0} value={text(key)} onChange={set(key)} error={errors[key]} />)

  return (
    <>
      <WorkforceTabs />
      <div className="split">
        <section className="card">
          <div className="toolbar">
            <h1>Workforce setup</h1>
          </div>
          {notice && <p className="notice">{notice}</p>}
          {failed && <div className="form-error">{failed}</div>}
          {!all && !failed && <p className="loading">Loading the setup…</p>}
          {all && (
            <>
              <div className="day-section">
                <div className="toolbar minor">
                  <h3 className="grow">Shifts</h3>
                  <button className="btn ghost" onClick={() => open({ what: 'shift', id: null }, { name: '', starts_at: '09:00', ends_at: '17:00', grace_minutes: '10', early_leave_minutes: '0', break_minutes: '0', half_day_minutes: '', full_day_minutes: '', overtime_after_minutes: '', is_active: true })}>
                    + Add a shift
                  </button>
                </div>
                <div className="table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th>Shift</th>
                        <th>Hours</th>
                        <th>Late after</th>
                        <th>Half day / full day from</th>
                        <th>Overtime after</th>
                      </tr>
                    </thead>
                    <tbody>
                      {all.shifts.map((s) => (
                        <tr key={s.id} onClick={() => open({ what: 'shift', id: s.id }, { name: s.name, starts_at: time(s.starts_at), ends_at: time(s.ends_at), grace_minutes: String(s.grace_minutes), early_leave_minutes: String(s.early_leave_minutes), break_minutes: String(s.break_minutes), half_day_minutes: String(s.half_day_minutes ?? ''), full_day_minutes: String(s.full_day_minutes ?? ''), overtime_after_minutes: String(s.overtime_after_minutes ?? ''), is_active: s.is_active })}>
                          <td className="wrap">
                            <strong>{s.name}</strong>
                            {!s.is_active && <span className="pill off"> Not in use</span>}
                          </td>
                          <td>
                            {time(s.starts_at)} to {time(s.ends_at)}
                            {time(s.ends_at) <= time(s.starts_at) && <small className="muted block">ends the next morning</small>}
                            {s.break_minutes > 0 && <small className="muted block">{s.break_minutes} min break</small>}
                          </td>
                          <td>{s.grace_minutes} min</td>
                          <td>{s.half_day_minutes || s.full_day_minutes ? `${hours(s.half_day_minutes) || '—'} / ${hours(s.full_day_minutes) || '—'}` : <span className="muted">Any time in counts as a day</span>}</td>
                          <td>{hours(s.overtime_after_minutes) || <span className="muted">Not counted</span>}</td>
                        </tr>
                      ))}
                      {all.shifts.length === 0 && (
                        <tr className="empty">
                          <td colSpan={5}>No shifts yet. Without one, a day is counted from the check-in and check-out alone.</td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </div>

              <div className="day-section">
                <div className="toolbar minor">
                  <h3 className="grow">Holiday calendars</h3>
                  <button className="btn ghost" onClick={() => open({ what: 'calendar', id: null }, { name: '', is_default: all.calendars.length === 0 })}>
                    + Add a calendar
                  </button>
                </div>
                {all.calendars.length === 0 && <p className="muted">No holiday calendar yet. Add one and list the hospital’s holidays in it.</p>}
                {all.calendars.map((c) => (
                  <div key={c.id} className="wf-calendar">
                    <div className="row wrap">
                      <strong>{c.name}</strong>
                      {c.is_default && <span className="pill ok">Used unless a person has another</span>}
                      <button className="link" onClick={() => open({ what: 'calendar', id: c.id }, { name: c.name, is_default: c.is_default })}>
                        Rename
                      </button>
                      <button className="link" onClick={() => open({ what: 'holiday', id: null, calendarId: c.id }, { on: '', name: '', is_optional: false })}>
                        + Add a holiday
                      </button>
                    </div>
                    <ul className="wf-holidays">
                      {c.holidays.map((h) => (
                        <li key={h.id}>
                          <span>
                            {longDate(h.on)} · {h.name}
                            {h.is_optional && <small className="muted"> (optional: staff may work it)</small>}
                          </span>
                          <span className="row">
                            <button className="link" onClick={() => open({ what: 'holiday', id: h.id, calendarId: c.id }, { on: h.on.slice(0, 10), name: h.name, is_optional: h.is_optional })}>
                              Change
                            </button>
                            <button className="link danger" onClick={() => window.confirm(`Take ${h.name} off this calendar?`) && void removeHoliday(c.id, h.id)}>
                              Remove
                            </button>
                          </span>
                        </li>
                      ))}
                      {c.holidays.length === 0 && <li className="muted">No holidays listed yet.</li>}
                    </ul>
                  </div>
                ))}
              </div>

              <div className="day-section">
                <div className="toolbar minor">
                  <h3 className="grow">Kinds of leave</h3>
                  <button className="btn ghost" onClick={() => open({ what: 'leave', id: null }, { code: '', name: '', is_paid: true, annual_allowance: '', accrual: 'yearly', carry_forward_max: '0', carry_forward_expires_months: '', allow_half_day: true, needs_approval: true, is_active: true })}>
                    + Add a kind of leave
                  </button>
                </div>
                <div className="table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th>Leave</th>
                        <th className="num">Days a year</th>
                        <th>Earned</th>
                        <th>Carried to next year</th>
                      </tr>
                    </thead>
                    <tbody>
                      {all.leave_types.map((t) => (
                        <tr key={t.id} onClick={() => open({ what: 'leave', id: t.id }, { code: t.code, name: t.name, is_paid: t.is_paid, annual_allowance: String(t.annual_allowance), accrual: t.accrual, carry_forward_max: String(t.carry_forward_max), carry_forward_expires_months: String(t.carry_forward_expires_months ?? ''), allow_half_day: t.allow_half_day, needs_approval: t.needs_approval, is_active: t.is_active })}>
                          <td className="wrap">
                            <strong>{t.name}</strong> <small className="muted">{t.code}</small>
                            {!t.is_paid && <span className="pill status-booked"> Without pay</span>}
                            {!t.is_active && <span className="pill off"> Not in use</span>}
                          </td>
                          <td className="num">{t.annual_allowance || '—'}</td>
                          <td>{ACCRUAL[t.accrual] ?? t.accrual}</td>
                          <td className="wrap">{t.carry_forward_max > 0 ? `Up to ${t.carry_forward_max} days${t.carry_forward_expires_months ? `, to be used within ${t.carry_forward_expires_months} months` : ''}` : <span className="muted">Not carried: what is left lapses</span>}</td>
                        </tr>
                      ))}
                      {all.leave_types.length === 0 && (
                        <tr className="empty">
                          <td colSpan={4}>No kinds of leave yet. Add the ones the hospital gives, such as casual, sick and earned leave.</td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </div>

              <div className="day-section">
                <h3>The hospital’s choices</h3>
                <div className="grid three">
                  <TextField label="Employee numbers start with" value={all.settings.numbering.employee_prefix} onChange={(v) => setAll((a) => a && { ...a, settings: { ...a.settings, numbering: { ...a.settings.numbering, employee_prefix: v } } })} onBlur={(e) => void saveSettings({ numbering: { employee_prefix: e.target.value } })} />
                  <TextField label="Digits in the number" type="number" min={1} max={10} value={String(all.settings.numbering.employee_digits)} onChange={(v) => setAll((a) => a && { ...a, settings: { ...a.settings, numbering: { ...a.settings.numbering, employee_digits: Number(v) } } })} onBlur={(e) => e.target.value && void saveSettings({ numbering: { employee_digits: Number(e.target.value) } })} />
                  <SelectField label="The leave year starts in" value={String(all.settings.leave.year_starts_month)} onChange={(v) => void saveSettings({ leave: { year_starts_month: Number(v) } })} options={MONTHS.map((m, i) => ({ value: String(i + 1), label: m }))} />
                </div>
                <div className="field gap-top">
                  <span className="field-label">Usual weekly off, for anyone without their own</span>
                  <div className="row wrap">
                    {WEEKDAYS.map(([day, label]) => (
                      <label key={day} className="check flush">
                        <input type="checkbox" checked={all.settings.attendance.default_weekly_off.includes(day)} onChange={(e) => void saveSettings({ attendance: { default_weekly_off: e.target.checked ? [...all.settings.attendance.default_weekly_off, day] : all.settings.attendance.default_weekly_off.filter((d) => d !== day) } })} />
                        {label}
                      </label>
                    ))}
                  </div>
                </div>
                <label className="check top">
                  <input type="checkbox" checked={all.settings.leave.count_days_off} onChange={(e) => void saveSettings({ leave: { count_days_off: e.target.checked } })} />
                  <span>
                    Count weekly offs and holidays inside a stretch of leave as leave
                    <small className="muted block">Off: Friday to Monday with Sunday off is three days of leave. On: it is four.</small>
                  </span>
                </label>
                <label className="check top">
                  <input type="checkbox" checked={all.settings.leave.allow_negative} onChange={(e) => void saveSettings({ leave: { allow_negative: e.target.checked } })} />
                  <span>
                    Allow leave to be approved beyond what is left
                    <small className="muted block">The balance then goes below zero until more is earned.</small>
                  </span>
                </label>
                <label className="check top">
                  <input type="checkbox" checked={all.settings.attendance.self_check_in} onChange={(e) => void saveSettings({ attendance: { self_check_in: e.target.checked } })} />
                  <span>
                    Let staff mark their own attendance from their login
                    <small className="muted block">Off unless you are satisfied nobody can sign in for someone else. No location or fingerprint is taken; it records only the login and the time.</small>
                  </span>
                </label>
                {all.settings.attendance.self_check_in && (
                  <div className="check-in-place">
                    <strong>Where staff may check in from</strong>
                    {all.settings.attendance.self_check_in_networks.length === 0 ? (
                      <p className="muted small">Anywhere, including from home. To keep it to the hospital, add the hospital's own internet connection below while this computer is on it.</p>
                    ) : (
                      <>
                        <p className="muted small">Only while their phone is on one of these internet connections (the hospital Wi-Fi). On mobile data or at home, check-in is refused.</p>
                        <ul className="plain-list">
                          {all.settings.attendance.self_check_in_networks.map((address) => (
                            <li key={address}>
                              <span className="mono">{address}</span>
                              {address === all.your_address && <span className="muted"> · this computer is on it now</span>}{' '}
                              <button type="button" className="link danger" onClick={() => void saveSettings({ attendance: { self_check_in_networks: all.settings.attendance.self_check_in_networks.filter((a) => a !== address) } })}>
                                Remove
                              </button>
                            </li>
                          ))}
                        </ul>
                      </>
                    )}
                    {all.your_address && !all.settings.attendance.self_check_in_networks.includes(all.your_address) && (
                      <button type="button" className="btn small-btn" onClick={() => window.confirm(`Add the connection this computer is on now (${all.your_address}) as the hospital's own? Do this only while you are at the hospital, on its Wi-Fi or network.`) && void saveSettings({ attendance: { self_check_in_networks: [...all.settings.attendance.self_check_in_networks, all.your_address!] } })}>
                        Add the connection this computer is on now
                      </button>
                    )}
                    <p className="muted small">If the internet company changes the hospital's connection, staff will be refused until the new one is added here.</p>
                  </div>
                )}
              </div>

              <div className="day-section">
                <h3>Payroll</h3>
                <div className="grid three">
                  <SelectField label="A day’s pay, for someone paid by the month" value={all.settings.payroll.month_days} onChange={(v) => void saveSettings({ payroll: { month_days: v } })} options={[{ value: 'calendar', label: 'The salary ÷ the days in that month' }, { value: 'fixed_30', label: 'The salary ÷ 30' }, { value: 'fixed_26', label: 'The salary ÷ 26' }]} />
                  <SelectField label="Net pay is" value={all.settings.payroll.round_net_to} onChange={(v) => void saveSettings({ payroll: { round_net_to: v } })} options={[{ value: 'rupee', label: 'Rounded to the rupee' }, { value: 'paise', label: 'Left in paise' }]} />
                </div>
                <label className="check top">
                  <input type="checkbox" checked={all.settings.payroll.deduct_absence} onChange={(e) => void saveSettings({ payroll: { deduct_absence: e.target.checked } })} />
                  <span>
                    Take pay off for absence
                    <small className="muted block">On: a working day with no attendance and no leave takes a day’s pay off. Switch it off until attendance is being kept here every day, or everyone will look absent; leave without pay is taken off either way.</small>
                  </span>
                </label>
                <label className="check top">
                  <input type="checkbox" checked={all.settings.payroll.daily_paid_days_off} onChange={(e) => void saveSettings({ payroll: { daily_paid_days_off: e.target.checked } })} />
                  <span>
                    Pay weekly offs and holidays to staff paid by the day
                    <small className="muted block">Off: they are paid for the days worked and paid leave only.</small>
                  </span>
                </label>
                <label className="check top">
                  <input type="checkbox" checked={all.settings.payroll.salary_needs_approval} onChange={(e) => void saveSettings({ payroll: { salary_needs_approval: e.target.checked } })} />
                  <span>
                    A new salary waits for a second person’s approval
                    <small className="muted block">Payroll does not use a salary until someone other than the person who entered it has approved it. The admin’s own entries are approved at once.</small>
                  </span>
                </label>
              </div>

              <div className="day-section">
                <h3>Who looks after each department</h3>
                <p className="muted small">A Department Manager sees and decides attendance and leave only for the departments ticked for them here.</p>
                {all.departments.length === 0 && <p className="muted">No departments yet. Add them under Setup → Hospital → Departments.</p>}
                {all.departments.map((d) => (
                  <div key={d.id} className="field gap-top">
                    <span className="field-label">{d.name}</span>
                    <div className="row wrap">
                      {all.logins.map((l) => (
                        <label key={l.id} className="check flush">
                          <input type="checkbox" checked={d.manager_ids.includes(l.id)} onChange={(e) => void setManagers(d.id, e.target.checked ? [...d.manager_ids, l.id] : d.manager_ids.filter((id) => id !== l.id))} />
                          {l.name} <small className="muted">({l.role})</small>
                        </label>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            </>
          )}
        </section>

        {editing && (
          <Popup title={<>{{ shift: 'Shift', calendar: 'Holiday calendar', holiday: 'Holiday', leave: 'Kind of leave' }[editing.what]}</>} onClose={() => setEditing(null)} onSubmit={save}>
            {editing.what === 'shift' && (
              <>
                <TextField label="Name" required value={text('name')} onChange={set('name')} error={errors.name} autoFocus />
                <div className="grid">
                  <TextField label="Starts" required type="time" value={text('starts_at')} onChange={set('starts_at')} error={errors.starts_at} />
                  <TextField label="Ends" required type="time" value={text('ends_at')} onChange={set('ends_at')} error={errors.ends_at} />
                </div>
                {text('ends_at') !== '' && text('ends_at') <= text('starts_at') && <p className="muted small">This shift ends the next morning. The day it counts for is the day it started.</p>}
                <div className="grid">{numbers([['grace_minutes', 'Late only after (minutes)'], ['early_leave_minutes', 'Early leaving ignored up to (minutes)'], ['break_minutes', 'Unpaid break (minutes)'], ['half_day_minutes', 'Half a day from (minutes worked)'], ['full_day_minutes', 'A full day from (minutes worked)'], ['overtime_after_minutes', 'Overtime after (minutes worked)']])}</div>
                <p className="muted small">Leave the last three empty and any time in counts as a full day, with no overtime.</p>
                {tick('is_active', 'In use')}
              </>
            )}
            {editing.what === 'calendar' && (
              <>
                <TextField label="Name" required value={text('name')} onChange={set('name')} error={errors.name} autoFocus />
                {tick('is_default', 'Use this for everyone who has no other calendar set')}
              </>
            )}
            {editing.what === 'holiday' && (
              <>
                <TextField label="Date" required type="date" value={text('on')} onChange={set('on')} error={errors.on} />
                <TextField label="Name" required value={text('name')} onChange={set('name')} error={errors.name} />
                {tick('is_optional', 'Optional: not a day off unless leave is taken')}
              </>
            )}
            {editing.what === 'leave' && (
              <>
                <div className="grid">
                  <TextField label="Name" required value={text('name')} onChange={set('name')} error={errors.name} autoFocus />
                  <TextField label="Short name" required value={text('code')} onChange={set('code')} error={errors.code} placeholder="CL" />
                  <TextField label="Days a year" type="number" min={0} step="0.5" value={text('annual_allowance')} onChange={set('annual_allowance')} error={errors.annual_allowance} />
                  <SelectField label="Earned" value={text('accrual')} onChange={set('accrual')} options={Object.entries(ACCRUAL).map(([value, label]) => ({ value, label }))} />
                  <TextField label="Days carried to next year, at most" type="number" min={0} step="0.5" value={text('carry_forward_max')} onChange={set('carry_forward_max')} error={errors.carry_forward_max} />
                  <TextField label="Carried days lapse after (months)" type="number" min={1} max={12} value={text('carry_forward_expires_months')} onChange={set('carry_forward_expires_months')} placeholder="Never" />
                </div>
                {tick('is_paid', 'Paid leave')}
                {tick('allow_half_day', 'Can be taken as a half day')}
                {tick('needs_approval', 'Needs approval')}
                {tick('is_active', 'In use')}
              </>
            )}
            {other.length > 0 && (
              <div className="form-error">
                {other.map(([key, message]) => (
                  <div key={key}>{message}</div>
                ))}
              </div>
            )}
            <div className="panel-foot">
              <button className="btn primary" disabled={saving}>
                {saving ? 'Saving…' : 'Save'}
              </button>
            </div>
          </Popup>
        )}
      </div>
    </>
  )
}
