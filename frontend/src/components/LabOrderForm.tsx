import { useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { rupees, today } from '../format'
import type { LabCatalogueTest, LabOrder, LabPackage, Patient } from '../types'
import { PatientPicker } from './PatientPicker'
import { ReferredBy } from './ReferredBy'
import { EarlierDate } from './EarlierDate'
import { earlier } from '../earlierDay'

type Props = {
  tests: LabCatalogueTest[]
  packages?: LabPackage[]
  /** Set when the order comes from a doctor's visit: who it is for and what was advised. */
  visit?: { patient: Pick<Patient, 'id' | 'uhid' | 'name' | 'gender' | 'age' | 'mobile'>; appointmentId: number; doctor: string; advised: string }
  /** Set when the tests are ordered from an IPD case: the patient is fixed and the lab bill goes on the IPD bill. */
  admission?: { id: number; ipdNo: string; patient: Pick<Patient, 'id' | 'uhid' | 'name'>; doctor: string }
  onClose: () => void
  onCreated: (order: LabOrder) => void
}

// The same test under the names doctors commonly write.
const SAME_TEST = [
  ['cbc', 'cbp', 'complete blood count', 'complete blood picture', 'hemogram', 'haemogram'],
  ['cue', 'urine routine', 'urine r/e', 'complete urine examination'],
  ['rbs', 'grbs', 'random blood sugar'],
  ['fbs', 'fasting blood sugar'],
]

/** The first letter of each word: "cbp" for "complete blood picture". */
const initialsOf = (name: string) => name.split(' ').map((w) => w[0]).join('')

/**
 * The ways a lab test may be written, in two lists. `names`: its name, a short form in brackets such as "(TSH)", its
 * initials, and common other names. `sides`: each side of a dash and its initials, so "Complete Blood Picture - CBP" is
 * also "CBP" (and so "CBC" and "Hemogram"). A side counts only when written exactly so: one such as "Serum" is in the
 * names of many other tests.
 */
function spellings(test: LabCatalogueTest): { names: Set<string>; sides: Set<string> } {
  const full = test.name.toLowerCase().trim()
  const short = [...full.matchAll(/\(([^)]+)\)/g)].map((m) => m[1].trim())
  const plain = full.replace(/\([^)]*\)/g, ' ').replace(/\s+/g, ' ').trim()
  const initials = initialsOf(plain)
  const names = new Set([full, plain, ...short, ...(initials.length >= 3 ? [initials] : [])])
  const parts = plain.split(/\s+[-–—]\s+/).filter(Boolean)
  const sides = new Set(parts.length > 1 ? parts.flatMap((part) => [part, ...(initialsOf(part).length >= 3 ? [initialsOf(part)] : [])]) : [])
  for (const group of SAME_TEST) if (group.some((n) => names.has(n) || sides.has(n))) group.forEach((n) => names.add(n))
  return { names, sides }
}

/** Every way a lab test may be written: its name, a short form in brackets such as "(TSH)", each side of a dash, initials, and common other names. */
export function namesOf(test: LabCatalogueTest) {
  const { names, sides } = spellings(test)
  return new Set([...names, ...sides])
}

/**
 * Matches what the doctor typed ("CBC, TSH" or one test per line) to tests the lab offers.
 * A line matches a test's name, short form, side of a dash or common other name, or a longer line that contains the
 * name; the rest are returned as not found. A short form of fewer than four letters, or one side of a dash, never
 * matches inside other words: "ASO" is not found in "Ultrasound".
 */
export function matchAdvised(advised: string, tests: LabCatalogueTest[]) {
  const known = tests.map((t) => ({ id: t.id, ...spellings(t) }))
  const ids: number[] = []
  const missing: string[] = []
  for (const line of advised.split(/[\n,;]+/).map((l) => l.trim()).filter(Boolean)) {
    const term = line.toLowerCase()
    const found =
      known.find((t) => t.names.has(term) || t.sides.has(term)) ??
      (term.length >= 4 ? known.find((t) => [...t.names].some((n) => n.length >= 4 && (n.includes(term) || term.includes(n)))) : undefined)
    if (found) ids.push(found.id)
    else missing.push(line)
  }
  return { ids: [...new Set(ids)], missing }
}

export function LabOrderForm({ tests, packages = [], visit, admission, onClose, onCreated }: Props) {
  const advised = visit ? matchAdvised(visit.advised, tests) : null
  const [patient, setPatient] = useState<Patient | null>(((admission?.patient ?? visit?.patient) as Patient | undefined) ?? null)
  const [chosen, setChosen] = useState<number[]>(advised?.ids ?? [])
  const [chosenPackages, setChosenPackages] = useState<number[]>([])
  // How many times a ticked test is done, when that is more than once (a CBP repeated the same day).
  const [counts, setCounts] = useState<Record<number, string>>({})
  const times = (id: number) => Math.min(9, Math.max(1, Math.floor(Number(counts[id]) || 1)))
  const [filter, setFilter] = useState('')
  const [referredBy, setReferredBy] = useState(admission?.doctor ?? visit?.doctor ?? '')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  const flip = (id: number) => (ids: number[]) => (ids.includes(id) ? ids.filter((i) => i !== id) : [...ids, id])
  const matches = (name: string) => name.toLowerCase().includes(filter.trim().toLowerCase())
  const visible = tests.filter((t) => matches(t.name))
  const visiblePackages = packages.filter((p) => matches(p.name))

  // A test that is already part of a chosen package is not charged again.
  const inPackage = new Set(packages.filter((p) => chosenPackages.includes(p.id)).flatMap((p) => p.tests.map((t) => t.id)))
  const total =
    packages.filter((p) => chosenPackages.includes(p.id)).reduce((sum, p) => sum + p.price, 0) +
    tests.filter((t) => chosen.includes(t.id) && !inPackage.has(t.id)).reduce((sum, t) => sum + t.price * times(t.id), 0)
  const count = [...inPackage].length + chosen.filter((id) => !inPackage.has(id)).reduce((sum, id) => sum + times(id), 0)

  // The day the tests were asked for, when an order that was missed is entered later.
  const [billedOn, setBilledOn] = useState(today)
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (!patient) {
      setErrors({ patient_id: 'Choose a patient first.' })
      return
    }
    setSaving(true)
    setErrors({})
    try {
      const res = await api.post<LabOrder>('/lab/orders', { patient_id: patient.id, appointment_id: visit?.appointmentId ?? null, admission_id: admission?.id ?? null, test_ids: chosen, package_ids: chosenPackages, referred_by: referredBy || null, billed_on: earlier(billedOn), test_counts: Object.fromEntries(chosen.filter((id) => !inPackage.has(id) && times(id) > 1).map((id) => [id, times(id)])) })
      onCreated(res.data)
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <form className={admission ? 'modal popup wide' : 'card panel'} onSubmit={submit} noValidate>
      <div className="panel-head">
        <div>
          <h2>{admission ? 'Order lab tests' : 'New lab order'}</h2>
          {admission && (
            <span className="muted">
              {admission.patient.name} · {admission.patient.uhid} · IPD {admission.ipdNo}
            </span>
          )}
        </div>
        <button type="button" className="btn ghost" onClick={onClose}>
          Close
        </button>
      </div>

      {admission ? (
        <p className="muted">The lab is told at once, with the ward and bed. The lab bill goes on this patient’s IPD bill by itself, so nothing is paid at the lab.</p>
      ) : (
        <PatientPicker patient={patient} onChange={setPatient} error={errors.patient_id} recent />
      )}

      <div className="gap-top">
        <ReferredBy label={admission ? 'Ordered by' : 'Referred by (optional)'} value={referredBy} onChange={setReferredBy} error={errors.referred_by} ours={admission?.doctor ?? visit?.doctor} />
      </div>

      {visit && advised && (
        <div className="advised">
          <strong>Advised by {visit.doctor}</strong>
          <p>{visit.advised}</p>
          <small>
            {advised.ids.length > 0 ? `${advised.ids.length} ticked below from the lab's list. ` : ''}
            {advised.missing.length > 0 ? `Not in the lab's list: ${advised.missing.join(', ')}. Tick the right test below, or it is done outside.` : 'Check the ticks before saving.'}
          </small>
        </div>
      )}

      <h3>Tests</h3>
      <input type="search" placeholder="Filter tests and packages" value={filter} onChange={(e) => setFilter(e.target.value)} aria-label="Filter tests" />
      <ul className="checklist">
        {visiblePackages.map((p) => (
          <li key={`p${p.id}`}>
            <label>
              <input type="checkbox" checked={chosenPackages.includes(p.id)} onChange={() => setChosenPackages(flip(p.id))} />
              <span className="grow">
                {p.name} <span className="pill status-ongoing">Package</span>
                <small className="muted block">{p.tests.map((t) => t.name).join(', ')}</small>
              </span>
              <span className="mono">{rupees(p.price)}</span>
            </label>
          </li>
        ))}
        {visible.map((t) => (
          <li key={t.id} className="split">
            <label>
              <input type="checkbox" checked={chosen.includes(t.id) || inPackage.has(t.id)} disabled={inPackage.has(t.id)} onChange={() => setChosen(flip(t.id))} />
              <span className="grow">
                {t.name}
                {t.sample_type && <small className="muted"> · {t.sample_type}</small>}
              </span>
            </label>
            {chosen.includes(t.id) && !inPackage.has(t.id) && (
              <input
                className="qty"
                type="number"
                min={1}
                max={9}
                value={counts[t.id] ?? '1'}
                onChange={(e) => setCounts((c) => ({ ...c, [t.id]: e.target.value }))}
                aria-label={`How many times ${t.name} is done`}
                title="How many times this test is done today. Each time gets its own report."
              />
            )}
            <span className="mono">{inPackage.has(t.id) ? 'in package' : rupees(t.price * times(t.id))}</span>
          </li>
        ))}
        {visible.length + visiblePackages.length === 0 && <li className="muted">No tests match.</li>}
      </ul>
      {errors.test_ids && <span className="field-error">{errors.test_ids}</span>}

      {count > 0 && <EarlierDate what="bill" value={billedOn} onChange={setBilledOn} />}
      {(errors._ ?? errors.billed_on ?? errors.admission_id ?? errors.patient_id) && <p className="form-error">{errors._ ?? errors.billed_on ?? errors.admission_id ?? errors.patient_id}</p>}
      <div className="panel-foot between">
        <span>
          {count} test{count === 1 ? '' : 's'} · <strong>{rupees(total)}</strong>
        </span>
        <button className="btn primary" disabled={saving || count === 0}>
          {saving ? 'Saving…' : admission ? 'Order tests' : 'Create order'}
        </button>
      </div>
    </form>
  )
}
