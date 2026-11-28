import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth'

const stop = (e: { stopPropagation: () => void }) => e.stopPropagation()

/**
 * A lab order's number: in the lab it opens the order's results there; for a doctor or nurse it opens the report.
 * For anyone else it stays plain text.
 */
export function LabOrderLink({ id, children }: { id: number | null | undefined; children: ReactNode }) {
  const { user } = useAuth()
  if (!id || !user) return <>{children}</>
  if (user.can_run_lab)
    return (
      <Link className="patient-link mono" to={`/lab?open=${id}`} title="Open this order in the lab" onClick={stop}>
        {children}
      </Link>
    )
  if (user.can_consult || user.can_ipd)
    return (
      <a className="patient-link mono" href={`/lab/orders/${id}/print`} target="_blank" rel="noreferrer" title="Open the report (new tab)" onClick={stop}>
        {children}
      </a>
    )
  return <>{children}</>
}

/** An IPD number: it opens the case, for anyone who works in IPD. */
export function IpdLink({ id, children }: { id: number | null | undefined; children: ReactNode }) {
  const { user } = useAuth()
  if (!id || !user?.can_ipd) return <>{children}</>
  return (
    <Link className="patient-link mono" to={`/ipd/${id}`} title="Open this IPD case" onClick={stop}>
      {children}
    </Link>
  )
}

/** A stockist's name: it opens their page with every bill from them, for anyone who may open the pharmacy's screens. */
export function StockistLink({ id, children }: { id: number | null | undefined; children: ReactNode }) {
  const { user } = useAuth()
  if (!id || !(user?.can_view_pharmacy || user?.can_run_pharmacy)) return <>{children}</>
  return (
    <Link className="patient-link" to={`/pharmacy/stockists/${id}`} title="Open this stockist: all their bills" onClick={stop}>
      {children}
    </Link>
  )
}
