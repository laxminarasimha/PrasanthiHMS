import { homeFor, ownRecordOnly, workforceHome } from './access'
import type { User } from './types'

export type NavTab = { key: string; label: string; to: string; /** Other paths that belong to this tab. */ also?: string[] }

/** The setup pages a person may open: hospital details and the price list. Staff and their logins are one list, under Workforce. */
export function setupPages(user: User): { label: string; to: string }[] {
  return [
    ...(user.can_manage_setup ? [{ label: 'Hospital', to: '/admin' }] : []),
    ...(user.can_manage_setup ? [{ label: 'Services', to: '/services' }] : []),
  ]
}

/**
 * Where the patient list sits for this person. It has no menu tab of its own: it is one of the doctor's pages, or one
 * of the front desk's for someone who does not see patients. Only a person with neither keeps it as a tab.
 */
export const patientsSection = (user: User): 'doctor' | 'front-desk' | 'patients' => (user.can_consult ? 'doctor' : user.can_front_desk ? 'front-desk' : 'patients')

/** Every menu tab this person is allowed to open, in menu order. */
export function allowedTabs(user: User): NavTab[] {
  // An own-record login has one place only, whatever else its permissions might seem to say.
  if (ownRecordOnly(user)) return workforceTab(user)
  const setup = setupPages(user)
  const patients = patientsSection(user)
  return [
    // Everyone's own list of what is waiting for them and what they have been asked to do.
    { key: 'todo', label: 'To do', to: '/todo' },
    ...(user.can_front_desk ? [{ key: 'front-desk', label: 'Front Desk', to: '/front-desk', also: ['/past-records', '/expenses', ...(user.role === 'doctor' ? [] : ['/reps']), ...(patients === 'front-desk' ? ['/patients'] : [])] }] : []),
    ...(user.can_consult ? [{ key: 'doctor', label: 'Doctor', to: '/doctor', also: ['/visits', '/patients', ...(user.role === 'doctor' || !user.can_front_desk ? ['/reps'] : [])] }] : []),
    ...(user.can_run_lab || user.can_front_desk ? [{ key: 'lab', label: 'Lab', to: '/lab' }] : []),
    // Those who run the pharmacy land on billing; those who may only look land on the manager's page.
    ...(user.can_run_pharmacy ? [{ key: 'pharmacy', label: 'Pharmacy', to: '/pharmacy', ...(!user.can_front_desk && !user.can_consult ? { also: ['/reps'] } : {}) }] : user.can_view_pharmacy ? [{ key: 'pharmacy', label: 'Pharmacy', to: '/pharmacy/manager', also: ['/pharmacy'] }] : []),
    ...(user.can_ipd ? [{ key: 'ipd', label: 'IPD', to: '/ipd' }] : []),
    ...(patients === 'patients' ? [{ key: 'patients', label: 'Patients', to: '/patients' }] : []),
    ...(user.can_view_reports ? [{ key: 'reports', label: 'Reports', to: '/reports' }] : []),
    ...workforceTab(user),
    ...(setup.length ? [{ key: 'setup', label: 'Setup', to: setup[0].to, also: setup.map((p) => p.to) }] : []),
  ]
}

/**
 * The workforce tab. Those who look after staff land on the first of its pages they may open; everyone else with a
 * login gets the same tab named for what it holds for them: their own record.
 */
function workforceTab(user: User): NavTab[] {
  const first = workforceHome(user)
  if (!first) return []
  return [{ key: 'workforce', label: first === '/workforce/me' ? 'My record' : 'Workforce', to: first, also: ['/workforce'] }]
}

/** The tabs shown in this person's menu: the allowed ones less those they chose to hide. */
export function menuTabs(user: User): NavTab[] {
  const hidden = user.hidden_tabs ?? []
  return allowedTabs(user).filter((t) => !hidden.includes(t.key))
}

export const onTab = (tab: NavTab, path: string) => [tab.to, ...(tab.also ?? [])].some((p) => path === p || path.startsWith(p + '/'))

/** One entry on the bar at the foot of a phone's screen. */
export type BottomTab = { key: string; label: string; to: string; /** The paths that light this entry. */ paths: string[] }

const whole = (tab: NavTab) => [tab.to, ...(tab.also ?? [])]

/**
 * The few places a person goes most, for the bar at the foot of a phone's screen: their own first screen, one or two
 * more by what they do, and their own account. Everything else stays in the full menu, which the bar's last button opens.
 */
export function bottomTabs(user: User): BottomTab[] {
  // A person who has arranged their own bar gets exactly that, as far as they may still open each place.
  const own = ownBar(user)
  return own.length ? own : usualBar(user)
}

/** The most places on the bar; "More" always takes the last one. */
export const BAR_MOST = 4

/**
 * Every place a person could put on the bar: each tab of their menu, and the few places that have no tab of
 * their own. Only what they are allowed to open is offered.
 */
export function barChoices(user: User): BottomTab[] {
  const tabs = allowedTabs(user)
  const choices: BottomTab[] = tabs.map((t) => ({ key: t.key, label: t.label, to: t.to, paths: whole(t) }))
  if (ownRecordOnly(user)) return [...choices, { key: 'profile', label: 'Profile', to: '/account', paths: ['/account'] }]
  if (user.can_front_desk) choices.push({ key: 'schedule', label: 'Schedule', to: '/front-desk/calendar', paths: ['/front-desk/calendar'] })
  if (!tabs.some((t) => t.key === 'patients')) choices.push({ key: 'patients', label: 'Patients', to: '/patients', paths: ['/patients'] })
  if (user.abilities?.['wf:self:view'] && !tabs.some((t) => t.to === '/workforce/me')) choices.push({ key: 'attendance', label: 'Attendance', to: '/workforce/me', paths: ['/workforce/me'] })
  return [...choices, { key: 'profile', label: 'Profile', to: '/account', paths: ['/account'] }]
}

/** The bar a person arranged for themself; empty when they have not, or when nothing they chose can be opened any more. */
function ownBar(user: User): BottomTab[] {
  const choices = barChoices(user)
  return (user.bar_tabs ?? []).flatMap((key) => choices.find((c) => c.key === key) ?? []).slice(0, BAR_MOST)
}

/** The usual bar, told as the choices it is made of: where a person starts from when they arrange their own. */
export function usualBarKeys(user: User): string[] {
  const choices = barChoices(user)
  return usualBar(user).flatMap((t) => choices.find((c) => c.to === t.to)?.key ?? [])
}

/** The bar for a person who has not arranged their own, by what their role does. */
function usualBar(user: User): BottomTab[] {
  const tabs = menuTabs(user)
  const home = homeFor(user)
  const homeTab = tabs.find((t) => onTab(t, home))
  const bar: BottomTab[] = [{ key: 'home', label: 'Home', to: home, paths: homeTab ? whole(homeTab) : [home] }]
  if (user.role === 'doctor') {
    // A doctor: the day's queue is home, then the calendar of appointments and the patient list.
    if (user.can_front_desk) bar.push({ key: 'schedule', label: 'Schedule', to: '/front-desk/calendar', paths: ['/front-desk/calendar'] })
    bar.push({ key: 'patients', label: 'Patients', to: '/patients', paths: ['/patients'] })
  } else {
    // Staff: the next section they work in, then their own attendance.
    const next = tabs.find((t) => t !== homeTab && t.key !== 'workforce' && t.key !== 'setup' && t.key !== 'todo')
    if (next) bar.push({ key: next.key, label: next.label, to: next.to, paths: whole(next) })
    // Not when their own record is already their first screen.
    if (user.abilities?.['wf:self:view'] && home !== '/workforce/me') bar.push({ key: 'attendance', label: 'Attendance', to: '/workforce/me', paths: ['/workforce/me'] })
  }
  return [...bar, { key: 'profile', label: 'Profile', to: '/account', paths: ['/account'] }]
}

/** Which entry of the bar the page belongs to: the one whose path fits most closely. None when the page is only in the full menu. */
export function activeBottom(bar: BottomTab[], path: string): string | undefined {
  let best: { key: string; length: number } | undefined
  for (const tab of bar) {
    for (const p of tab.paths) {
      if ((path === p || path.startsWith(p + '/')) && (!best || p.length >= best.length)) best = { key: tab.key, length: p.length }
    }
  }
  return best?.key
}
