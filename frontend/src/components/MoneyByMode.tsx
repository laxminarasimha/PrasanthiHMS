import { modeLabel, rupees } from '../format'

/** The three every screen shows, even at zero; any other way of paying appears only when money came in by it. */
const ALWAYS = ['cash', 'upi', 'card']
const NAMES: Record<string, string> = { upi: 'UPI' }
const NOTES: Record<string, string> = { cash: 'the staff hand this over', upi: 'goes straight to the bank' }

/**
 * Money received, told apart by how it was paid: cash is what the staff must hand over, while UPI, card and
 * transfers reach the bank on their own. Shown wherever a screen gives a total of money collected.
 */
export function MoneyByMode({ amounts, title = 'Collected, by how it was paid', note }: { amounts: Record<string, number> | null | undefined; title?: string; note?: string }) {
  if (!amounts) return null
  const others = Object.keys(amounts).filter((mode) => !ALWAYS.includes(mode) && mode !== 'ipd' && Math.abs(amounts[mode]) > 0.004)
  return (
    <div className="money-by-mode print-together">
      <h3>{title}</h3>
      <div className="tiles small">
        {[...ALWAYS, ...others].map((mode) => (
          <div className={`tile mode-${mode}`} key={mode}>
            <span>{NAMES[mode] ?? modeLabel(mode)}</span>
            <strong>{rupees(amounts[mode] ?? 0)}</strong>
            {NOTES[mode] && <small className="muted">{NOTES[mode]}</small>}
          </div>
        ))}
      </div>
      {note && <p className="muted small">{note}</p>}
    </div>
  )
}
