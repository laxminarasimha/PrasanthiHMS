import { useState } from 'react'
import type { StaffUser } from '../api'
import { Switch } from '../ui'
import { FrontDeskBoard } from './FrontDeskBoard'
import { LabBoard } from './LabBoard'
import { PharmacyCounter } from './PharmacyCounter'

export type WorkSection = 'desk' | 'lab' | 'pharmacy'

/** Which parts of the day's work this person does, in the order they are offered. */
export const workSections = (user: StaffUser): { value: WorkSection; label: string }[] => [
  ...(user.can_front_desk ? [{ value: 'desk' as const, label: 'Front desk' }] : []),
  ...(user.can_run_lab ? [{ value: 'lab' as const, label: 'Lab' }] : []),
  ...(user.can_run_pharmacy ? [{ value: 'pharmacy' as const, label: 'Pharmacy' }] : []),
]

/** The working screens for the front desk, the lab and the pharmacy, each shown to those who do that work. */
export function Work({ user, refreshKey }: { user: StaffUser; refreshKey: number }) {
  const sections = workSections(user)
  const [section, setSection] = useState<WorkSection>(sections[0]?.value ?? 'desk')
  return (
    <>
      {sections.length > 1 && <Switch options={sections} value={section} onChange={setSection} />}
      {section === 'desk' && <FrontDeskBoard refreshKey={refreshKey} />}
      {section === 'lab' && <LabBoard canRun={Boolean(user.can_run_lab)} refreshKey={refreshKey} />}
      {section === 'pharmacy' && <PharmacyCounter refreshKey={refreshKey} />}
    </>
  )
}
