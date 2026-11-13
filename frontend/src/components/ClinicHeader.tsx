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
}

/** The heading of every printed document: logo, hospital name, address and phone numbers. */
export function ClinicHeader({ suffix, name, address, children }: Props) {
  const clinic = useClinic()
  const phones = [clinic?.phone, clinic?.phone_alt].filter(Boolean).join(', ')

  return (
    <header>
      {clinic?.logo && <img className="clinic-logo" src={clinic.logo} alt="" />}
      <h1>
        {name || clinic?.name || 'Prasanthi Hospitals'}
        {suffix ? ` ${suffix}` : ''}
      </h1>
      {!name && clinic?.tagline && <p>{clinic.tagline}</p>}
      {(address || clinic?.address) && <p>{address || clinic?.address}</p>}
      {(phones || clinic?.email) && <p>{[phones && `Phone: ${phones}`, clinic?.email].filter(Boolean).join(' · ')}</p>}
      {children}
    </header>
  )
}

/** The line at the foot of printed documents, when the hospital has set one. */
export function ClinicFooter() {
  const clinic = useClinic()
  return clinic?.footer ? <p className="clinic-footer">{clinic.footer}</p> : null
}
