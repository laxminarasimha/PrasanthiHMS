import { useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { AGE_UNITS, TITLES } from '../format'
import type { Patient } from '../types'
import { Field } from './Field'
import { SameMobile } from './SameMobile'

type Props = {
  patient: Patient | null
  onChange: (patient: Patient | null) => void
  error?: string
}

const summary = (p: Patient) => `${p.uhid} · ${p.age_label} / ${p.gender.charAt(0).toUpperCase()} · ${p.mobile}`

/** Finds a registered patient by name, mobile or patient ID, or registers a new one on the spot. */
export function PatientPicker({ patient, onChange, error }: Props) {
  const [search, setSearch] = useState('')
  const [matches, setMatches] = useState<Patient[]>([])
  const [adding, setAdding] = useState(false)
  const [form, setForm] = useState({ title: '', name: '', gender: '', age: '', age_unit: 'years', mobile: '' })
  const [errors, setErrors] = useState<FieldErrors>({})

  useEffect(() => {
    if (search.trim().length < 2) return
    const timer = setTimeout(() => {
      api.get<{ data: Patient[] }>('/patients', { params: { q: search.trim() } }).then((res) => setMatches(res.data.data.slice(0, 6)))
    }, 250)
    return () => clearTimeout(timer)
  }, [search])

  if (patient) {
    return (
      <div className="chosen">
        <div>
          <strong>{patient.name}</strong>
          <small className="muted">{summary(patient)}</small>
        </div>
        <button type="button" className="link" onClick={() => onChange(null)}>
          Change
        </button>
      </div>
    )
  }

  const register = async (e: FormEvent) => {
    e.preventDefault()
    e.stopPropagation()
    setErrors({})
    try {
      const res = await api.post<Patient>('/patients', { ...form, title: form.title || null })
      setAdding(false)
      setForm({ title: '', name: '', gender: '', age: '', age_unit: 'years', mobile: '' })
      onChange(res.data)
    } catch (err) {
      setErrors(fieldErrors(err))
    }
  }

  if (adding) {
    const set = (key: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [key]: value }))
    return (
      <div className="quick-patient">
        <div className="row">
          <strong className="grow">New patient</strong>
          <button type="button" className="link" onClick={() => setAdding(false)}>
            Search instead
          </button>
        </div>
        <div className="grid gap-top">
          <Field label="Name" required error={errors.name}>
            <input value={form.name} onChange={(e) => set('name')(e.target.value)} autoFocus />
          </Field>
          <Field label="Mobile" required error={errors.mobile}>
            <input inputMode="numeric" maxLength={10} value={form.mobile} onChange={(e) => set('mobile')(e.target.value)} />
          </Field>
          <SameMobile
            mobile={form.mobile}
            onUse={(p) => {
              setAdding(false)
              onChange(p)
            }}
          />
          <Field label="Gender" required error={errors.gender}>
            <select value={form.gender} onChange={(e) => set('gender')(e.target.value)}>
              <option value="">Select</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
              <option value="other">Other</option>
            </select>
          </Field>
          <Field label="Age" required error={errors.age}>
            <div className="row">
              <input type="number" min={0} max={130} value={form.age} onChange={(e) => set('age')(e.target.value)} />
              <select value={form.age_unit} onChange={(e) => set('age_unit')(e.target.value)} aria-label="Age in">
                {AGE_UNITS.map((u) => (
                  <option key={u.value} value={u.value}>
                    {u.label}
                  </option>
                ))}
              </select>
            </div>
          </Field>
          <Field label="Title" error={errors.title}>
            <select value={form.title} onChange={(e) => set('title')(e.target.value)}>
              <option value="">None</option>
              {TITLES.map((t) => (
                <option key={t} value={t}>
                  {t}
                </option>
              ))}
            </select>
          </Field>
        </div>
        <div className="row gap-top">
          <span className="muted small grow">Other details can be added later under Patients.</span>
          <button type="button" className="btn primary" onClick={register}>
            Register
          </button>
        </div>
      </div>
    )
  }

  const shown = search.trim().length >= 2 ? matches : []

  return (
    <Field label="Patient" required error={error}>
      <input type="search" autoFocus placeholder="Search by name, mobile or patient ID" value={search} onChange={(e) => setSearch(e.target.value)} />
      {shown.length > 0 && (
        <ul className="picker">
          {shown.map((p) => (
            <li key={p.id}>
              <button type="button" onClick={() => onChange(p)}>
                <strong>{p.name}</strong>
                <small className="muted">{summary(p)}</small>
              </button>
            </li>
          ))}
        </ul>
      )}
      <button
        type="button"
        className="link add-medicine"
        onClick={() => {
          // Carry over what was typed: digits are a mobile number, anything else a name.
          const typed = search.trim()
          setForm((f) => ({ ...f, name: /^\d+$/.test(typed) ? '' : typed, mobile: /^\d+$/.test(typed) ? typed : '' }))
          setAdding(true)
        }}
      >
        + Add new patient{search.trim().length >= 2 && shown.length === 0 ? ' (no match found)' : ''}
      </button>
    </Field>
  )
}
