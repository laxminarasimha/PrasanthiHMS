import { useEffect, useState } from 'react'
import { api } from '../api'
import type { ReferralDoctor } from '../types'

/** Suggestions for a "referred by" or "refer to" box. Give the input `list={id}` to use them. */
export function ReferralList({ id, direction }: { id: string; direction: 'by' | 'to' }) {
  const [doctors, setDoctors] = useState<ReferralDoctor[]>([])

  useEffect(() => {
    api.get<ReferralDoctor[]>('/referral-doctors').then((res) => setDoctors(res.data)).catch(() => setDoctors([]))
  }, [])

  return (
    <datalist id={id}>
      {doctors
        .filter((d) => d.direction === direction)
        .map((d) => (
          <option key={d.id} value={[d.name, d.specialization, d.place].filter(Boolean).join(', ')} />
        ))}
    </datalist>
  )
}
