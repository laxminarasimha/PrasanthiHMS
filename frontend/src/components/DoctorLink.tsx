import { useState, type ReactNode } from 'react'
import { useAuth } from '../auth'
import { DoctorCard } from './DoctorCard'

/** Whether this person works at a clinic desk and so may open a doctor's card. A login that only keeps its own attendance may not. */
const opensDoctors = (u: ReturnType<typeof useAuth>['user']) =>
  Boolean(u && (u.can_front_desk || u.can_consult || u.can_ipd || u.can_run_lab || u.can_run_pharmacy || u.can_view_reports || u.can_view_pharmacy))

/**
 * A doctor's name, wherever it is shown: one click opens their card over the page (today's patients, inpatients,
 * leave, and more for those allowed), and closing it leaves the page as it was.
 */
export function DoctorLink({ id, children }: { id: number | null | undefined; children: ReactNode }) {
  const { user } = useAuth()
  const [open, setOpen] = useState(false)
  if (!id || !opensDoctors(user)) return <>{children}</>
  return (
    <>
      <button
        type="button"
        className="person-link"
        title="Open this doctor: today’s patients, inpatients, leave and hours"
        // A row that opens something itself must not also fire when the name is the thing clicked.
        onClick={(e) => {
          e.stopPropagation()
          setOpen(true)
        }}
      >
        {children}
      </button>
      {open && <DoctorCard id={id} onClose={() => setOpen(false)} />}
    </>
  )
}
