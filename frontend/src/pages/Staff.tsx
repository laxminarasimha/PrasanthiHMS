import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { SetupTabs } from '../components/SetupTabs'
import { api, fieldErrors, type FieldErrors } from '../api'
import { useAuth } from '../auth'
import { SelectField, TextField } from '../components/Field'
import { useSignature } from '../components/useSignature'
import type { Ability, Availability, RoleOption, Staff as StaffMember } from '../types'

const GENDERS = [
  { value: 'male', label: 'Male' },
  { value: 'female', label: 'Female' },
  { value: 'other', label: 'Other' },
]

const DAYS = [
  ['mon', 'Monday'], ['tue', 'Tuesday'], ['wed', 'Wednesday'], ['thu', 'Thursday'], ['fri', 'Friday'], ['sat', 'Saturday'], ['sun', 'Sunday'],
] as const
type Day = (typeof DAYS)[number][0]
/** Two sessions a day (morning and evening), each with a from and a to; blank means not set. */
type Hours = Record<Day, { from: string; to: string }[]>
const toHours = (saved: Availability | null | undefined): Hours =>
  Object.fromEntries(DAYS.map(([day]) => [day, [0, 1].map((i) => saved?.[day]?.[i] ?? { from: '', to: '' })])) as Hours
type Tab = 'details' | 'permissions' | 'availability'

const blank = { name: '', email: '', password: '', role: '', gender: '', phone: '', department: '', signature_text: '', is_active: true }
type Form = typeof blank

export function Staff() {
  const { user } = useAuth()
  const [staff, setStaff] = useState<StaffMember[]>([])
  const [roles, setRoles] = useState<RoleOption[]>([])
  const [query, setQuery] = useState('')
  const [editing, setEditing] = useState<StaffMember | 'new' | null>(null)
  const [form, setForm] = useState<Form>(blank)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState('')
  const [abilities, setAbilities] = useState<Ability[]>([])
  const [departments, setDepartments] = useState<string[]>([])
  useEffect(() => {
    api.get<{ name: string }[]>('/admin-lists/department').then((res) => setDepartments(res.data.map((d) => d.name)))
  }, [])
  const [tab, setTab] = useState<Tab>('details')
  const [perms, setPerms] = useState<Record<string, boolean>>({})
  const [hours, setHours] = useState<Hours>(toHours(null))
  const [signatureVersion, setSignatureVersion] = useState(0)
  const editingId = editing && editing !== 'new' ? editing.id : null
  const signature = useSignature(editing && editing !== 'new' && editing.has_signature ? editingId : null, signatureVersion)
  const roleDefaults = (role: string) => Object.fromEntries(abilities.map((a) => [a.key, a.roles.includes(role)]))

  const load = useCallback(() => {
    api.get<{ data: StaffMember[]; roles: RoleOption[]; abilities: Ability[] }>('/staff', { params: { q: query || undefined } }).then((res) => {
      setStaff(res.data.data)
      setRoles(res.data.roles)
      setAbilities(res.data.abilities)
    })
  }, [query])

  useEffect(() => {
    const timer = setTimeout(load, 250)
    return () => clearTimeout(timer)
  }, [load])

  const open = (target: StaffMember | 'new') => {
    setEditing(target)
    setErrors({})
    setNotice('')
    setTab('details')
    setPerms(target === 'new' ? {} : target.abilities)
    setHours(toHours(target === 'new' ? null : target.availability))
    setForm(
      target === 'new'
        ? blank
        : {
            name: target.name, email: target.email, password: '', role: target.role, gender: target.gender ?? '',
            phone: target.phone ?? '', department: target.department ?? '', signature_text: target.signature_text ?? '',
            is_active: target.is_active,
          },
    )
  }

  const set = (key: keyof Form) => (value: string) => {
    setForm((f) => ({ ...f, [key]: value }))
    // A new role starts from that role's usual permissions.
    if (key === 'role') setPerms(roleDefaults(value))
  }
  const setHour = (day: Day, index: number, key: 'from' | 'to', value: string) =>
    setHours((h) => ({ ...h, [day]: h[day].map((range, i) => (i === index ? { ...range, [key]: value } : range)) }))

  const saveSignature = async (file?: File) => {
    if (!editing || editing === 'new') return
    setErrors({})
    try {
      let res
      if (file) {
        const body = new FormData()
        body.append('file', file)
        res = await api.post<StaffMember>(`/staff/${editing.id}/signature`, body)
      } else {
        res = await api.delete<StaffMember>(`/staff/${editing.id}/signature`)
      }
      setEditing(res.data)
      setSignatureVersion((v) => v + 1)
      load()
    } catch (err) {
      setErrors({ _: Object.values(fieldErrors(err))[0] })
    }
  }
  const roleLabel = (value: string) => roles.find((r) => r.value === value)?.label ?? value
  const isSelf = editing !== 'new' && editing?.id === user?.id

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    const body = {
      ...form,
      permissions: form.role ? perms : undefined,
      availability: form.role === 'doctor' ? Object.fromEntries(DAYS.map(([day]) => [day, hours[day].filter((r) => r.from && r.to)])) : null,
    }
    try {
      const res =
        editing === 'new'
          ? await api.post<StaffMember>('/staff', body)
          : await api.put<StaffMember>(`/staff/${(editing as StaffMember).id}`, body)
      setNotice(editing === 'new' ? `${res.data.name} was added.` : `${res.data.name}'s details were updated.`)
      setEditing(null)
      load()
    } catch (err) {
      const found = fieldErrors(err)
      setErrors(found)
      // Show the tab that holds the problem.
      const first = Object.keys(found)[0] ?? ''
      setTab(first.startsWith('availability') ? 'availability' : first.startsWith('permissions') ? 'permissions' : 'details')
    } finally {
      setSaving(false)
    }
  }
  const availabilityError = Object.entries(errors).find(([key]) => key.startsWith('availability'))?.[1]

  return (
    <>
    <SetupTabs />
    <div className="split">
      <section className="card">
        <div className="toolbar">
          <h1>Staff</h1>
          <input className="search" type="search" placeholder="Search by name, email or phone" value={query} onChange={(e) => setQuery(e.target.value)} />
          <button className="btn primary" onClick={() => open('new')}>
            + New staff
          </button>
        </div>

        {notice && <p className="notice">{notice}</p>}

        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Role</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {staff.map((s) => (
                <tr key={s.id} onClick={() => open(s)} className={editing !== 'new' && editing?.id === s.id ? 'selected' : ''}>
                  <td>
                    <strong>{s.name}</strong>
                  </td>
                  <td>{roleLabel(s.role)}</td>
                  <td>{s.email}</td>
                  <td className="mono">{s.phone ?? '—'}</td>
                  <td>
                    <span className={s.is_active ? 'pill ok' : 'pill off'}>{s.is_active ? 'Active' : 'Deactivated'}</span>
                  </td>
                </tr>
              ))}
              {staff.length === 0 && (
                <tr className="empty">
                  <td colSpan={5}>No staff match this search.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>

      {editing && (
        <form className="card panel" onSubmit={save} noValidate>
          <div className="panel-head">
            <h2>{editing === 'new' ? 'Add staff' : `Edit ${editing.name}`}</h2>
            <button type="button" className="btn ghost" onClick={() => setEditing(null)}>
              Close
            </button>
          </div>

          <div className="tabs flush gap-bottom">
            <button type="button" className={tab === 'details' ? 'active' : ''} onClick={() => setTab('details')}>
              Details
            </button>
            <button type="button" className={tab === 'permissions' ? 'active' : ''} onClick={() => setTab('permissions')}>
              Permissions
            </button>
            {form.role === 'doctor' && (
              <button type="button" className={tab === 'availability' ? 'active' : ''} onClick={() => setTab('availability')}>
                Availability
              </button>
            )}
          </div>

          {tab === 'permissions' && (
            <div>
              {!form.role ? (
                <p className="muted">Choose a role on the Details tab first.</p>
              ) : form.role === 'admin' ? (
                <p className="muted">An admin can always do everything, so the hospital cannot be locked out.</p>
              ) : isSelf ? (
                <p className="muted">You cannot change your own permissions. Ask another admin or doctor.</p>
              ) : (
                <p className="muted small">Ticked by default according to the role. Change any of them for this person only.</p>
              )}
              {form.role &&
                abilities.map((a) => {
                  const usual = a.roles.includes(form.role)
                  const allowed = form.role === 'admin' ? true : (perms[a.key] ?? usual)
                  return (
                    <label className="check top" key={a.key}>
                      <input type="checkbox" checked={allowed} disabled={form.role === 'admin' || isSelf} onChange={(e) => setPerms((p) => ({ ...p, [a.key]: e.target.checked }))} />
                      <span>
                        {a.label}
                        {allowed !== usual && form.role !== 'admin' && <span className="pill status-arrived"> {allowed ? 'Added' : 'Removed'} for this person</span>}
                        <small className="muted block">{a.hint}</small>
                      </span>
                    </label>
                  )
                })}
            </div>
          )}

          {tab === 'availability' && (
            <div>
              <p className="muted small">The doctor's usual hours, up to two sessions a day. The appointment calendar greys the times outside them. Leave every day empty to follow the hospital's OPD hours. Leave and holidays are marked on the calendar under "Doctor leave".</p>
              <div className="hours">
                <span />
                <span className="field-label">Morning</span>
                <span className="field-label">Evening</span>
                {DAYS.map(([day, label]) => (
                  <div className="hours-row" key={day}>
                    <strong>{label}</strong>
                    {[0, 1].map((i) => (
                      <span className="hours-range" key={i}>
                        <input type="time" value={hours[day][i].from} onChange={(e) => setHour(day, i, 'from', e.target.value)} aria-label={`${label} ${i ? 'evening' : 'morning'} from`} />
                        <span>–</span>
                        <input type="time" value={hours[day][i].to} onChange={(e) => setHour(day, i, 'to', e.target.value)} aria-label={`${label} ${i ? 'evening' : 'morning'} to`} />
                      </span>
                    ))}
                  </div>
                ))}
              </div>
              <div className="row gap-top">
                <button type="button" className="link" onClick={() => setHours((h) => Object.fromEntries(DAYS.map(([day]) => [day, day === 'sun' ? h.sun : h.mon.map((r) => ({ ...r }))])) as Hours)}>
                  Copy Monday to Tuesday–Saturday
                </button>
                <button type="button" className="link danger" onClick={() => setHours(toHours(null))}>
                  Clear all
                </button>
              </div>
              {availabilityError && <p className="form-error">Each session needs a start time and a later end time.</p>}
            </div>
          )}

          {tab === 'details' && (
          <>
          <div className="grid">
            <TextField label="Name" required value={form.name} onChange={set('name')} error={errors.name} autoFocus />
            <SelectField label="Gender" required value={form.gender} onChange={set('gender')} options={GENDERS} placeholder="Select" error={errors.gender} />
            <SelectField label="Role" required value={form.role} onChange={set('role')} options={roles} placeholder="Select role" error={errors.role} />
            <TextField label="Phone" required inputMode="tel" value={form.phone} onChange={set('phone')} error={errors.phone} />
            <TextField label="Login email" required type="email" value={form.email} onChange={set('email')} error={errors.email} autoComplete="off" />
            <TextField
              label={editing === 'new' ? 'Password' : 'New password'}
              required={editing === 'new'}
              type="password"
              placeholder={editing === 'new' ? 'At least 8 letters and numbers' : 'Leave blank to keep current'}
              value={form.password}
              onChange={set('password')}
              error={errors.password}
              autoComplete="new-password"
            />
            <TextField label="Department" list="staff-departments" value={form.department} onChange={set('department')} error={errors.department} />
            <datalist id="staff-departments">
              {departments.map((d) => (
                <option key={d} value={d} />
              ))}
            </datalist>
            <TextField label="Signature text" value={form.signature_text} onChange={set('signature_text')} error={errors.signature_text} />
          </div>

          {editing !== 'new' && !isSelf && (
            <label className="check">
              <input type="checkbox" checked={form.is_active} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))} />
              Account is active (untick to block this person from signing in)
            </label>
          )}

          <h3>Signature picture</h3>
          {editing === 'new' ? (
            <p className="muted small">Add the person first; then a picture of their signature can be uploaded here.</p>
          ) : (
            <div className="signature-box">
              {signature ? <img src={signature} alt={`Signature of ${editing.name}`} /> : <span className="muted small">No signature picture. The signature text is printed on its own.</span>}
              <div className="row">
                <label className="btn ghost small-btn">
                  {editing.has_signature ? 'Replace' : 'Upload'}
                  <input
                    type="file"
                    hidden
                    accept="image/png,image/jpeg"
                    onChange={(e) => {
                      saveSignature(e.target.files?.[0])
                      e.target.value = ''
                    }}
                  />
                </label>
                {editing.has_signature && (
                  <button type="button" className="link danger" onClick={() => saveSignature()}>
                    Remove
                  </button>
                )}
                <span className="muted small">PNG or JPG, up to 300 KB. Printed on prescriptions and lab reports.</span>
              </div>
            </div>
          )}
          </>
          )}

          {errors._ && <p className="form-error">{errors._}</p>}
          <div className="panel-foot">
            <button className="btn primary" disabled={saving}>
              {saving ? 'Saving…' : editing === 'new' ? 'Add staff' : 'Save changes'}
            </button>
          </div>
        </form>
      )}
    </div>
    </>
  )
}
