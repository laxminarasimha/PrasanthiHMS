import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import type { Patient } from '../types'
import { Field } from './Field'

type Props = {
  patient: Patient | null
  onChange: (patient: Patient | null) => void
  error?: string
}

const summary = (p: Patient) => `${p.uhid} · ${p.age}y / ${p.gender.charAt(0).toUpperCase()} · ${p.mobile}`

/** Search box that finds a registered patient by name, mobile or patient ID. */
export function PatientPicker({ patient, onChange, error }: Props) {
  const [search, setSearch] = useState('')
  const [matches, setMatches] = useState<Patient[]>([])

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
      {search.trim().length >= 2 && shown.length === 0 && (
        <small className="muted">
          No patient found. <Link to="/patients">Register a new patient</Link> first.
        </small>
      )}
    </Field>
  )
}
