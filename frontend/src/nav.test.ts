import { describe, expect, it } from 'vitest'
import { activeBottom, allowedTabs, bottomTabs, onTab, patientsSection } from './nav'
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

describe('the bar at the foot of a phone’s screen', () => {
  const own = { 'wf:self:view': true }

  it('gives a doctor the queue, the calendar, the patient list and their account', () => {
    const doctor = person({ role: 'doctor', can_consult: true, can_front_desk: true, can_run_lab: true, abilities: own })
    const bar = bottomTabs(doctor)
    expect(bar.map((t) => [t.key, t.to])).toEqual([['home', '/doctor'], ['schedule', '/front-desk/calendar'], ['patients', '/patients'], ['profile', '/account']])
    expect(activeBottom(bar, '/doctor')).toBe('home')
    expect(activeBottom(bar, '/visits/7')).toBe('home')
    // The patient list is one of the doctor's pages, yet on the bar it lights its own entry.
    expect(activeBottom(bar, '/patients/12/history')).toBe('patients')
    expect(activeBottom(bar, '/front-desk/calendar')).toBe('schedule')
    expect(activeBottom(bar, '/account')).toBe('profile')
    // A page that is only in the full menu lights nothing.
    expect(activeBottom(bar, '/lab')).toBeUndefined()
  })

  it('gives staff their own screen, the next section, their attendance and their account', () => {
    const desk = person({ role: 'frontdesk', can_front_desk: true, abilities: own })
    const bar = bottomTabs(desk)
    expect(bar.map((t) => [t.key, t.to])).toEqual([['home', '/front-desk'], ['lab', '/lab'], ['attendance', '/workforce/me'], ['profile', '/account']])
    expect(activeBottom(bar, '/front-desk/bills')).toBe('home')
    expect(activeBottom(bar, '/patients/3')).toBe('home')
    expect(activeBottom(bar, '/lab/tests')).toBe('lab')
    expect(activeBottom(bar, '/workforce/me')).toBe('attendance')
  })

  it('leaves out what a person may not open or has hidden', () => {
    const lab = person({ role: 'lab_tech', can_run_lab: true })
    expect(bottomTabs(lab).map((t) => t.key)).toEqual(['home', 'patients', 'profile'])
    const hidden = person({ role: 'frontdesk', can_front_desk: true, hidden_tabs: ['lab'], abilities: own })
    expect(bottomTabs(hidden).map((t) => t.key)).toEqual(['home', 'attendance', 'profile'])
  })
})

describe('a login that opens only the person’s own record', () => {
  const ward = person({ role: 'staff', abilities: { 'wf:self:view': true, 'wf:self:create': true } })

  it('has one place in the menu and starts there', () => {
    expect(allowedTabs(ward).map((t) => [t.key, t.label, t.to])).toEqual([['workforce', 'My record', '/workforce/me']])
    expect(bottomTabs(ward).map((t) => [t.key, t.to])).toEqual([['home', '/workforce/me'], ['profile', '/account']])
  })

  it('stays that way whatever else is ticked on it', () => {
    const ticked = person({ role: 'staff', can_front_desk: true, can_consult: true, can_view_reports: true, abilities: { 'wf:self:view': true } })
    expect(allowedTabs(ticked).map((t) => t.key)).toEqual(['workforce'])
  })

  it('office roles start in the staff pages, not on the patient list', () => {
    const hr = person({ role: 'hr', abilities: { 'wf:staff:view': true, 'wf:self:view': true } })
    expect(bottomTabs(hr)[0].to).toBe('/workforce/staff')
  })
})

