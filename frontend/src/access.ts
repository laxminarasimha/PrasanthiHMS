import type { User } from './types'

/** The first screen a person sees after signing in, by what their role does. */
export function homeFor(user: User): string {
  if (user.role === 'doctor') return '/doctor'
  // A manager is there to watch over the whole hospital, so starts on the figures.
  if (user.role === 'manager' && user.can_view_reports) return '/reports'
  if (user.can_front_desk) return '/front-desk'
  if (user.can_run_lab) return '/lab'
  if (user.can_run_pharmacy) return '/pharmacy'
  return '/patients'
}
