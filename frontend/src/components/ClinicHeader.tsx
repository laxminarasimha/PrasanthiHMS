import { useEffect, useState, type ReactNode } from 'react'
import { api } from '../api'
import { useHeadingPrefs } from '../printPaper'
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
  /**
   * Where the logo sits (on the left with the name and address beside it, or on top with everything centred) and how
   * large the words and the logo are; or `image`, for the hospital's own letterhead picture in place of them. Left out, the hospital's own choice under Setup is used, on every print.
   * Setup passes them itself, to show a choice before it is saved.
   */
  layout?: 'side' | 'centre' | 'image'
  size?: 'small' | 'medium' | 'large'
  logoSize?: 'small' | 'medium' | 'large'
}

/** The heading of every printed document: logo, hospital name, address and phone numbers. */
export function ClinicHeader({ suffix, name, address, children, layout: layoutGiven, size: sizeGiven, logoSize: logoGiven }: Props) {
  const clinic = useClinic()
  const chosen = useHeadingPrefs()
  const layout = layoutGiven ?? chosen.heading
  const size = sizeGiven ?? chosen.headingSize
  const logoSize = logoGiven ?? chosen.logoSize
  const phones = [clinic?.phone, clinic?.phone_alt].filter(Boolean).join(', ')

  // The hospital's letterhead as a picture, when it has chosen that and uploaded one. A heading of its own
  // (the lab's name and address) is still printed in words.
  if (layout === 'image' && clinic?.letterhead_header && !name) {
    return (
      <header className="clinic-head letterhead-image">
        <img className="letterhead-picture" src={clinic.letterhead_header} alt={clinic.name} />
        {children}
      </header>
    )
  }
  // With no picture uploaded yet, the words are printed as they were before.
  const words = layout === 'image' ? 'side' : layout

  return (
    <header className={`clinic-head ${words === 'side' ? 'side' : 'centred'} text-${size} logo-${logoSize}`}>
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

/**
 * The foot of printed documents: the hospital's letterhead picture for the foot when it prints its letterhead as
 * pictures, otherwise its own line of words when it has set one. `plain` keeps to the words, for a narrow receipt.
 */
export function ClinicFooter({ plain = false }: { plain?: boolean }) {
  const clinic = useClinic()
  const { heading } = useHeadingPrefs()
  if (!plain && heading === 'image' && clinic?.letterhead_header && clinic.letterhead_footer) {
    return <img className="letterhead-picture foot" src={clinic.letterhead_footer} alt="" />
  }
  return clinic?.footer ? <p className="clinic-footer">{clinic.footer}</p> : null
}
