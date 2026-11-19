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
    ...(user.can_front_desk ? [{ key: 'front-desk', label: 'Front Desk', to: '/front-desk', also: ['/past-records', '/expenses'] }] : []),
    ...(user.can_consult ? [{ key: 'doctor', label: 'Doctor', to: '/doctor', also: ['/visits'] }] : []),
    ...(user.can_run_lab || user.can_front_desk ? [{ key: 'lab', label: 'Lab', to: '/lab' }] : []),
    // Those who run the pharmacy land on billing; those who may only look land on the manager's page.
    ...(user.can_run_pharmacy ? [{ key: 'pharmacy', label: 'Pharmacy', to: '/pharmacy' }] : user.can_view_pharmacy ? [{ key: 'pharmacy', label: 'Pharmacy', to: '/pharmacy/manager', also: ['/pharmacy'] }] : []),
    ...(user.can_ipd ? [{ key: 'ipd', label: 'IPD', to: '/ipd' }] : []),
    { key: 'patients', label: 'Patients', to: '/patients' },
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
  const can = (permission: string) => Boolean(user.abilities?.[permission])
  const pages: [string, string][] = [['wf:reports:view', '/workforce'], ['wf:staff:view', '/workforce/staff'], ['wf:attendance:view', '/workforce/attendance'], ['wf:leave:view', '/workforce/leave'], ['wf:payroll:view', '/workforce/payroll'], ['wf:salary:view', '/workforce/pay'], ['wf:advances:view', '/workforce/advances'], ['wf:settings:admin', '/workforce/setup']]
  const first = pages.find(([permission]) => can(permission))
  if (first) return [{ key: 'workforce', label: 'Workforce', to: first[1], also: ['/workforce'] }]
  return can('wf:self:view') ? [{ key: 'workforce', label: 'My record', to: '/workforce/me', also: ['/workforce'] }] : []
}

/** The tabs shown in this person's menu: the allowed ones less those they chose to hide. */
export function menuTabs(user: User): NavTab[] {
  const hidden = user.hidden_tabs ?? []
  return allowedTabs(user).filter((t) => !hidden.includes(t.key))
}

export const onTab = (tab: NavTab, path: string) => [tab.to, ...(tab.also ?? [])].some((p) => path === p || path.startsWith(p + '/'))
