import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { useAuth } from '../auth'
import { SelectField, TextField } from '../components/Field'
import type { RoleOption, Staff as StaffMember } from '../types'

const GENDERS = [
  { value: 'male', label: 'Male' },
  { value: 'female', label: 'Female' },
  { value: 'other', label: 'Other' },
]

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

  const load = useCallback(() => {
    api.get<{ data: StaffMember[]; roles: RoleOption[] }>('/staff', { params: { q: query || undefined } }).then((res) => {
      setStaff(res.data.data)
      setRoles(res.data.roles)
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

  const set = (key: keyof Form) => (value: string) => setForm((f) => ({ ...f, [key]: value }))
  const roleLabel = (value: string) => roles.find((r) => r.value === value)?.label ?? value
  const isSelf = editing !== 'new' && editing?.id === user?.id

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    try {
      const res =
        editing === 'new'
          ? await api.post<StaffMember>('/staff', form)
          : await api.put<StaffMember>(`/staff/${(editing as StaffMember).id}`, form)
      setNotice(editing === 'new' ? `${res.data.name} was added.` : `${res.data.name}'s details were updated.`)
      setEditing(null)
      load()
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  return (
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
            <TextField label="Department" value={form.department} onChange={set('department')} error={errors.department} />
            <TextField label="Signature text" value={form.signature_text} onChange={set('signature_text')} error={errors.signature_text} />
          </div>

          {editing !== 'new' && !isSelf && (
            <label className="check">
              <input type="checkbox" checked={form.is_active} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))} />
              Account is active (untick to block this person from signing in)
            </label>
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
  )
}
