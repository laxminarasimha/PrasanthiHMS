import type { Service } from './types'

/** The appointment services a doctor's visit may be billed with: the doctor's own first, then those open to every doctor. */
export function offeredTo(services: Service[], doctorId: string): Service[] {
  const fit = services.filter((s) => s.type === 'appointment' && s.is_active !== false && (!s.doctor_id || String(s.doctor_id) === doctorId))
  return [...fit.filter((s) => s.doctor_id), ...fit.filter((s) => !s.doctor_id)]
}

/**
 * The service put in by itself when a doctor is chosen: the one marked as the doctor's usual consultation, else the
 * first of the doctor's own. Nothing when the doctor has none of their own, and the desk chooses from the list.
 */
export function usualService(services: Service[], doctorId: string): Service | undefined {
  const own = offeredTo(services, doctorId).filter((s) => s.doctor_id)
  return own.find((s) => s.is_doctor_fee) ?? own[0]
}
