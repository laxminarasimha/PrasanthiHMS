import { FREQUENCIES, TIMINGS } from './MedicineRows'

import { emptyDrug, type CatalogueBrand, type RepDrug } from '../repDrugs'

const DOSES = ['1 tablet', '½ tablet', '2 tablets', '1 capsule', '5 ml', '10 ml', '1 puff', 'Apply locally']
const DURATIONS = ['3 days', '5 days', '7 days', '10 days', '15 days', '1 month', '3 months', 'To continue']

/**
 * The drugs of a visit, one to a block: the brand and what it contains, what it is for, how the doctor would give
 * it, and the MRP, the price the hospital gets it at and any offer. A brand already in the company's catalogue fills
 * in what the catalogue knows; a new one joins the catalogue when the visit is saved.
 */
export function RepDrugRows({ rows, onChange, catalogue, canKeep }: { rows: RepDrug[]; onChange: (rows: RepDrug[]) => void; catalogue: CatalogueBrand[]; canKeep: boolean }) {
  const known = (name: string) => catalogue.find((c) => c.name.toLowerCase() === name.trim().toLowerCase())
  const set = (index: number, patch: Partial<RepDrug>) => onChange(rows.map((r, i) => (i === index ? { ...r, ...patch } : r)))
  const setName = (index: number, name: string) => {
    const brand = known(name)
    const row = rows[index]
    // Chosen from the catalogue: what it already knows is put in, where the line is still empty.
    set(index, {
      name,
      ...(brand
        ? {
            composition: row.composition || brand.composition || '',
            uses: row.uses || brand.uses || '',
            mrp: row.mrp || (brand.mrp !== null ? String(brand.mrp) : ''),
            rate: row.rate || (brand.rate !== null ? String(brand.rate) : ''),
            scheme: row.scheme || brand.scheme || '',
          }
        : {}),
    })
  }

  return (
    <div className="rep-drugs">
      <datalist id="rep-drug-brands">
        {catalogue.map((c) => (
          <option key={c.name} value={c.name}>
            {c.composition ?? ''}
          </option>
        ))}
      </datalist>
      <datalist id="rep-drug-doses">{DOSES.map((v) => <option key={v} value={v} />)}</datalist>
      <datalist id="rep-drug-frequencies">{FREQUENCIES.map((v) => <option key={v} value={v} />)}</datalist>
      <datalist id="rep-drug-timings">{TIMINGS.map((v) => <option key={v} value={v} />)}</datalist>
      <datalist id="rep-drug-durations">{DURATIONS.map((v) => <option key={v} value={v} />)}</datalist>

      {rows.map((r, i) => {
        const brand = known(r.name)
        return (
          <div className="rep-drug" key={i}>
            <div className="rep-drug-head">
              <span className="rep-drug-no">{i + 1}</span>
              <input list="rep-drug-brands" value={r.name} onChange={(e) => setName(i, e.target.value)} placeholder="Brand name" aria-label={`Brand ${i + 1}`} />
              {(rows.length > 1 || r.name) && (
                <button type="button" className="link danger" onClick={() => onChange(rows.length > 1 ? rows.filter((_, x) => x !== i) : [emptyDrug()])} aria-label={`Remove drug ${i + 1}`}>
                  Remove
                </button>
              )}
            </div>
            {/* The catalogue's own record of what a brand contains is not changed from here. */}
            {brand?.composition ? (
              <small className="muted rep-drug-known">Contains {brand.composition}</small>
            ) : (
              <input value={r.composition} onChange={(e) => set(i, { composition: e.target.value })} placeholder="What it contains: Pantoprazole 40 mg, or Drug A 5 mg + Drug B 10 mg" aria-label={`What drug ${i + 1} contains`} />
            )}
            <input value={r.uses} onChange={(e) => set(i, { uses: e.target.value })} placeholder="What it is used for" aria-label={`What drug ${i + 1} is used for`} />
            <div className="rep-drug-grid four">
              <label className="mini">
                <span>Dose</span>
                <input list="rep-drug-doses" value={r.dose} onChange={(e) => set(i, { dose: e.target.value })} aria-label={`Dose of drug ${i + 1}`} />
              </label>
              <label className="mini">
                <span>How often</span>
                <input list="rep-drug-frequencies" value={r.frequency} onChange={(e) => set(i, { frequency: e.target.value })} aria-label={`How often for drug ${i + 1}`} />
              </label>
              <label className="mini">
                <span>When</span>
                <input list="rep-drug-timings" value={r.timing} onChange={(e) => set(i, { timing: e.target.value })} aria-label={`When to take drug ${i + 1}`} />
              </label>
              <label className="mini">
                <span>For how long</span>
                <input list="rep-drug-durations" value={r.duration} onChange={(e) => set(i, { duration: e.target.value })} aria-label={`For how long for drug ${i + 1}`} />
              </label>
            </div>
            <div className="rep-drug-grid three">
              <label className="mini">
                <span>MRP of a pack ₹</span>
                <input type="number" min={0} step="0.01" value={r.mrp} onChange={(e) => set(i, { mrp: e.target.value })} aria-label={`MRP of drug ${i + 1}`} />
              </label>
              <label className="mini">
                <span>Price we get it at ₹</span>
                <input type="number" min={0} step="0.01" value={r.rate} onChange={(e) => set(i, { rate: e.target.value })} aria-label={`Price we get drug ${i + 1} at`} />
              </label>
              <label className="mini">
                <span>Offer</span>
                <input value={r.scheme} onChange={(e) => set(i, { scheme: e.target.value })} placeholder="10 + 1…" aria-label={`Offer on drug ${i + 1}`} />
              </label>
            </div>
            <input value={r.note} onChange={(e) => set(i, { note: e.target.value })} placeholder="Note or instructions (optional)" aria-label={`Note on drug ${i + 1}`} />
            {canKeep && (
              <label className="check flush">
                <input type="checkbox" checked={r.keep} onChange={(e) => set(i, { keep: e.target.checked })} />
                Keep for my prescriptions: fill in this dose when I prescribe it
              </label>
            )}
          </div>
        )
      })}
      <button type="button" className="link" onClick={() => onChange([...rows, emptyDrug()])}>
        + Add another drug
      </button>
    </div>
  )
}
