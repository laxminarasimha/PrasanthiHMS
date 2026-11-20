import type { KeyboardEventHandler } from 'react'
import { rupees } from '../format'
import { discountRupees, type BillDiscount } from './billDiscount'

/**
 * The discount on a whole bill, typed as rupees or as a percentage, switched with ₹ / %. A percentage shows the
 * rupees it comes to, and follows the bill as medicines are added or taken off. The bill keeps the rupee amount.
 */
export function BillDiscountBox({ value, onChange, subtotal, label = 'Bill discount', onKeyDown }: {
  value: BillDiscount
  onChange: (value: BillDiscount) => void
  subtotal: number
  label?: string
  onKeyDown?: KeyboardEventHandler<HTMLInputElement>
}) {
  const percent = value.mode === 'percent'
  const comesTo = discountRupees(value, subtotal)
  const over = percent ? Number(value.typed || 0) > 100 : Number(value.typed || 0) > subtotal && subtotal > 0
  // Switching keeps what the discount comes to: ₹50 on a ₹500 bill becomes 10%, and back.
  const flip = (mode: BillDiscount['mode']) => {
    if (mode === value.mode) return
    if (!comesTo || !subtotal) return onChange({ mode, typed: '' })
    const typed = mode === 'percent' ? Math.round((comesTo / subtotal) * 10000) / 100 : comesTo
    onChange({ mode, typed: String(typed) })
  }
  return (
    <span className="bill-discount">
      <span className="tabs flush mini" role="group" aria-label={`${label} in`}>
        <button type="button" className={percent ? '' : 'active'} onClick={() => flip('amount')} aria-pressed={!percent}>
          ₹
        </button>
        <button type="button" className={percent ? 'active' : ''} onClick={() => flip('percent')} aria-pressed={percent}>
          %
        </button>
      </span>
      <input
        className="qty"
        type="number"
        min={0}
        max={percent ? 100 : undefined}
        step="0.01"
        value={value.typed}
        onChange={(e) => onChange({ ...value, typed: e.target.value })}
        onKeyDown={onKeyDown}
        aria-label={`${label} ${percent ? 'in percent' : 'in rupees'}`}
        aria-invalid={over}
      />
      {percent && comesTo > 0 && <small className="muted block">= {rupees(comesTo)}</small>}
      {over && <small className="due block">{percent ? 'At most 100%.' : 'More than the bill.'}</small>}
    </span>
  )
}
