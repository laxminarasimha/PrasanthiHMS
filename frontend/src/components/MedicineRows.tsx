import { useEffect, useRef, useState, type KeyboardEvent } from 'react'
import { api } from '../api'
import type { PrescribedMedicine as Medicine } from '../types'

/** A medicine the pharmacy lists, as offered while prescribing. */
type Found = { name: string; composition: string | null; type: string | null; in_stock: number; combination: boolean; by_drug: boolean; before?: boolean }
type Offer = { row: number; at: number; left: number; top: number; width: number; up: boolean }

/** Where the list of medicines sits: under the box, or above it when the box is near the bottom of the screen. */
const place = (box: HTMLInputElement) => {
  const at = box.getBoundingClientRect()
  const up = at.bottom + 300 > window.innerHeight && at.top > 300
  return { left: Math.max(8, Math.min(at.left, window.innerWidth - 468)), top: up ? at.top - 4 : at.bottom + 4, width: Math.min(460, window.innerWidth - 16), up }
}

export const FREQUENCIES = ['1-0-0', '0-1-0', '0-0-1', '1-0-1', '1-1-0', '0-1-1', '1-1-1', '1-1-1-1', 'SOS', 'Once a week']
export const TIMINGS = ['After food', 'Before food', 'Empty stomach', 'At bedtime']
export const emptyMedicine = (): Medicine => ({ medicine: '', dose: '', frequency: '', timing: '', duration: '', instructions: '' })

/** Rows left completely blank are dropped rather than reported as errors. */
export const filledMedicines = (rows: Medicine[]) =>
  rows
    .filter((m) => Object.entries(m).some(([k, v]) => k !== 'id' && v))
    .map(({ medicine, dose, frequency, timing, duration, instructions }) => ({ medicine, dose, frequency, timing, duration, instructions }))

type Props = {
  rows: Medicine[]
  onChange: (rows: Medicine[]) => void
  names?: string[]
  /** How this doctor usually gives each medicine; fills the rest of a row when its name is chosen. */
  defaults?: Medicine[]
  invalid?: (index: number) => boolean
  /** The doctor's own lists for the Frequency and When suggestions. */
  frequencies?: string[]
  timings?: string[]
}

/** The medicine grid used on the visit pad and in Rx groups. */
export function MedicineRows({ rows, onChange, names = [], defaults = [], invalid, frequencies = FREQUENCIES, timings = TIMINGS }: Props) {
  const set = (index: number, key: keyof Medicine, value: string) =>
    onChange(
      rows.map((row, i) => {
        if (i !== index) return row
        const next = { ...row, [key]: value }
        if (key !== 'medicine' || row.dose || row.frequency || row.timing || row.duration || row.instructions) return next
        const usual = defaults.find((d) => d.medicine.toLowerCase() === value.trim().toLowerCase())
        return usual ? { ...next, dose: usual.dose ?? '', frequency: usual.frequency ?? '', timing: usual.timing ?? '', duration: usual.duration ?? '', instructions: usual.instructions ?? '' } : next
      }),
    )

  // While a medicine is being typed, the pharmacy's list is searched by brand and by the drug inside,
  // so the doctor sees the single drugs and the combinations on the shelf, not only a name that matches.
  const [offer, setOffer] = useState<Offer | null>(null)
  const [found, setFound] = useState<Found[]>([])
  const asked = useRef(0)
  const timer = useRef<number | undefined>(undefined)

  const look = (index: number, text: string, box: HTMLInputElement) => {
    window.clearTimeout(timer.current)
    const term = text.trim()
    if (term.length < 2) {
      setOffer(null)
      return
    }
    setOffer({ row: index, at: 0, ...place(box) })
    const mine = ++asked.current
    timer.current = window.setTimeout(() => {
      api.get<Found[]>('/medicines/find', { params: { q: term } }).then((res) => {
        if (mine !== asked.current) return
        const listed = new Set(res.data.map((f) => f.name.toLowerCase()))
        // Names this doctor has written before that the pharmacy does not list are still offered.
        const before = names.filter((n) => n.toLowerCase().includes(term.toLowerCase()) && !listed.has(n.toLowerCase())).slice(0, 5)
        setFound([...res.data, ...before.map((name) => ({ name, composition: null, type: null, in_stock: 0, combination: false, by_drug: false, before: true }))])
      })
    }, 180)
  }
  const choose = (index: number, name: string) => {
    set(index, 'medicine', name)
    setOffer(null)
  }
  const onMedicineKey = (index: number, e: KeyboardEvent<HTMLInputElement>) => {
    if (!offer || offer.row !== index || found.length === 0) return
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      setOffer({ ...offer, at: Math.min(offer.at + 1, found.length - 1) })
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setOffer({ ...offer, at: Math.max(offer.at - 1, 0) })
    } else if (e.key === 'Enter') {
      e.preventDefault()
      choose(index, found[offer.at].name)
    } else if (e.key === 'Escape') {
      setOffer(null)
    }
  }
  // The list floats over the page, so when the page moves it follows the box being typed in.
  const showing = offer !== null
  useEffect(() => {
    if (!showing) return
    const follow = (e: Event) => {
      if (e.target instanceof Element && e.target.closest('.rx-pick')) return
      const box = document.activeElement
      if (!(box instanceof HTMLInputElement) || !box.closest('.rx-row')) {
        setOffer(null)
        return
      }
      setOffer((o) => (o ? { ...o, ...place(box) } : o))
    }
    window.addEventListener('scroll', follow, true)
    window.addEventListener('resize', follow)
    return () => {
      window.removeEventListener('scroll', follow, true)
      window.removeEventListener('resize', follow)
    }
  }, [showing])

  return (
    <>
      <datalist id="frequencies">
        {frequencies.map((f) => (
          <option key={f} value={f} />
        ))}
      </datalist>
      <datalist id="timings">
        {timings.map((t) => (
          <option key={t} value={t} />
        ))}
      </datalist>
      <div className="rx">
        <div className="rx-row rx-head">
          <span>Medicine</span>
          <span>Dose</span>
          <span>Frequency</span>
          <span>When</span>
          <span>Duration</span>
          <span>Instructions</span>
          <span />
        </div>
        {rows.map((m, i) => (
          <div className="rx-row" key={i}>
            <input
              placeholder="Brand or drug name"
              value={m.medicine}
              onChange={(e) => {
                set(i, 'medicine', e.target.value)
                look(i, e.target.value, e.target)
              }}
              onKeyDown={(e) => onMedicineKey(i, e)}
              onBlur={() => window.setTimeout(() => setOffer((o) => (o?.row === i ? null : o)), 180)}
              aria-label={`Medicine ${i + 1}`}
              aria-invalid={invalid?.(i) ?? false}
              autoComplete="off"
              role="combobox"
              aria-expanded={offer?.row === i && found.length > 0}
              aria-controls="rx-pick"
            />
            <input placeholder="1 tablet" value={m.dose ?? ''} onChange={(e) => set(i, 'dose', e.target.value)} aria-label="Dose" />
            <input list="frequencies" placeholder="1-0-1" value={m.frequency ?? ''} onChange={(e) => set(i, 'frequency', e.target.value)} aria-label="Frequency" />
            <input list="timings" placeholder="After food" value={m.timing ?? ''} onChange={(e) => set(i, 'timing', e.target.value)} aria-label="When to take" />
            <input placeholder="5 days" value={m.duration ?? ''} onChange={(e) => set(i, 'duration', e.target.value)} aria-label="Duration" />
            <input value={m.instructions ?? ''} onChange={(e) => set(i, 'instructions', e.target.value)} aria-label="Instructions" />
            <button type="button" className="link danger" onClick={() => onChange(rows.length > 1 ? rows.filter((_, r) => r !== i) : [emptyMedicine()])}>
              Remove
            </button>
          </div>
        ))}
      </div>
      {offer && found.length > 0 && (
        <ul className={`rx-pick ${offer.up ? 'up' : ''}`} id="rx-pick" role="listbox" style={{ left: offer.left, top: offer.top, width: offer.width }}>
          {found.map((f, n) => (
            <li key={f.name} role="option" aria-selected={n === offer.at}>
              {/* Chosen on mouse-down, before the box loses the cursor and the list closes. */}
              <button type="button" className={n === offer.at ? 'on' : ''} onMouseDown={(e) => { e.preventDefault(); choose(offer.row, f.name) }} onMouseEnter={() => setOffer({ ...offer, at: n })}>
                <span className="rx-pick-line">
                  <strong>{f.name}</strong>
                  {f.before ? <span className="pill status-booked">written before</span> : f.in_stock > 0 ? <span className="pill ok">In stock · {f.in_stock}</span> : <span className="pill status-booked">Not in stock</span>}
                </span>
                {(f.composition || f.type) && (
                  <small className="muted">
                    {f.combination ? 'Combination: ' : ''}
                    {[f.composition, f.type].filter(Boolean).join(' · ')}
                  </small>
                )}
              </button>
            </li>
          ))}
        </ul>
      )}
      <button type="button" className="link add-medicine" onClick={() => onChange([...rows, emptyMedicine()])}>
        + Add medicine
      </button>
    </>
  )
}
