import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { useAuth } from '../auth'

type Counts = { unjoined: unknown[]; differences: unknown[]; shared_numbers: unknown[] }

/**
 * At the top of the staff list, for those who manage logins: a line saying that some logins and staff records do not
 * agree yet, with the way to the staff check. Nothing is shown when all is in order.
 */
export function StaffCheckNote() {
  const { user } = useAuth()
  const may = Boolean(user?.can_manage_staff && user.abilities?.['wf:staff:edit'])
  const [counts, setCounts] = useState<Counts | null>(null)
  useEffect(() => {
    if (may) api.get<Counts>('/workforce/staff-check').then((res) => setCounts(res.data)).catch(() => undefined)
  }, [may])
  if (!may || !counts) return null

  const parts = [
    counts.unjoined.length ? `${counts.unjoined.length} login${counts.unjoined.length === 1 ? ' belongs' : 's belong'} to no member of staff` : '',
    counts.differences.length ? `${counts.differences.length} detail${counts.differences.length === 1 ? ' differs' : 's differ'} between a person and their login` : '',
    counts.shared_numbers.length ? `${counts.shared_numbers.length} mobile number${counts.shared_numbers.length === 1 ? ' is' : 's are'} on more than one login` : '',
  ].filter(Boolean)
  if (parts.length === 0) return null

  return (
    <p className="check-note">
      {parts.join('; ')}. <Link to="/workforce/staff/check">Open the staff check</Link>
    </p>
  )
}
