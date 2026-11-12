import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors, type FieldErrors } from '../api'
import { SelectField, TextField } from '../components/Field'
import { SameMobile } from '../components/SameMobile'
import type { Patient } from '../types'

type Page = { data: Patient[]; current_page: number; last_page: number; total: number }

const GENDERS = [
  { value: 'male', label: 'Male' },
  { value: 'female', label: 'Female' },
  { value: 'other', label: 'Other' },
]
const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'].map((g) => ({ value: g, label: g }))
const LANGUAGES = ['Telugu', 'English', 'Hindi', 'Urdu', 'Tamil'].map((l) => ({ value: l, label: l }))
const CHANNELS = ['Walk-in', 'Referral', 'Friend or family', 'Online search', 'Camp'].map((c) => ({ value: c, label: c }))

const blank = {
  name: '', gender: '', age: '', date_of_birth: '', mobile: '', mobile_alt: '', email: '', blood_group: '',
  preferred_language: '', address: '', city: '', pincode: '', care_of: '', occupation: '', referred_by: '',
  channel: '', tag: '', existing_id: '',
}
type Form = typeof blank

function toForm(p: Patient): Form {
  const form = { ...blank }
  for (const key of Object.keys(blank) as (keyof Form)[]) {
    if (key === 'age') continue
    form[key] = (p[key as keyof Patient] as string | null) ?? ''
  }
  // Show the age, not the made-up birth date, when the date was estimated from an age.
  if (p.dob_estimated) {
    form.age = String(p.age)
    form.date_of_birth = ''
  }
  return form
}

export function Patients() {
  const [page, setPage] = useState<Page | null>(null)
  const [query, setQuery] = useState('')
  const [pageNo, setPageNo] = useState(1)
  const [editing, setEditing] = useState<Patient | 'new' | null>(null)
  const [form, setForm] = useState<Form>(blank)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState('')

  const load = useCallback(() => {
    api.get<Page>('/patients', { params: { q: query || undefined, page: pageNo } }).then((res) => setPage(res.data))
  }, [query, pageNo])

  // Wait briefly after typing so each keystroke does not hit the server.
  useEffect(() => {
    const timer = setTimeout(load, 250)
    return () => clearTimeout(timer)
  }, [load])

  const open = (target: Patient | 'new') => {
    setEditing(target)
    setForm(target === 'new' ? blank : toForm(target))
    setErrors({})
    setNotice('')
  }

  const set = (key: keyof Form) => (value: string) => setForm((f) => ({ ...f, [key]: value }))

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    const payload = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]))
    try {
      const res =
        editing === 'new'
          ? await api.post<Patient>('/patients', payload)
          : await api.put<Patient>(`/patients/${(editing as Patient).id}`, payload)
      setNotice(
        editing === 'new'
          ? `${res.data.name} registered. Patient ID ${res.data.uhid}.`
          : `${res.data.name}'s details were updated.`,
      )
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
          <h1>Patients</h1>
          <input
            className="search"
            type="search"
            placeholder="Search by name, mobile or patient ID"
            value={query}
            onChange={(e) => {
              setQuery(e.target.value)
              setPageNo(1)
            }}
          />
          <button className="btn primary" onClick={() => open('new')}>
            + New patient
          </button>
        </div>

        {notice && <p className="notice">{notice}</p>}

        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Patient ID</th>
                <th>Name</th>
                <th>Age / Sex</th>
                <th>Mobile</th>
                <th>City</th>
                <th>Registered</th>
              </tr>
            </thead>
            <tbody>
              {page?.data.map((p) => (
                <tr key={p.id} onClick={() => open(p)} className={editing !== 'new' && editing?.id === p.id ? 'selected' : ''}>
                  <td className="mono">
                    <Link className="link" to={`/patients/${p.id}`} onClick={(e) => e.stopPropagation()} title="View patient dashboard">
                      {p.uhid}
                    </Link>
                  </td>
                  <td>
                    <strong>{p.name}</strong>
                  </td>
                  <td>
                    {p.age}y / {p.gender.charAt(0).toUpperCase()}
                  </td>
                  <td className="mono">{p.mobile}</td>
                  <td>{p.city ?? '—'}</td>
                  <td>{new Date(p.created_at).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })}</td>
                </tr>
              ))}
              {page && page.data.length === 0 && (
                <tr className="empty">
                  <td colSpan={6}>{query ? 'No patients match this search.' : 'No patients registered yet.'}</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>

        {page && page.last_page > 1 && (
          <div className="pager">
            <button className="btn ghost" disabled={pageNo <= 1} onClick={() => setPageNo(pageNo - 1)}>
              Previous
            </button>
            <span>
              Page {page.current_page} of {page.last_page} · {page.total} patients
            </span>
            <button className="btn ghost" disabled={pageNo >= page.last_page} onClick={() => setPageNo(pageNo + 1)}>
              Next
            </button>
          </div>
        )}
      </section>

      {editing && (
        <form className="card panel" onSubmit={save} noValidate>
          <div className="panel-head">
            <h2>{editing === 'new' ? 'Register patient' : `Edit patient · ${editing.uhid}`}</h2>
            <button type="button" className="btn ghost" onClick={() => setEditing(null)}>
              Close
            </button>
          </div>

          <div className="grid">
            <TextField label="Name" required value={form.name} onChange={set('name')} error={errors.name} autoFocus />
            <SelectField label="Gender" required value={form.gender} onChange={set('gender')} options={GENDERS} placeholder="Select" error={errors.gender} />
            <TextField label="Age (years)" required type="number" min={0} max={130} value={form.age} onChange={set('age')} error={errors.age} />
            <TextField label="or Date of birth" type="date" value={form.date_of_birth} onChange={set('date_of_birth')} error={errors.date_of_birth} />
            <TextField label="Mobile" required inputMode="numeric" maxLength={10} value={form.mobile} onChange={set('mobile')} error={errors.mobile} />
            <SameMobile mobile={form.mobile} exceptId={editing === 'new' ? undefined : editing.id} onUse={editing === 'new' ? open : undefined} />
            <TextField label="Alternate mobile" inputMode="numeric" maxLength={10} value={form.mobile_alt} onChange={set('mobile_alt')} error={errors.mobile_alt} />
            <TextField label="Email" type="email" value={form.email} onChange={set('email')} error={errors.email} />
            <SelectField label="Blood group" value={form.blood_group} onChange={set('blood_group')} options={BLOOD_GROUPS} placeholder="Not known" error={errors.blood_group} />
            <SelectField label="Preferred language" value={form.preferred_language} onChange={set('preferred_language')} options={LANGUAGES} placeholder="Select" error={errors.preferred_language} />
            <TextField label="Care of (C/O)" value={form.care_of} onChange={set('care_of')} error={errors.care_of} />
          </div>

          <h3>Address</h3>
          <div className="grid">
            <div className="span-2">
              <TextField label="Address" value={form.address} onChange={set('address')} error={errors.address} />
            </div>
            <TextField label="City" value={form.city} onChange={set('city')} error={errors.city} />
            <TextField label="PIN code" inputMode="numeric" maxLength={6} value={form.pincode} onChange={set('pincode')} error={errors.pincode} />
          </div>

          <h3>Other details</h3>
          <div className="grid">
            <TextField label="Occupation" value={form.occupation} onChange={set('occupation')} error={errors.occupation} />
            <TextField label="Referred by" value={form.referred_by} onChange={set('referred_by')} error={errors.referred_by} />
            <SelectField label="How they found us" value={form.channel} onChange={set('channel')} options={CHANNELS} placeholder="Select" error={errors.channel} />
            <TextField label="Tag" value={form.tag} onChange={set('tag')} error={errors.tag} />
            <TextField label="Old file / existing ID" value={form.existing_id} onChange={set('existing_id')} error={errors.existing_id} />
          </div>

          {errors._ && <p className="form-error">{errors._}</p>}
          <div className="panel-foot">
            <button className="btn primary" disabled={saving}>
              {saving ? 'Saving…' : editing === 'new' ? 'Register patient' : 'Save changes'}
            </button>
          </div>
        </form>
      )}
    </div>
  )
}
