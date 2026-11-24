import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api, fieldErrors, type FieldErrors } from '../api'
import { useAuth } from '../auth'
import { SelectField, TextField } from '../components/Field'
import { longDate } from '../format'
import { useLoadOnChange } from '../loading'
import { useChoiceInAddress } from '../rangeInAddress'
import { LoginAccess, PinSlip } from './LoginAccess'
import { PasswordAgain } from './PasswordAgain'
import { PersonCard } from './PersonCard'
import { saveFile, usePasswordAgain } from './sensitiveStep'
import { STATUS_LABEL, TYPE_LABEL, WEEKDAYS, useWorkforce, type Employee } from './shared'
import { StaffCheckNote } from './StaffCheckNote'
import { WorkforceTabs } from './WorkforceTabs'

type Options = {
  departments: { id: number; name: string }[]
  shifts: { id: number; name: string; starts_at: string; ends_at: string }[]
  calendars: { id: number; name: string; is_default: boolean }[]
  managers: { id: number; name: string; employee_no: string }[]
  logins: { id: number; name: string; role: string }[]
  types: string[]
  statuses: string[]
  next_employee_no: string
}
type Private = { bank_name: string | null; tax_regime: string | null; bank_account_no: string | null; bank_ifsc: string | null; upi_id: string | null; pan: string | null; aadhaar: string | null; uan: string | null; esi_no: string | null; revealed: boolean; can_edit: boolean; unreadable?: string[] }

const blank = {
  employee_no: '', name: '', status: 'active', phone: '', email: '', gender: '', emergency_contact_name: '', emergency_contact_phone: '', department_id: '', designation: '', manager_id: '',
  work_location: '', joined_on: '', confirmed_on: '', separated_on: '', separation_reason: '', employment_type: 'permanent', shift_id: '', holiday_calendar_id: '', payment_mode: '', user_id: '',
}
type Form = typeof blank
const PRIVATE_FIELDS: [keyof Private & string, string, string][] = [
  ['bank_account_no', 'Bank account number', ''], ['bank_ifsc', 'IFSC', 'ABCD0123456'], ['upi_id', 'UPI ID', ''], ['pan', 'PAN', 'ABCDE1234F'], ['aadhaar', 'Aadhaar', '12 digits'], ['uan', 'UAN (provident fund)', '12 digits'], ['esi_no', 'ESI number', ''],
]

/** Everyone who works for the hospital. Someone who leaves is marked as left and kept; nobody is ever removed. */
export function StaffDirectory() {
  const { can } = useWorkforce()
  const [staff, setStaff] = useState<Employee[] | null>(null)
  const [counts, setCounts] = useState<Record<string, number>>({})
  const [options, setOptions] = useState<Options | null>(null)
  const [query, setQuery] = useState('')
  const [status, setStatus] = useChoiceInAddress<string>('status', 'active')
  const [department, setDepartment] = useChoiceInAddress<string>('department', '')
  const [editing, setEditing] = useState<Employee | 'new' | null>(null)
  const [tab, setTab] = useState<'details' | 'login' | 'private'>('details')
  const { user } = useAuth()
  const manages = Boolean(user?.can_manage_staff)
  // Who may open a person's "Login and access": those who manage logins, and those who keep the staff records.
  const seesLogin = manages || can('wf:staff:edit')
  // Adding a person: whether they sign in, and as what. One form; nothing about them is typed twice.
  const [signsIn, setSignsIn] = useState(false)
  const [newRole, setNewRole] = useState('staff')
  const [newPassword, setNewPassword] = useState('')
  const [roles, setRoles] = useState<{ value: string; label: string }[]>([])
  useEffect(() => {
    if (manages) api.get<{ roles: { value: string; label: string }[] }>('/staff/catalogue').then((res) => setRoles(res.data.roles.filter((r) => r.value !== 'admin' || user?.role === 'admin')))
  }, [manages, user?.role])
  // A starting PIN just made for someone added here: shown once.
  const [slip, setSlip] = useState<{ name: string; mobile: string | null; pin: string } | null>(null)
  // A login that is not a member of staff (a screen on the wall, or one not yet joined), opened from the staff check.
  const [search, setSearch] = useSearchParams()
  const bareLogin = manages && search.get('login') ? Number(search.get('login')) : null
  const [form, setForm] = useState<Form>(blank)
  const [weeklyOff, setWeeklyOff] = useState<string[] | null>(null)
  const [reason, setReason] = useState('')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState('')
  const [failed, setFailed] = useState('')

  const load = useCallback(() => {
    api
      .get<{ data: Employee[]; counts: Record<string, number> }>('/workforce/employees', { params: { q: query || undefined, status, department_id: department || undefined } })
      .then((res) => {
        setStaff(res.data.data)
        setCounts(res.data.counts)
        setFailed('')
        // The person whose page is open is shown as they now are.
        setEditing((open) => (open && open !== 'new' ? (res.data.data.find((s) => s.id === open.id) ?? open) : open))
      })
      .catch(() => setFailed('Could not load the staff list. Check the connection and try again.'))
  }, [query, status, department])
  useLoadOnChange(load)
  const loadOptions = useCallback(() => api.get<Options>('/workforce/options').then((res) => setOptions(res.data)), [])
  useEffect(() => {
    void loadOptions()
  }, [loadOptions])

  const open = (target: Employee | 'new', at: 'details' | 'login' = 'details') => {
    setEditing(target)
    setTab(at)
    setSignsIn(false)
    setNewRole('staff')
    setNewPassword('')
    setErrors({})
    setNotice('')
    setReason('')
    setWeeklyOff(target === 'new' ? null : target.weekly_off)
    setRegular(null)
    setForm(
      target === 'new'
        ? blank
        : (Object.fromEntries(Object.keys(blank).map((key) => [key, String((target as unknown as Record<string, unknown>)[key] ?? '')])) as Form),
    )
  }
  const set = (key: keyof Form) => (value: string) => setForm((f) => ({ ...f, [key]: value }))
  // The person whose window is open: a click on the list shows them at a glance first; changing them is one step further.
  const [viewing, setViewing] = useState<number | null>(null)
  // In the daily attendance and on the payroll. Empty until it is ticked or unticked here: the usual rule then decides.
  const [regular, setRegular] = useState<boolean | null>(null)

  // "Open full record" from the window on a person's name arrives here with their number in the address.
  const wanted = search.get('person') ? Number(search.get('person')) : null
  useEffect(() => {
    if (!wanted || !staff) return
    const show = (person: Employee) => {
      open(person)
      setSearch({}, { replace: true })
    }
    const found = staff.find((s) => s.id === wanted)
    if (found) show(found)
    // Not on the list as it is filtered (someone who has left, say): fetched by itself.
    else void api.get<Employee>(`/workforce/employees/${wanted}`).then((res) => show(res.data)).catch(() => setSearch({}, { replace: true }))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [wanted, staff])

  /** The login of someone just added, from the same form. If it cannot be given, the person stays added and their page opens where it can be put right. */
  const giveLogin = async (person: Employee) => {
    try {
      const given = await api.post<{ starting_pin?: string }>(`/staff/for/${person.id}`, newRole === 'staff' ? { role: 'staff' } : { role: newRole, email: form.email || null, password: newPassword })
      if (given.data.starting_pin) setSlip({ name: person.name, mobile: person.phone, pin: given.data.starting_pin })
      else setNotice(`${person.name} was added as ${person.employee_no} and can sign in.`)
    } catch (err) {
      setEditing(person)
      setTab('login')
      setNotice(`${person.name} was added as ${person.employee_no}, but the login was not given: ${Object.values(fieldErrors(err))[0] ?? 'try again below.'}`)
    }
  }

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    const body = { ...Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v])), weekly_off: weeklyOff, reason: reason || undefined, ...(regular === null ? {} : { regular }) }
    try {
      const res = editing === 'new' ? await api.post<Employee>('/workforce/employees', body) : await api.put<Employee>(`/workforce/employees/${(editing as Employee).id}`, body)
      setNotice(editing === 'new' ? `${res.data.name} was added as ${res.data.employee_no}.` : `${res.data.name}'s details were saved.`)
      setEditing(null)
      if (editing === 'new' && signsIn) await giveLogin(res.data)
      load()
      void loadOptions()
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  const canEdit = editing === 'new' ? can('wf:staff:create') : can('wf:staff:edit')
  const pick = (list: { id: number; name: string }[] | undefined) => (list ?? []).map((o) => ({ value: String(o.id), label: o.name }))
  const errorList = Object.entries(errors).filter(([key]) => !(key in blank))

  return (
    <>
      <WorkforceTabs />
      <div className="split">
        <section className="card">
          <div className="toolbar">
            <h1>Staff</h1>
            <input className="search" type="search" placeholder="Name, employee number, phone or designation" value={query} onChange={(e) => setQuery(e.target.value)} />
            <select className="narrow" value={department} onChange={(e) => setDepartment(e.target.value)} aria-label="Department">
              <option value="">All departments</option>
              {options?.departments.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </select>
            {can('wf:staff:export') && (
              <button className="btn ghost" onClick={() => void saveFile('/workforce/employees/export', {}, 'staff.csv')}>
                Export
              </button>
            )}
            {can('wf:staff:create') && (
              <button className="btn primary" onClick={() => open('new')}>
                + Add staff
              </button>
            )}
          </div>
          <div className="tabs flush" role="group" aria-label="Which staff to show">
            {([['active', 'On the staff', (counts.active ?? 0) + (counts.on_notice ?? 0)], ['on_notice', 'On notice', counts.on_notice ?? 0], ['inactive', 'Left', counts.inactive ?? 0]] as const).map(([value, label, n]) => (
              <button key={value} className={status === value ? 'active' : ''} onClick={() => setStatus(value)}>
                {label} <span>{n}</span>
              </button>
            ))}
            <button className={status === 'all' ? 'active' : ''} onClick={() => setStatus('all')}>
              Everyone
            </button>
          </div>
          <StaffCheckNote />
          {slip && <PinSlip name={slip.name} mobile={slip.mobile} pin={slip.pin} onDone={() => setSlip(null)} />}
          {viewing && <PersonCard id={viewing} onClose={() => setViewing(null)} onChanged={load} />}
          {notice && <p className="notice">{notice}</p>}
          {failed && <div className="form-error">{failed}</div>}
          <div className="table-wrap">
            <table className="wf-staff">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Department</th>
                  <th>Phone</th>
                  <th>Signs in as</th>
                  <th>Joined</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {staff?.map((s) => (
                  <tr key={s.id} onClick={() => setViewing(s.id)} className={editing !== 'new' && editing?.id === s.id ? 'selected' : ''} title="Open this person">
                    <td className="fit-wrap">
                      <strong>{s.name}</strong>
                      <small className="muted block">
                        {s.employee_no}
                        {s.designation ? ` · ${s.designation}` : ''}
                      </small>
                    </td>
                    <td className="wrap">
                      {s.department?.name ?? '—'}
                      <small className="muted block">
                        {TYPE_LABEL[s.employment_type] ?? s.employment_type}
                        {s.regular === false && s.employment_type !== 'consultant' ? ' · not in daily attendance' : ''}
                      </small>
                    </td>
                    <td className="mono">{s.phone ?? '—'}</td>
                    <td className="wrap">
                      {s.login ? s.login.role_label : <span className="muted">Does not sign in</span>}
                      {s.login && !s.login.is_active && <small className="due block">Login switched off</small>}
                      {s.login?.is_active && s.login.waiting_for_own_pin && <small className="muted block">Has not signed in yet</small>}
                    </td>
                    <td>{s.joined_on ? longDate(s.joined_on) : '—'}</td>
                    <td>
                      <span className={`pill ${s.status === 'active' ? 'ok' : s.status === 'inactive' ? 'off' : 'status-arrived'}`}>{STATUS_LABEL[s.status] ?? s.status}</span>
                    </td>
                  </tr>
                ))}
                {staff && staff.length === 0 && (
                  <tr className="empty">
                    <td colSpan={6}>{query || department ? 'No member of staff matches.' : status === 'active' ? 'No staff have been added yet.' : 'Nobody here.'}</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </section>

        {bareLogin && !editing && (
          <aside className="card panel wide">
            <div className="panel-head">
              <h2>Login</h2>
              <button type="button" className="btn ghost" onClick={() => setSearch({}, { replace: true })}>
                Close
              </button>
            </div>
            <p className="muted small">This login is not joined to a member of staff. It is a screen on the wall, or it still waits on the staff check.</p>
            <LoginAccess key={bareLogin} loginId={bareLogin} onChanged={(message) => message && setNotice(message)} />
          </aside>
        )}

        {editing && (
          <aside className="card panel wide">
            <div className="panel-head">
              <h2>{editing === 'new' ? 'Add a member of staff' : editing.name}</h2>
              <button type="button" className="btn ghost" onClick={() => setEditing(null)}>
                Close
              </button>
            </div>
            {editing !== 'new' && (seesLogin || can('wf:staff_private:view')) && (
              <div className="tabs flush">
                <button type="button" className={tab === 'details' ? 'active' : ''} onClick={() => setTab('details')}>
                  Details
                </button>
                {seesLogin && (
                  <button type="button" className={tab === 'login' ? 'active' : ''} onClick={() => setTab('login')}>
                    Login and access
                  </button>
                )}
                {can('wf:staff_private:view') && (
                  <button type="button" className={tab === 'private' ? 'active' : ''} onClick={() => setTab('private')}>
                    Bank and identity
                  </button>
                )}
              </div>
            )}

            {tab === 'private' && editing !== 'new' ? (
              <PrivateDetails key={editing.id} employeeId={editing.id} />
            ) : tab === 'login' && editing !== 'new' ? (
              <LoginAccess
                key={`${editing.id}-${editing.login?.id ?? 'none'}`}
                person={editing}
                onChanged={(message) => {
                  if (message) setNotice(message)
                  load()
                }}
              />
            ) : (
              <form onSubmit={save} noValidate>
                <fieldset disabled={!canEdit}>
                  <div className="grid">
                    <div className="span-2">
                      <TextField label="Name" required value={form.name} onChange={set('name')} error={errors.name} autoFocus={editing === 'new'} />
                    </div>
                    <TextField label="Employee number" value={form.employee_no} onChange={set('employee_no')} error={errors.employee_no} placeholder={editing === 'new' ? `Leave empty for ${options?.next_employee_no ?? 'the next number'}` : ''} />
                    <SelectField label="Status" value={form.status} onChange={set('status')} options={Object.entries(STATUS_LABEL).map(([value, label]) => ({ value, label }))} />
                    <SelectField label="Department" value={form.department_id} onChange={set('department_id')} options={pick(options?.departments)} placeholder="Not set" error={errors.department_id} />
                    <TextField label="Designation" value={form.designation} onChange={set('designation')} error={errors.designation} />
                    <SelectField label="Employment" value={form.employment_type} onChange={set('employment_type')} options={Object.entries(TYPE_LABEL).map(([value, label]) => ({ value, label }))} />
                    <SelectField label="Reports to" value={form.manager_id} onChange={set('manager_id')} options={pick(options?.managers.filter((m) => editing === 'new' || m.id !== editing.id))} placeholder="Nobody set" error={errors.manager_id} />
                    <TextField label="Phone" value={form.phone} onChange={set('phone')} error={errors.phone} inputMode="tel" />
                    <TextField label="Email" type="email" value={form.email} onChange={set('email')} error={errors.email} />
                    <TextField label="Emergency contact" value={form.emergency_contact_name} onChange={set('emergency_contact_name')} error={errors.emergency_contact_name} />
                    <TextField label="Emergency phone" value={form.emergency_contact_phone} onChange={set('emergency_contact_phone')} error={errors.emergency_contact_phone} inputMode="tel" />
                    <TextField label="Joined on" type="date" value={form.joined_on} onChange={set('joined_on')} error={errors.joined_on} />
                    <TextField label="Confirmed on" type="date" value={form.confirmed_on} onChange={set('confirmed_on')} error={errors.confirmed_on} />
                    <SelectField label="Shift" value={form.shift_id} onChange={set('shift_id')} options={pick(options?.shifts)} placeholder="No fixed shift" error={errors.shift_id} />
                    <SelectField label="Holiday calendar" value={form.holiday_calendar_id} onChange={set('holiday_calendar_id')} options={pick(options?.calendars)} placeholder="The hospital's usual" />
                    <TextField label="Work location" value={form.work_location} onChange={set('work_location')} />
                    <SelectField label="Salary is paid by" value={form.payment_mode} onChange={set('payment_mode')} options={[{ value: 'bank', label: 'Bank transfer' }, { value: 'cash', label: 'Cash' }, { value: 'upi', label: 'UPI' }, { value: 'cheque', label: 'Cheque' }]} placeholder="Not set" />
                  </div>

                  <div className="field gap-top">
                    <span className="field-label">Weekly off</span>
                    <div className="row wrap">
                      {WEEKDAYS.map(([day, label]) => (
                        <label key={day} className="check flush">
                          <input type="checkbox" checked={(weeklyOff ?? []).includes(day)} onChange={(e) => setWeeklyOff((off) => (e.target.checked ? [...(off ?? []), day] : (off ?? []).filter((d) => d !== day)))} />
                          {label}
                        </label>
                      ))}
                      {weeklyOff === null && <small className="muted">Nothing ticked: the hospital's usual weekly off applies.</small>}
                    </div>
                  </div>

                  <label className="check top gap-top">
                    <input type="checkbox" checked={regular ?? (editing === 'new' ? form.employment_type !== 'consultant' : (editing.regular ?? true))} onChange={(e) => setRegular(e.target.checked)} />
                    <span>
                      In the daily attendance and on the payroll
                      <small className="muted block">Unticked for doctors and consultants: they are not marked absent, are not in a payroll, and are paid apart. They may still check in for the record.</small>
                    </span>
                  </label>

                  {editing === 'new' && seesLogin && (
                    <div className="signs-in">
                      <label className="check flush">
                        <input type="checkbox" checked={signsIn} onChange={(e) => setSignsIn(e.target.checked)} />
                        This person signs in to the system
                      </label>
                      {signsIn && (
                        <>
                          {manages && roles.length > 0 && (
                            <div className="grid gap-top">
                              <SelectField label="Role" required value={newRole} onChange={setNewRole} options={roles} />
                              {newRole !== 'staff' && <TextField label="Password" required type="password" placeholder="At least 8 letters and numbers" value={newPassword} onChange={setNewPassword} autoComplete="new-password" />}
                            </div>
                          )}
                          <p className="muted small">
                            {newRole === 'staff'
                              ? 'They will sign in with the mobile number above and a 6-digit PIN, and see only their own attendance, leave and payslips. The starting PIN is shown once when you add them.'
                              : 'They will sign in with the mobile number above, or the email if one is given, and this password. What the role allows can be changed for them afterwards under Login and access.'}
                          </p>
                        </>
                      )}
                    </div>
                  )}

                  {form.status === 'inactive' && (
                    <div className="grid gap-top">
                      <TextField label="Left on" type="date" value={form.separated_on} onChange={set('separated_on')} error={errors.separated_on} />
                      <TextField label="Reason for leaving" value={form.separation_reason} onChange={set('separation_reason')} />
                    </div>
                  )}
                  {editing !== 'new' && <TextField label="Reason for the change (kept with it)" value={reason} onChange={setReason} placeholder="Optional" />}
                </fieldset>
                {errorList.length > 0 && (
                  <div className="form-error">
                    {errorList.map(([key, message]) => (
                      <div key={key}>{message}</div>
                    ))}
                  </div>
                )}
                {canEdit && (
                  <div className="panel-foot">
                    <button className="btn primary" disabled={saving}>
                      {saving ? 'Saving…' : editing === 'new' ? 'Add to the staff' : 'Save changes'}
                    </button>
                  </div>
                )}
              </form>
            )}
          </aside>
        )}
      </div>
    </>
  )
}

/** Bank, tax and identity details: shown with most of each number hidden, in full only after the password again. */
function PrivateDetails({ employeeId }: { employeeId: number }) {
  const { run, ask } = usePasswordAgain()
  const [data, setData] = useState<Private | null>(null)
  const [form, setForm] = useState<Record<string, string>>({})
  const [reason, setReason] = useState('')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)

  const show = useCallback(
    (reveal: boolean) =>
      run(() => api.get<Private>(`/workforce/employees/${employeeId}/private`, { params: reveal ? { reveal: 1 } : undefined }))
        .then((res) => {
          setData(res.data)
          setForm(Object.fromEntries(['bank_name', 'tax_regime', ...PRIVATE_FIELDS.map(([key]) => key)].map((key) => [key, String((res.data as unknown as Record<string, unknown>)[key] ?? '')])))
        })
        .catch(() => undefined),
    [employeeId, run],
  )
  useEffect(() => {
    void show(false)
  }, [show])

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    setNotice('')
    try {
      await run(() => api.put(`/workforce/employees/${employeeId}/private`, { ...Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v])), reason }))
      setNotice('Saved. The change is on record with your name and the reason.')
      setReason('')
      await show(false)
    } catch (err) {
      if ((err as Error).message !== 'Cancelled') setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  if (!data) return <p className="loading">Loading…</p>
  return (
    <form onSubmit={save} noValidate>
      <p className="muted small">
        Every look at these details, and every change, is recorded with who and when. {data.revealed ? 'The full numbers are showing.' : 'Only the last characters are showing.'}{' '}
        {!data.revealed && (
          <button type="button" className="link" onClick={() => void show(true)}>
            Show the full numbers
          </button>
        )}
      </p>
      {(data.unreadable?.length ?? 0) > 0 && (
        <p className="form-error">
          These were saved before 20 Nov 2026 and can no longer be read: {data.unreadable!.map((f) => PRIVATE_FIELDS.find(([key]) => key === f)?.[1] ?? f).join(', ')}. Please type them again and save.
        </p>
      )}
      <fieldset disabled={!data.can_edit}>
        <div className="grid">
          <TextField label="Bank" value={form.bank_name ?? ''} onChange={(v) => setForm((f) => ({ ...f, bank_name: v }))} error={errors.bank_name} />
          <SelectField label="Tax regime" value={form.tax_regime ?? ''} onChange={(v) => setForm((f) => ({ ...f, tax_regime: v }))} options={[{ value: 'new', label: 'New' }, { value: 'old', label: 'Old' }]} placeholder="Not set" />
          {PRIVATE_FIELDS.map(([key, label, hint]) => (
            <TextField key={key} label={label} value={form[key] ?? ''} onChange={(v) => setForm((f) => ({ ...f, [key]: v }))} error={errors[key]} placeholder={hint} autoComplete="off" className="mono" />
          ))}
        </div>
        {data.can_edit && <TextField label="Reason for the change" required value={reason} onChange={setReason} error={errors.reason} placeholder="For example: joining papers, new bank account" />}
      </fieldset>
      {notice && <p className="notice">{notice}</p>}
      {data.can_edit && (
        <div className="panel-foot">
          <span className="muted small grow">A number left showing dots is not changed.</span>
          <button className="btn primary" disabled={saving || !reason.trim()}>
            {saving ? 'Saving…' : 'Save these details'}
          </button>
        </div>
      )}
      <PasswordAgain ask={ask} />
    </form>
  )
}
