import { useId, useMemo, useRef, useState, type KeyboardEvent } from 'react'
import { matchMedicines, type Findable } from './medicineMatch'

/**
 * Chooses a medicine from the whole list, for a purchase invoice, an order or opening stock: type part of its name or
 * what it contains, move with ↑ ↓, and press Enter. When nothing fits, the last choice adds what was typed as a new
 * medicine. Shows the chosen medicine's name when not being typed in.
 */
export function MedicineChoose<T extends Findable>({ medicines, value, onChange, onNew, disabled, label, stockShown }: {
  medicines: T[]
  value: string
  onChange: (id: string) => void
  /** Asked to add a medicine not in the list, with what was typed. */
  onNew?: (typed: string) => void
  disabled?: boolean
  label: string
  /** Show how many units are in stock beside each medicine. */
  stockShown?: boolean
}) {
  const id = useId()
  const chosen = useMemo(() => medicines.find((m) => String(m.id) === value), [medicines, value])
  // What is being typed, and for which chosen medicine: a new choice from outside (a medicine just added) ends it.
  const [typed, setTyped] = useState<{ for: string; text: string } | null>(null)
  const typing = typed?.for === value ? typed.text : null
  const setTyping = (text: string | null) => setTyped(text === null ? null : { for: value, text })
  const [at, setAt] = useState(0)
  const box = useRef<HTMLInputElement>(null)
  const found = useMemo(() => (typing === null ? [] : matchMedicines(medicines, typing)), [medicines, typing])
  const open = typing !== null && typing.trim().length > 0
  const offers = open ? found.length + (onNew ? 1 : 0) : 0

  const choose = (index: number) => {
    if (index < found.length) {
      onChange(String(found[index].id))
      setTyping(null)
    } else if (onNew && typing) {
      onNew(typing.trim())
      setTyping(null)
    }
  }
  const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      setAt((i) => Math.min(i + 1, Math.max(offers - 1, 0)))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setAt((i) => Math.max(i - 1, 0))
    } else if (e.key === 'Enter') {
      // Enter chooses here. It must never save the invoice around it.
      e.preventDefault()
      if (open && offers > 0) choose(Math.min(at, offers - 1))
    } else if (e.key === 'Escape') {
      setTyping(null)
      box.current?.blur()
    }
  }

  return (
    <div className="medicine-search medicine-choose">
      <input
        ref={box}
        value={typing ?? chosen?.name ?? ''}
        placeholder="Type a medicine or what it contains"
        disabled={disabled}
        onFocus={(e) => e.target.select()}
        onChange={(e) => {
          setTyping(e.target.value)
          setAt(0)
        }}
        onBlur={() => setTimeout(() => setTyping(null), 150)}
        onKeyDown={onKey}
        aria-label={label}
        role="combobox"
        aria-expanded={open}
        aria-controls={id}
        autoComplete="off"
      />
      {open && (
        <ul className="picker floating" id={id} role="listbox">
          {found.map((m, i) => (
            <li key={m.id} role="option" aria-selected={i === at}>
              <button type="button" className={i === at ? 'on' : ''} onMouseDown={(e) => e.preventDefault()} onClick={() => choose(i)} onMouseEnter={() => setAt(i)}>
                <strong>{m.name}</strong>
                <small className="muted">{[m.composition, m.type, m.manufacturer, stockShown && m.units_in_stock !== undefined ? `${m.units_in_stock} in stock` : ''].filter(Boolean).join(' · ') || ' '}</small>
              </button>
            </li>
          ))}
          {found.length === 0 && <li className="none">No medicine in the list matches “{typing.trim()}”.</li>}
          {onNew && (
            <li role="option" aria-selected={at === found.length}>
              <button type="button" className={`add-new ${at === found.length ? 'on' : ''}`} onMouseDown={(e) => e.preventDefault()} onClick={() => choose(found.length)} onMouseEnter={() => setAt(found.length)}>
                <strong>＋ Add “{typing.trim()}” as a new medicine</strong>
              </button>
            </li>
          )}
        </ul>
      )}
    </div>
  )
}
