import { useEffect, useState, type ReactNode } from 'react'
import { api } from '../api'
import type { Clinic } from '../types'

const CACHE_KEY = 'hms_clinic'

const cached = (): Clinic | null => {
  try {
    return JSON.parse(localStorage.getItem(CACHE_KEY) ?? 'null')
  } catch {
    return null
  }
}

/** The hospital's own details. The last copy seen is shown at once so a print never waits for it. */
export function useClinic(): Clinic | null {
  const [clinic, setClinic] = useState<Clinic | null>(cached)

  useEffect(() => {
    api.get<Clinic>('/clinic').then((res) => {
      setClinic(res.data)
      try {
        localStorage.setItem(CACHE_KEY, JSON.stringify(res.data))
      } catch {
        // A full or blocked store only means the next print fetches it again.
      }
    })
  }, [])

  return clinic
}

type Props = {
  /** Added after the hospital name, e.g. "Pharmacy". */
  suffix?: string
  /** Replaces the hospital name and address, for the lab's own heading. */
  name?: string | null
  address?: string | null
  /** The document's title line and anything else under the heading. */
  children?: ReactNode
  /** Where the logo sits: above the name with everything centred (the usual way), or on the left with the name and address beside it. */
  layout?: 'side' | 'centre'
  /** How large the name and address are, and the logo. Left out, they are as they have always been. */
  size?: 'small' | 'medium' | 'large'
  logoSize?: 'small' | 'medium' | 'large'
}

/** The heading of every printed document: logo, hospital name, address and phone numbers. */
export function ClinicHeader({ suffix, name, address, children, layout = 'centre', size = 'medium', logoSize = 'medium' }: Props) {
  const clinic = useClinic()
  const phones = [clinic?.phone, clinic?.phone_alt].filter(Boolean).join(', ')

  return (
    <header className={`clinic-head ${layout === 'side' ? 'side' : 'centred'} text-${size} logo-${logoSize}`}>
      <div className="clinic-brand">
        {clinic?.logo && <img className="clinic-logo" src={clinic.logo} alt="" />}
        <div className="clinic-text">
          <h1>
            {name || clinic?.name || 'Prasanthi Hospitals'}
            {suffix ? ` ${suffix}` : ''}
          </h1>
          {!name && clinic?.tagline && <p>{clinic.tagline}</p>}
          {(address || clinic?.address) && <p>{address || clinic?.address}</p>}
          {(phones || clinic?.email) && <p>{[phones && `Phone: ${phones}`, clinic?.email].filter(Boolean).join(' · ')}</p>}
        </div>
      </div>
      {children}
    </header>
  )
}

/** The line at the foot of printed documents, when the hospital has set one. */
export function ClinicFooter() {
  const clinic = useClinic()
  return clinic?.footer ? <p className="clinic-footer">{clinic.footer}</p> : null
}
