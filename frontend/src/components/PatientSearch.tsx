import { useEffect, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { api } from '../api'
import { fullName } from '../format'
import { recentLine, useRecentPatients, type RecentPatient } from '../recentPatients'
import type { Patient } from '../types'
import { canLeave } from '../unsaved'

/**
 * Finds any patient by name, mobile or patient ID. Before anything is typed it offers the patients who are in
 * the hospital or were here in the last few days. In the top bar it opens the patient's page, except on the
 * pharmacy counter, where the chosen patient is put on the bill being made; a screen can pass onPick to do
 * something else with the chosen patient.
 */
export function PatientSearch({ onPick, placeholder, className = 'patient-search' }: { onPick?: (p: Patient) => void; placeholder?: string; className?: string }) {
  const navigate = useNavigate()
  const { pathname } = useLocation()
  // On the pharmacy's billing screen the search is for whom the bill is for.
  const billing = !onPick && pathname === '/pharmacy'
  const [query, setQuery] = useState('')
  const [found, setFound] = useState<{ query: string; patients: Patient[] } | null>(null)
  const [open, setOpen] = useState(false)
  const [at, setAt] = useState(0)
  const { recent, ask } = useRecentPatients()
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

  const searched = term.length >= 2 && found?.query === term ? found.patients : null
  // What the list holds: the matches once something is typed, the recent patients before that.
  const offered: (Patient | RecentPatient)[] | null = term.length >= 2 ? searched : recent.length > 0 ? recent : null
  const go = (p: Patient) => {
    setQuery('')
    setOpen(false)
    if (onPick) onPick(p)
    else if (billing) navigate(`/pharmacy?patient=${p.id}`)
    else if (canLeave()) navigate(`/patients/${p.id}`)
  }

  return (
    <div className={className} ref={box}>
      <input
        type="search"
        placeholder={placeholder ?? (billing ? 'Find a patient to bill' : 'Find a patient')}
        value={query}
        onFocus={() => {
          setOpen(true)
          ask()
        }}
        onChange={(e) => {
          setQuery(e.target.value)
          setOpen(true)
          setAt(0)
        }}
        onKeyDown={(e) => {
          if (e.key === 'ArrowDown' && offered?.length) {
            e.preventDefault()
            setOpen(true)
            setAt((i) => Math.min(i + 1, offered.length - 1))
          } else if (e.key === 'ArrowUp' && offered?.length) {
            e.preventDefault()
            setAt((i) => Math.max(i - 1, 0))
          } else if (e.key === 'Enter' && open && offered?.length && (term.length >= 2 || at > 0 || billing)) {
            // With nothing typed, Enter takes a recent patient only after one was chosen with the arrows, or on the counter.
            go(offered[Math.min(at, offered.length - 1)])
          } else if (e.key === 'Escape') {
            setOpen(false)
          }
        }}
        aria-label={billing ? 'Find the patient this bill is for, by name, mobile or patient ID' : 'Find a patient by name, mobile or patient ID'}
      />
      {open && offered && (
        <ul className="picker">
          {term.length < 2 && <li className="picker-head">{billing ? 'Recently in the hospital · choose who the bill is for' : 'Recently in the hospital'}</li>}
          {offered.map((p, i) => (
            <li key={p.id}>
              <button type="button" className={i === at ? 'on' : ''} onClick={() => go(p)} onMouseEnter={() => setAt(i)}>
                <strong>{fullName(p)}</strong>
                <small className="muted">
                  {p.uhid} · {p.age_label} / {p.gender.charAt(0).toUpperCase()} · {p.mobile}
                </small>
                {'recent' in p && (
                  <small className={p.recent.here ? 'recent-why here' : 'recent-why'}>
                    {recentLine(p.recent)}
                  </small>
                )}
              </button>
            </li>
          ))}
          {offered.length === 0 && <li className="none">No patient matches.</li>}
        </ul>
      )}
    </div>
  )
}
