import { useEffect, useState } from 'react'
import { api } from '../api'
import type { Patient } from '../types'

type Props = {
  mobile: string
  /** The patient being edited, who should not be reported as their own duplicate. */
  exceptId?: number
  /** When given, each match gets a button to use that patient instead of registering again. */
  onUse?: (patient: Patient) => void
}

/** Warns that a mobile number already belongs to registered patients, so the same person is not registered twice. */
export function SameMobile({ mobile, exceptId, onUse }: Props) {
  const [found, setFound] = useState<{ mobile: string; patients: Patient[] }>({ mobile: '', patients: [] })
  const complete = /^\d{10}$/.test(mobile)

  useEffect(() => {
    if (!complete) return
    let current = true
    api.get<{ data: Patient[] }>('/patients', { params: { mobile } }).then((res) => current && setFound({ mobile, patients: res.data.data }))
    return () => {
      current = false
    }
  }, [mobile, complete])

  const matches = complete && found.mobile === mobile ? found.patients.filter((p) => p.id !== exceptId) : []
  if (matches.length === 0) return null

  return (
    <div className="same-mobile span-2" role="status">
      <strong>
        This mobile number is already registered for {matches.length === 1 ? 'a patient' : `${matches.length} patients`}.
      </strong>
      <ul>
        {matches.slice(0, 5).map((p) => (
          <li key={p.id}>
            <span>
              {p.name} · {p.uhid} · {p.age_label} / {p.gender.charAt(0).toUpperCase()}
            </span>
            {onUse && (
              <button type="button" className="link" onClick={() => onUse(p)}>
                Use this patient
              </button>
            )}
          </li>
        ))}
      </ul>
      <small>Family members can share a number. Register again only if this is a different person.</small>
    </div>
  )
}
