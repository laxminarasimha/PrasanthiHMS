import { useEffect, useRef, useState } from 'react'
import { DoctorCard } from './DoctorCard'
import { useLocation, useNavigate } from 'react-router-dom'
import { api } from '../api'
import { fullName } from '../format'
import { recentLine, useRecentPatients, type RecentPatient } from '../recentPatients'
import type { Patient } from '../types'
import { canLeave } from '../unsaved'

/** Something other than a patient that the top search found: a bill, order or IPD number, a medicine, a doctor, staff or a rep. */
type Found = { kind: string; label: string; detail: string | null; to?: string; new_tab?: boolean; doctor_id?: number }

const KIND_LABEL: Record<string, string> = {
  bill: 'Bill', pharmacy_bill: 'Pharmacy', lab_order: 'Lab', ipd: 'IPD', medicine: 'Medicine', doctor: 'Doctor', staff: 'Staff', rep: 'Rep',
}

/**
 * Finds any patient by name, mobile or patient ID. In the top bar it also finds a bill, lab order or IPD number
 * typed as printed, and a medicine, doctor, member of staff or medical rep by name, for those whose work opens them. Before anything is typed it offers the patients who are in
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
  // The other things found, only in the top bar's own search (not on the counter, nor where a screen picks a patient).
  const wide = !onPick && !billing
  const [others, setOthers] = useState<{ query: string; items: Found[] } | null>(null)
  const [doctor, setDoctor] = useState<number | null>(null)
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
      if (wide) api.get<Found[]>('/search', { params: { q: term } }).then((res) => current && setOthers({ query: term, items: res.data })).catch(() => undefined)
    }, 250)
    return () => {
      current = false
      clearTimeout(timer)
    }
  }, [term, wide])

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
  const extra = wide && term.length >= 2 && others?.query === term ? others.items : []
  // One list to move through with the arrows: the patients, then the rest.
  const count = (offered?.length ?? 0) + extra.length
  const goTo = (f: Found) => {
    setQuery('')
    setOpen(false)
    if (f.doctor_id) setDoctor(f.doctor_id)
    else if (f.to && f.new_tab) window.open(f.to, '_blank')
    else if (f.to && canLeave()) navigate(f.to)
  }
  const choose = (i: number) => {
    const list = offered ?? []
    if (i < list.length) go(list[i])
    else if (extra[i - list.length]) goTo(extra[i - list.length])
  }
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
        placeholder={placeholder ?? (billing ? 'Find a patient to bill' : wide ? 'Find a patient, bill, medicine…' : 'Find a patient')}
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
          if (e.key === 'ArrowDown' && count) {
            e.preventDefault()
            setOpen(true)
            setAt((i) => Math.min(i + 1, count - 1))
          } else if (e.key === 'ArrowUp' && count) {
            e.preventDefault()
            setAt((i) => Math.max(i - 1, 0))
          } else if (e.key === 'Enter' && open && count && (term.length >= 2 || at > 0 || billing)) {
            // With nothing typed, Enter takes a recent patient only after one was chosen with the arrows, or on the counter.
            choose(Math.min(at, count - 1))
          } else if (e.key === 'Escape') {
            setOpen(false)
          }
        }}
        aria-label={billing ? 'Find the patient this bill is for, by name, mobile or patient ID' : wide ? 'Find a patient by name, mobile or ID; or a bill, lab order or IPD number, a medicine, doctor, staff member or rep' : 'Find a patient by name, mobile or patient ID'}
      />
      {open && (offered || extra.length > 0) && (
        <ul className="picker">
          {term.length < 2 && <li className="picker-head">{billing ? 'Recently in the hospital · choose who the bill is for' : 'Recently in the hospital'}</li>}
          {(offered ?? []).map((p, i) => (
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
          {offered && offered.length === 0 && extra.length === 0 && <li className="none">Nothing matches.</li>}
          {extra.length > 0 && <li className="picker-head">{offered && offered.length > 0 ? 'Also found' : 'Found'}</li>}
          {extra.map((f, j) => {
            const i = (offered?.length ?? 0) + j
            return (
              <li key={`${f.kind}-${f.label}-${j}`}>
                <button type="button" className={i === at ? 'on' : ''} onClick={() => goTo(f)} onMouseEnter={() => setAt(i)}>
                  <strong>
                    <span className="pill found-kind">{KIND_LABEL[f.kind] ?? f.kind}</span> {f.label}
                  </strong>
                  {f.detail && <small className="muted">{f.detail}</small>}
                </button>
              </li>
            )
          })}
        </ul>
      )}
      {doctor && <DoctorCard id={doctor} onClose={() => setDoctor(null)} />}
    </div>
  )
}
