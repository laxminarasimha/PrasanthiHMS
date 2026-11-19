import { useEffect, useId, useState, type KeyboardEvent, type Ref } from 'react'
import { api } from '../api'
import { expiryLabel, placeLabel, rupees } from '../format'
import type { SellableMedicine } from '../types'

export type SellableBatch = SellableMedicine['sellable_batches'][number]

/**
 * The pharmacy's medicine box: type a medicine or drug name, walk the batches in stock with the
 * arrow keys, and press Enter to choose one. Only what the shelf holds is offered.
 */
export function MedicinePick({ onPick, inputRef, placeholder = 'Type a medicine or drug name', hint }: {
  onPick: (medicine: SellableMedicine, batch: SellableBatch) => void
  inputRef?: Ref<HTMLInputElement>
  placeholder?: string
  hint?: string
}) {
  const id = useId()
  const [search, setSearch] = useState('')
  // What came back, and for which words. Results of an earlier search are never offered against a later one,
  // so Enter pressed quickly cannot pick a medicine left over from what was typed before.
  const [found, setFound] = useState<{ q: string; matches: SellableMedicine[] } | null>(null)
  const [at, setAt] = useState(0)
  const q = search.trim()

  useEffect(() => {
    if (q.length < 2) return
    let stale = false
    const timer = setTimeout(() => {
      api
        .get<SellableMedicine[]>('/pharmacy/sell-search', { params: { q } })
        .then((res) => !stale && setFound({ q, matches: res.data }))
        .catch(() => !stale && setFound({ q, matches: [] }))
    }, 200)
    return () => {
      stale = true
      clearTimeout(timer)
    }
  }, [q])

  const ready = q.length >= 2 && found?.q === q
  const offers = ready ? found.matches.flatMap((m) => m.sellable_batches.map((b, i) => ({ m, b, first: i === 0 && m.sellable_batches.length > 1 }))) : []

  const pick = (m: SellableMedicine, b: SellableBatch) => {
    onPick(m, b)
    setSearch('')
    setAt(0)
  }
  const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      setAt((i) => Math.min(i + 1, offers.length - 1))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setAt((i) => Math.max(i - 1, 0))
    } else if (e.key === 'Enter') {
      // Enter here chooses a medicine. It must never save the form around it by accident.
      e.preventDefault()
      const chosen = offers[Math.min(at, offers.length - 1)]
      if (chosen) pick(chosen.m, chosen.b)
    } else if (e.key === 'Escape') {
      setSearch('')
    }
  }

  return (
    <div className="medicine-search">
      <input
        ref={inputRef}
        type="search"
        placeholder={placeholder}
        value={search}
        onChange={(e) => {
          setSearch(e.target.value)
          setAt(0)
        }}
        onKeyDown={onKey}
        aria-label="Search medicine"
        role="combobox"
        aria-expanded={offers.length > 0}
        aria-controls={id}
      />
      {offers.length > 0 && (
        <ul className="picker floating" id={id} role="listbox">
          {offers.map(({ m, b, first }, i) => (
            <li key={b.id} role="option" aria-selected={i === at}>
              <button type="button" className={i === at ? 'on' : ''} onClick={() => pick(m, b)} onMouseEnter={() => setAt(i)}>
                <strong>{m.name}</strong>
                {m.composition && <small className="muted">{m.composition}</small>}
                <small className="muted">
                  Batch {b.batch_no} · exp {expiryLabel(b.expiry)} · {b.units_in_stock} in stock · {rupees(b.unit_mrp)} each
                  {first ? ' · sell this first' : ''}
                </small>
                {placeLabel(b.rack_no, b.box_no) && <small className="shelf-place">{placeLabel(b.rack_no, b.box_no)}</small>}
              </button>
            </li>
          ))}
        </ul>
      )}
      {q.length >= 2 && !ready && <small className="muted">Looking…</small>}
      {ready && offers.length === 0 && <small className="muted">No medicine in stock matches "{q}".</small>}
      {hint && <small className="muted key-hint">{hint}</small>}
    </div>
  )
}
