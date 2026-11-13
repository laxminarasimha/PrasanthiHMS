import type { User } from './types'

export type NavTab = { key: string; label: string; to: string; /** Other paths that belong to this tab. */ also?: string[] }

/** The setup pages a person may open: hospital details, staff, and the price list. */
export function setupPages(user: User): { label: string; to: string }[] {
  return [
    ...(user.can_manage_setup ? [{ label: 'Hospital', to: '/admin' }] : []),
    ...(user.can_manage_staff ? [{ label: 'Staff', to: '/staff' }] : []),
    ...(user.can_manage_setup ? [{ label: 'Services', to: '/services' }] : []),
  ]
}

/** Every menu tab this person is allowed to open, in menu order. */
export function allowedTabs(user: User): NavTab[] {
  const setup = setupPages(user)
  return [
    ...(user.can_front_desk ? [{ key: 'front-desk', label: 'Front Desk', to: '/front-desk' }] : []),
    ...(user.can_consult ? [{ key: 'doctor', label: 'Doctor', to: '/doctor', also: ['/visits'] }] : []),
    ...(user.can_run_lab || user.can_front_desk ? [{ key: 'lab', label: 'Lab', to: '/lab' }] : []),
    ...(user.can_run_pharmacy ? [{ key: 'pharmacy', label: 'Pharmacy', to: '/pharmacy' }] : []),
    ...(user.can_ipd ? [{ key: 'ipd', label: 'IPD', to: '/ipd' }] : []),
    { key: 'patients', label: 'Patients', to: '/patients' },
    ...(user.can_view_reports ? [{ key: 'reports', label: 'Reports', to: '/reports' }] : []),
    ...(setup.length ? [{ key: 'setup', label: 'Setup', to: setup[0].to, also: setup.map((p) => p.to) }] : []),
  ]
}

/** The tabs shown in this person's menu: the allowed ones less those they chose to hide. */
export function menuTabs(user: User): NavTab[] {
  const hidden = user.hidden_tabs ?? []
  return allowedTabs(user).filter((t) => !hidden.includes(t.key))
}

export const onTab = (tab: NavTab, path: string) => [tab.to, ...(tab.also ?? [])].some((p) => path === p || path.startsWith(p + '/'))
