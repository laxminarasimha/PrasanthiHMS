import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api'
import { fullName } from '../format'
import type { Patient } from '../types'

/**
 * Finds any patient by name, mobile or patient ID. In the top bar it opens the patient's page;
 * a screen can pass onPick to do something else with the chosen patient.
 */
export function PatientSearch({ onPick, placeholder = 'Find a patient', className = 'patient-search' }: { onPick?: (p: Patient) => void; placeholder?: string; className?: string }) {
  const navigate = useNavigate()
  const [query, setQuery] = useState('')
  const [found, setFound] = useState<{ query: string; patients: Patient[] } | null>(null)
  const [open, setOpen] = useState(false)
  const box = useRef<HTMLDivElement>(null)
  const term = query.trim()

  useEffect(() => {
    if (term.length < 2) return
    let current = true
    const timer = setTimeout(() => {
      api.get<{ data: Patient[] }>('/patients', { params: { q: term } }).then((res) => current && setFound({ query: term, patients: res.data.data.slice(0, 8) }))
    }, 250)
    return () => {
      current = false
      clearTimeout(timer)
    }
  }, [term])

  // Clicking anywhere else closes the list.
  useEffect(() => {
    const close = (e: MouseEvent) => {
      if (!box.current?.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [])

  const patients = term.length >= 2 && found?.query === term ? found.patients : null
  const go = (p: Patient) => {
    setQuery('')
    setOpen(false)
    if (onPick) onPick(p)
    else navigate(`/patients/${p.id}`)
  }

  return (
    <div className={className} ref={box}>
      <input
        type="search"
        placeholder={placeholder}
        value={query}
        onFocus={() => setOpen(true)}
        onChange={(e) => {
          setQuery(e.target.value)
          setOpen(true)
        }}
        onKeyDown={(e) => {
          if (e.key === 'Enter' && patients?.length) go(patients[0])
          if (e.key === 'Escape') setOpen(false)
        }}
        aria-label="Find a patient by name, mobile or patient ID"
      />
      {open && patients && (
        <ul className="picker">
          {patients.map((p) => (
            <li key={p.id}>
              <button type="button" onClick={() => go(p)}>
                <strong>{fullName(p)}</strong>
                <small className="muted">
                  {p.uhid} · {p.age_label} / {p.gender.charAt(0).toUpperCase()} · {p.mobile}
                </small>
              </button>
            </li>
          ))}
          {patients.length === 0 && <li className="none">No patient matches.</li>}
        </ul>
      )}
    </div>
  )
}
