import { describe, expect, it } from 'vitest'
import { allowedTabs, onTab, patientsSection } from './nav'
import type { User } from './types'

const person = (can: Partial<User>) => ({ id: 1, name: 'Sample Person', role: 'receptionist', ...can }) as User
const keys = (user: User) => allowedTabs(user).map((t) => t.key)
const tabFor = (user: User, path: string) => allowedTabs(user).find((t) => onTab(t, path))?.key

describe('where the patient list sits in the menu', () => {
  it('is one of the doctor’s pages for someone who sees patients', () => {
    const doctor = person({ can_consult: true, can_front_desk: true })
    expect(patientsSection(doctor)).toBe('doctor')
    expect(keys(doctor)).toEqual(['front-desk', 'doctor', 'lab'])
    expect(tabFor(doctor, '/patients')).toBe('doctor')
    expect(tabFor(doctor, '/patients/12/history')).toBe('doctor')
  })

  it('is one of the front desk’s pages for someone who does not', () => {
    const desk = person({ can_front_desk: true })
    expect(patientsSection(desk)).toBe('front-desk')
    expect(keys(desk)).toEqual(['front-desk', 'lab'])
    expect(tabFor(desk, '/patients/12')).toBe('front-desk')
  })

  it('stays a tab of its own for someone with neither', () => {
    const lab = person({ can_run_lab: true })
    expect(keys(lab)).toEqual(['lab', 'patients'])
    expect(tabFor(lab, '/patients')).toBe('patients')
  })
})
