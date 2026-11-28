import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth'

/** A medicine's name, wherever the pharmacy shows it: one click opens its own page. For anyone who cannot open the pharmacy's screens it is plain text. */
export function MedicineLink({ id, children }: { id: number | null | undefined; children: ReactNode }) {
  const { user } = useAuth()
  if (!id || !(user?.can_view_pharmacy || user?.can_run_pharmacy)) return <>{children}</>
  return (
    <Link className="patient-link" to={`/pharmacy/medicines/${id}`} title="Open this medicine: stock, suppliers, sales and other brands" onClick={(e) => e.stopPropagation()}>
      {children}
    </Link>
  )
}
