import { Fragment, useCallback, useEffect, useState, type FormEvent } from 'react'
import { useLoadOnChange } from '../loading'
import { SetupTabs } from '../components/SetupTabs'
import { api, fieldErrors, type FieldErrors } from '../api'
import { useAuth } from '../auth'
import { SelectField, TextField } from '../components/Field'
import { clock, longDate } from '../format'
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
/** The counters a doctor can have a share of: bills of their visits, lab bills from their visits, bills of inpatients under them. */
const SHARES = [['front_desk', 'Front desk bills of their visits'], ['lab', 'Lab bills from their visits'], ['ipd', 'Bills of inpatients under them']] as const
const noShares = { front_desk: '', lab: '', ipd: '' }
type Form = typeof blank

/** Every change made to one person's role and permissions: who made it, when, what changed and why. */
function PermissionHistory({ staffId }: { staffId: number }) {
  const [rows, setRows] = useState<{ id: number; by: string | null; at: string | null; what: string | null }[] | null>(null)
  useEffect(() => {
    let current = true
    api
      .get<{ id: number; by: string | null; at: string | null; what: string | null }[]>(`/staff/${staffId}/permission-history`)
      .then((res) => current && setRows(res.data))
      .catch(() => current && setRows([]))
    return () => {
      current = false
    }
  }, [staffId])
  if (!rows || rows.length === 0) return null
  return (
    <div className="permission-history">
      <h3>Changes to this person’s permissions</h3>
      <ul>
        {rows.map((r) => (
          <li key={r.id}>
            <strong>{r.at ? `${longDate(r.at)} ${clock(r.at)}` : ''}</strong> · {r.by ?? 'Unknown'}
            <small className="muted block">{(r.what ?? '').split(' · ').slice(1).join(' · ')}</small>
          </li>
        ))}
      </ul>
    </div>
  )
}

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

  // At once when the screen opens; after a short pause when a search or filter changes.
  useLoadOnChange(load)

  const open = (target: StaffMember | 'new') => {
    setEditing(target)
    setErrors({})
    setNotice('')
    setReason('')
    setTab('details')
    setPerms(target === 'new' ? {} : target.abilities)
    setHours(toHours(target === 'new' ? null : target.availability))
    setSlot(target === 'new' || !target.slot_minutes ? '' : String(target.slot_minutes))
    const share = (value?: number | null) => (target === 'new' || value == null ? '' : String(value))
    setShares(target === 'new' ? noShares : { front_desk: share(target.share_front_desk), lab: share(target.share_lab), ipd: share(target.share_ipd) })
    setForm(
      target === 'new'
        ? blank
        : {
            name: target.name, email: target.email ?? '', password: '', role: target.role, gender: target.gender ?? '',
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
  // The doctor's own slot length in minutes; empty follows the hospital's.
  const [slot, setSlot] = useState('')
  // A doctor's share of collections, in percent for each counter; empty means none is worked out. Admins only.
  const [shares, setShares] = useState(noShares)
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
  const isAdmin = user?.role === 'admin'
  const had = (key: string) => (editing && editing !== 'new' ? Boolean(editing.abilities[key]) : false)
  /**
   * Why whoever is signed in cannot give this permission to the person, or '' if they can. Nobody gives more than
   * they hold, and a sensitive one on top of the role is the admin's to give. What a person already has stays.
   */
  const barred = (a: Ability) => {
    if (isAdmin || had(a.key)) return ''
    if (!user?.abilities?.[a.key]) return 'You cannot give this: you are not allowed to do it yourself.'
    if (a.sensitive && !a.roles.includes(form.role)) return 'Only an admin can give this on top of the role.'
    return ''
  }
  const allowedOf = (a: Ability) => (form.role === 'admin' ? true : barred(a) ? false : (perms[a.key] ?? a.roles.includes(form.role)))
  // A change of role or of any tick is kept with who made it, when and why, so a reason is asked for.
  const [reason, setReason] = useState('')
  const changing = Boolean(editing && editing !== 'new' && !isSelf && form.role && (form.role !== editing.role || abilities.some((a) => allowedOf(a) !== Boolean(editing.abilities[a.key]))))

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    const body = {
      ...form,
      permissions: form.role ? Object.fromEntries(abilities.map((a) => [a.key, allowedOf(a)])) : undefined,
      reason: changing ? reason.trim() : undefined,
      availability: form.role === 'doctor' ? Object.fromEntries(DAYS.map(([day]) => [day, hours[day].filter((r) => r.from && r.to)])) : null,
      slot_minutes: form.role === 'doctor' && slot ? Number(slot) : null,
      ...(isAdmin ? Object.fromEntries(SHARES.map(([key]) => [`share_${key}`, form.role === 'doctor' && shares[key] !== '' ? Number(shares[key]) : null])) : {}),
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
      setTab(first.startsWith('availability') ? 'availability' : first.startsWith('permissions') || first === 'reason' ? 'permissions' : 'details')
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
                  <td className="fit-wrap">
                    <strong>{s.name}</strong>
                  </td>
                  <td className="wrap">{roleLabel(s.role)}</td>
                  <td className="fit-wrap">{s.email}</td>
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
            {/* The own-record role is exactly that: there is nothing to tick on top of it. */}
            {form.role !== 'staff' && (
              <button type="button" className={tab === 'permissions' ? 'active' : ''} onClick={() => setTab('permissions')}>
                Permissions
              </button>
            )}
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
                <p className="muted small">
                  Ticked by default according to the role. Change any of them for this person only: an unticked box always wins over the role.
                  {!isAdmin && ' You can give only what you are allowed to do yourself; the ones marked “admin only” are given by an admin.'}
                </p>
              )}
              {form.role &&
                abilities.map((a, index) => {
                  const usual = a.roles.includes(form.role)
                  const allowed = allowedOf(a)
                  const why = form.role === 'admin' || isSelf ? '' : barred(a)
                  // The list is long: each part of the system gets its own heading.
                  const heading = a.group && a.group !== abilities[index - 1]?.group ? a.group : ''
                  return (
                    <Fragment key={a.key}>
                    {heading && <h3 className="permission-group">{heading}</h3>}
                    <label className="check top">
                      <input type="checkbox" checked={allowed} disabled={form.role === 'admin' || isSelf || Boolean(why)} onChange={(e) => setPerms((p) => ({ ...p, [a.key]: e.target.checked }))} />
                      <span>
                        {a.label}
                        {a.sensitive && <span className="pill off"> Admin only to add</span>}
                        {allowed !== usual && form.role !== 'admin' && <span className="pill status-arrived"> {allowed ? 'Added' : 'Removed'} for this person</span>}
                        <small className="muted block">{a.hint}</small>
                        {why && <small className="due block">{why}</small>}
                      </span>
                    </label>
                    </Fragment>
                  )
                })}
              {errors.permissions && <p className="form-error">{errors.permissions}</p>}
              {editing !== 'new' && editing && <PermissionHistory key={`${editing.id}-${staff.length}`} staffId={editing.id} />}
            </div>
          )}

          {tab === 'availability' && (
            <div>
              <p className="muted small">The doctor's usual hours, up to two sessions a day. The appointment calendar greys the times outside them, and booking a later visit outside them asks the desk to confirm. Leave every day empty to follow the hospital's OPD hours. Leave and holidays are marked on the calendar under "Doctor leave".</p>
              <label className="field slot-length">
                <span className="field-label">Length of one appointment slot</span>
                <select value={slot} onChange={(e) => setSlot(e.target.value)}>
                  <option value="">Same as the hospital</option>
                  {[5, 10, 15, 20, 30, 60].map((m) => (
                    <option key={m} value={m}>
                      {m === 60 ? '1 hour' : `${m} minutes`}
                    </option>
                  ))}
                </select>
              </label>
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
            <TextField label="Login email (optional)" type="email" placeholder="Without one, they sign in with the mobile number" value={form.email} onChange={set('email')} error={errors.email} autoComplete="off" />
            <TextField
              label={form.role === 'staff' ? (editing === 'new' ? 'Starting PIN' : 'New starting PIN') : editing === 'new' ? 'Password' : 'New password'}
              required={editing === 'new'}
              type="password"
              inputMode={form.role === 'staff' ? 'numeric' : undefined}
              placeholder={form.role === 'staff' ? '6 digits; they choose their own at first sign-in' : editing === 'new' ? 'At least 8 letters and numbers' : 'Leave blank to keep current'}
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

          {isAdmin && form.role === 'doctor' && (
            <>
              <h3>Doctor's share of collections</h3>
              <p className="muted small">
                Optional. The percentage of the money collected from this doctor's own patients that is the doctor's, for each counter. The report "Doctor: share of collections" works it out for any dates; paying it is
                entered under Expenses. Only admins see these figures.
              </p>
              <div className="doctor-shares">
                {SHARES.map(([key, label]) => (
                  <TextField key={key} label={`${label} (%)`} type="number" min={0} max={100} step="0.5" placeholder="None" value={shares[key]} onChange={(v) => setShares((s) => ({ ...s, [key]: v }))} error={errors[`share_${key}`]} />
                ))}
              </div>
            </>
          )}

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

          {changing && (
            <div className="change-reason">
              <TextField label="Reason for changing this person’s role or permissions" required value={reason} onChange={setReason} error={errors.reason} placeholder="Kept with the change: who made it, when and why" />
            </div>
          )}
          {errors._ && <p className="form-error">{errors._}</p>}
          <div className="panel-foot">
            <button className="btn primary" disabled={saving || (changing && !reason.trim())} title={changing && !reason.trim() ? 'Give the reason for the change first' : undefined}>
              {saving ? 'Saving…' : editing === 'new' ? 'Add staff' : 'Save changes'}
            </button>
          </div>
        </form>
      )}
    </div>
    </>
  )
}
