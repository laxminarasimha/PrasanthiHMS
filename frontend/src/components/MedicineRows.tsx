import type { PrescribedMedicine as Medicine } from '../types'

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

  return (
    <>
      <datalist id="medicine-names">
        {names.map((name) => (
          <option key={name} value={name} />
        ))}
      </datalist>
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
            <input list="medicine-names" placeholder="Medicine name and strength" value={m.medicine} onChange={(e) => set(i, 'medicine', e.target.value)} aria-label={`Medicine ${i + 1}`} aria-invalid={invalid?.(i) ?? false} />
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
      <button type="button" className="link add-medicine" onClick={() => onChange([...rows, emptyMedicine()])}>
        + Add medicine
      </button>
    </>
  )
}
