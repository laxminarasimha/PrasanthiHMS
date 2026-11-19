import type { LabOrder } from '../types'

/** The one thing an order is waiting for next. */
export type Step = 'collect' | 'report' | 'approve' | 'ready'
export const STEPS: Step[] = ['collect', 'report', 'approve', 'ready']
export const STEP_LABEL = { collect: 'Sample to collect', report: 'Results to enter', approve: 'To approve', ready: 'Ready' } as const
export const STEP_CLASS = { collect: 'status-arrived', report: 'status-ongoing', approve: 'status-booked', ready: 'ok' } as const

export const stepOf = (order: LabOrder, approval: boolean): Step =>
  order.items.some((i) => i.stage === 'booked')
    ? 'collect'
    : order.items.some((i) => i.stage === 'collected')
      ? 'report'
      : approval && order.items.some((i) => i.stage === 'reported')
        ? 'approve'
        : 'ready'

/** Where a patient with several orders stands: at the earliest step any of them is still waiting for. */
export const earliestStep = (steps: Step[]): Step => STEPS.find((s) => steps.includes(s)) ?? 'ready'
