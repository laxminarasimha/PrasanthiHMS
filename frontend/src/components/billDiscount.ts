/** A discount on a whole bill as it is typed: a rupee amount, or a percentage of the bill. */
export type BillDiscount = { mode: 'amount' | 'percent'; typed: string }

export const noDiscount = (mode: BillDiscount['mode'] = 'amount'): BillDiscount => ({ mode, typed: '' })

/**
 * The rupees a discount comes to on a bill of this amount, to the paisa. A percentage is of the bill before the
 * discount, never more than all of it; an amount is never more than the bill either.
 */
export function discountRupees(d: BillDiscount, subtotal: number): number {
  const typed = Number(d.typed || 0)
  if (!(typed > 0) || !(subtotal > 0)) return 0
  const rupees = d.mode === 'percent' ? (subtotal * Math.min(typed, 100)) / 100 : typed
  return Math.round(Math.min(rupees, subtotal) * 100) / 100
}
