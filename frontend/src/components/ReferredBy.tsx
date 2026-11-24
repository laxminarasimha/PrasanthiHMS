import { useEffect, useId, useState } from 'react'
import { api } from '../api'
import type { ReferralDoctor } from '../types'

// One read of the list serves every "referred by" box on the page.
let list: Promise<ReferralDoctor[]> | null = null
const referringDoctors = () => (list ??= api.get<ReferralDoctor[]>('/referral-doctors').then((res) => res.data.filter((d) => d.direction === 'by')).catch(() => []))

/** A name without its "Dr", dots, capitals and extra spaces: the same comparison the server makes. */
const plainName = (name: string) =>
  name
    .toLowerCase()
    .trim()
    .replace(/^(dr|doctor)\b[\s.]*/, '')
    .replace(/[^\p{L}\p{N}]+/gu, ' ')
    .trim()

/**
 * The "referred by" box: pick a doctor from the hospital's list of referring doctors, or type another name.
 * A name on the list is saved in the list's spelling, so the referral reports show one row for each doctor.
 */
export function ReferredBy({ label = 'Referred by', value, onChange, error, placeholder = 'Pick from the list or type a name', ours }: { label?: string; value: string; onChange: (value: string) => void; error?: string; placeholder?: string; /** The hospital's own doctor when the box starts with their name: no remark is made about the list. */ ours?: string | null }) {
  const id = useId()
  const [doctors, setDoctors] = useState<ReferralDoctor[]>([])
  useEffect(() => {
    let live = true
    referringDoctors().then((d) => live && setDoctors(d))
    return () => {
      live = false
    }
  }, [])

  const typed = plainName(value)
  const match = typed ? doctors.find((d) => plainName(d.name) === typed || plainName(d.name) === plainName(value.split(',')[0])) : undefined

  return (
    <label className="field">
      <span className="field-label">{label}</span>
      <input list={id} placeholder={placeholder} value={value} onChange={(e) => onChange(e.target.value)} aria-invalid={Boolean(error)} />
      <datalist id={id}>
        {doctors.map((d) => (
          <option key={d.id} value={d.name} label={[d.specialization, d.place].filter(Boolean).join(', ')} />
        ))}
      </datalist>
      {error ? (
        <span className="field-error">{error}</span>
      ) : match ? (
        <span className="field-hint ok">On the list: {[match.name, match.specialization, match.place].filter(Boolean).join(', ')}</span>
      ) : typed && doctors.length > 0 && value.trim() !== (ours ?? '').trim() ? (
        <span className="field-hint">Not on the list of referring doctors. It will be saved as typed.</span>
      ) : null}
    </label>
  )
}
