import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'

type Props = {
  /** Nothing to open for a walk-in customer: the name is then drawn as plain text. */
  id: number | null | undefined
  children: ReactNode
  /** From a money screen the patient page opens at the bills rather than at the top. */
  bills?: boolean
  /** Inside a form that is being filled in, the patient opens beside it so the work is not left. */
  newTab?: boolean
}

/** A patient's name, wherever it is shown: one click opens everything the hospital holds on them. */
export function PatientLink({ id, children, bills, newTab }: Props) {
  if (!id) return <>{children}</>
  const to = `/patients/${id}${bills ? '#bills' : ''}`
  const title = bills ? 'Open this patient: all their bills, visits and reports' : 'Open this patient: visits, bills and reports'
  return newTab ? (
    <a className="patient-link" href={to} target="_blank" rel="noreferrer" title={`${title} (new tab)`}>
      {children}
    </a>
  ) : (
    // A row that opens something itself must not also fire when the name is the thing clicked.
    <Link className="patient-link" to={to} title={title} onClick={(e) => e.stopPropagation()}>
      {children}
    </Link>
  )
}
