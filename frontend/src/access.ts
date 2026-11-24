import type { User } from './types'

/**
 * Where the workforce pages start for this person: the first of them they may open, or their own record.
 * Empty for someone with nothing there at all.
 */
export function workforceHome(user: User): string | null {
  const can = (permission: string) => Boolean(user.abilities?.[permission])
  const pages: [string, string][] = [['wf:reports:view', '/workforce'], ['wf:staff:view', '/workforce/staff'], ['wf:attendance:view', '/workforce/attendance'], ['wf:leave:view', '/workforce/leave'], ['wf:payroll:view', '/workforce/payroll'], ['wf:salary:view', '/workforce/pay'], ['wf:advances:view', '/workforce/advances'], ['wf:settings:admin', '/workforce/setup']]
  return pages.find(([permission]) => can(permission))?.[1] ?? (can('wf:self:view') ? '/workforce/me' : null)
}

/** A login that reaches only the person's own attendance, leave and payslips. */
export const ownRecordOnly = (user: User) => user.role === 'staff'

/** The first screen a person sees after signing in, by what their role does. */
export function homeFor(user: User): string {
  if (ownRecordOnly(user)) return '/workforce/me'
  if (user.role === 'doctor') return '/doctor'
  // A manager is there to watch over the whole hospital, so starts on the figures.
  if (user.role === 'manager' && user.can_view_reports) return '/reports'
  if (user.can_front_desk) return '/front-desk'
  if (user.can_run_lab) return '/lab'
  if (user.can_run_pharmacy) return '/pharmacy'
  // The office: HR, accounts and department managers work in the staff pages, not with patients.
  if (['hr', 'accountant', 'dept_manager'].includes(user.role)) return workforceHome(user) ?? '/patients'
  return '/patients'
}
