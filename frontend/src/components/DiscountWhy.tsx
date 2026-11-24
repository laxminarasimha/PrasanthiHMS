import { useEffect, useState } from 'react'
import { api } from '../api'
import type { Doctor } from '../types'
import type { Why } from './discountFields'


const REASONS = ['Staff or staff family', 'Doctor’s instruction', 'Cannot afford', 'Review visit', 'Regular patient', 'Camp or offer']

// The doctors' names are the same on every panel, so they are fetched once for the page.
let names: Promise<string[]> | null = null
const doctorNames = () => (names ??= api.get<Doctor[]>('/doctors').then((res) => res.data.map((d) => d.name)).catch(() => []))

/**
 * Asked whenever a discount is typed: why it is given and who allowed it. Both are kept on the bill
 * and shown in the bill report.
 */
export function DiscountWhy({ value, onChange, id }: { value: Why; onChange: (next: Why) => void; id: string }) {
  const [doctors, setDoctors] = useState<string[]>([])
  useEffect(() => {
    let live = true
    doctorNames().then((list) => live && setDoctors(list))
    return () => {
      live = false
    }
  }, [])

  return (
    <div className="discount-why">
      <label className="field">
        <span className="field-label">
          Reason for the discount<span className="req"> *</span>
        </span>
        <input list={`${id}-reasons`} placeholder="Why it is given" maxLength={255} value={value.reason} onChange={(e) => onChange({ ...value, reason: e.target.value })} />
      </label>
      <label className="field">
        <span className="field-label">
          Allowed by<span className="req"> *</span>
        </span>
        <input list={`${id}-people`} placeholder="Who allowed it" maxLength={100} value={value.by} onChange={(e) => onChange({ ...value, by: e.target.value })} />
      </label>
      <datalist id={`${id}-reasons`}>
        {REASONS.map((r) => (
          <option key={r} value={r} />
        ))}
      </datalist>
      <datalist id={`${id}-people`}>
        {doctors.map((d) => (
          <option key={d} value={d} />
        ))}
      </datalist>
    </div>
  )
}
