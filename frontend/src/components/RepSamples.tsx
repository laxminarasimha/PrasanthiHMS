import { Fragment, useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { daysUntil, longDate, today } from '../format'
import type { Doctor, Patient } from '../types'
import { SelectField, TextField } from './Field'
import { PatientPicker } from './PatientPicker'

type Issue = { id: number; kind: 'given' | 'discarded'; quantity: number; given_on: string; note: string | null; patient?: { id: number; uhid: string; name: string } | null; giver?: { id: number; name: string } | null }
type Sample = {
  id: number
  rep_company_id: number
  medical_rep_id: number | null
  doctor_id: number | null
  name: string
  quantity: number
  unit: string | null
  expiry: string | null
  received_on: string
  notes: string | null
  given: number
  discarded: number
  in_hand: number
  company?: { id: number; name: string }
  rep?: { id: number; name: string; mobile: string | null } | null
  doctor?: { id: number; name: string } | null
  issues: Issue[]
}
type Company = { id: number; name: string; reps?: { id: number; name: string; is_active: boolean }[] }
/** What a new entry starts with, when it is opened from a representative's visit. */
export type SampleStart = { companyId: number; repId: number; visitId: number; doctorId: number | null }

const blank = { rep_company_id: '', medical_rep_id: '', doctor_id: '', name: '', quantity: '', unit: '', expiry: '', received_on: '', notes: '' }

/**
 * The register of samples left by medical representatives: what came in, what was given out or thrown away, and
 * what is left. Samples are not for sale, so they are not in the pharmacy's stock and never go on a bill.
 */
export function RepSamples({ doctors, start }: { doctors: Doctor[]; start?: SampleStart | null }) {
  const [samples, setSamples] = useState<Sample[] | null>(null)
  const [companies, setCompanies] = useState<Company[]>([])
  const [brands, setBrands] = useState<string[]>([])
  const [show, setShow] = useState<'in_hand' | 'all'>('in_hand')
  const [query, setQuery] = useState('')
  // Opened from a visit, the entry starts with the company, the representative and the doctor already known.
  const [draft, setDraft] = useState<(typeof blank & { rep_visit_id?: number }) | null>(() =>
    start ? { ...blank, rep_company_id: String(start.companyId), medical_rep_id: String(start.repId), doctor_id: start.doctorId ? String(start.doctorId) : '', received_on: today(), rep_visit_id: start.visitId } : null,
  )
  const [editing, setEditing] = useState<number | null>(null)
  const [giving, setGiving] = useState<{ sample: Sample; kind: 'given' | 'discarded'; quantity: string; given_on: string; note: string; patient: Patient | null } | null>(null)
  const [open, setOpen] = useState<number | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)

  const load = useCallback(() => api.get<{ samples: Sample[] }>('/reps/samples', { params: { show, q: query || undefined } }).then((res) => setSamples(res.data.samples)).catch(() => setNotice('The samples could not be loaded.')), [show, query])
  useEffect(() => {
    const wait = window.setTimeout(load, 250)
    return () => window.clearTimeout(wait)
  }, [load])
  useEffect(() => {
    api.get<Company[]>('/reps/companies').then((res) => setCompanies(res.data)).catch(() => undefined)
  }, [])
  // The brands in the chosen company's catalogue are offered as the name is typed.
  const companyId = draft?.rep_company_id ?? ''
  useEffect(() => {
    if (!companyId) return
    let live = true
    api.get<{ products: { name: string }[] }>('/reps/products', { params: { company_id: companyId } }).then((res) => live && setBrands(res.data.products.map((p) => p.name))).catch(() => undefined)
    return () => {
      live = false
    }
  }, [companyId])

  const set = (key: keyof typeof blank) => (value: string) => setDraft((d) => (d ? { ...d, [key]: value, ...(key === 'rep_company_id' ? { medical_rep_id: '' } : {}) } : d))
  const edit = (s: Sample | null) => {
    setErrors({})
    setEditing(s?.id ?? null)
    setDraft(
      s
        ? { rep_company_id: String(s.rep_company_id), medical_rep_id: s.medical_rep_id ? String(s.medical_rep_id) : '', doctor_id: s.doctor_id ? String(s.doctor_id) : '', name: s.name, quantity: String(s.quantity), unit: s.unit ?? '', expiry: s.expiry ?? '', received_on: s.received_on, notes: s.notes ?? '' }
        : { ...blank, received_on: today() },
    )
  }
  const save = async (e: FormEvent) => {
    e.preventDefault()
    if (!draft) return
    setSaving(true)
    setErrors({})
    const body = Object.fromEntries(Object.entries(draft).map(([k, v]) => [k, typeof v === 'string' && v.trim() === '' ? null : v]))
    try {
      await (editing ? api.put(`/reps/samples/${editing}`, body) : api.post('/reps/samples', body))
      setNotice(editing ? 'Saved.' : `${draft.quantity} ${draft.unit || ''} of ${draft.name} entered.`.replace('  ', ' '))
      // More than one kind of sample is often left at once: the next starts from the same company and day.
      setDraft(editing ? null : { ...blank, rep_company_id: draft.rep_company_id, medical_rep_id: draft.medical_rep_id, doctor_id: draft.doctor_id, received_on: draft.received_on, rep_visit_id: draft.rep_visit_id })
      setEditing(null)
      void load()
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }
  const remove = async (s: Sample) => {
    if (!window.confirm(`Take the entry for ${s.name} off the register? Use this only for an entry made by mistake.`)) return
    try {
      await api.delete(`/reps/samples/${s.id}`)
      setDraft(null)
      setEditing(null)
      void load()
    } catch (err) {
      setErrors(fieldErrors(err))
    }
  }
  const give = async (e: FormEvent) => {
    e.preventDefault()
    if (!giving) return
    setSaving(true)
    setErrors({})
    try {
      await api.post(`/reps/samples/${giving.sample.id}/issues`, { kind: giving.kind, quantity: giving.quantity, given_on: giving.given_on || null, patient_id: giving.kind === 'given' ? (giving.patient?.id ?? null) : null, note: giving.note || null })
      setNotice(`${giving.quantity} of ${giving.sample.name} ${giving.kind === 'given' ? 'given out' : 'thrown away'}.`)
      setOpen(giving.sample.id)
      setGiving(null)
      void load()
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }
  const takeBack = async (issue: Issue) => {
    if (!window.confirm('Take this entry back? The samples will count as on the shelf again.')) return
    await api.delete(`/reps/samples/issues/${issue.id}`)
    void load()
  }

  const reps = companies.find((c) => String(c.id) === companyId)?.reps?.filter((r) => r.is_active) ?? []
  const expiryNote = (s: Sample) => {
    if (!s.expiry) return null
    const days = daysUntil(s.expiry)
    return { text: days < 0 ? `Expired ${longDate(s.expiry)}` : longDate(s.expiry), tone: days < 0 ? 'due' : days <= 30 ? 'soon' : 'muted' }
  }

  return (
    <>
      <div className="toolbar">
        <input className="search" type="search" placeholder="Find by sample or company" value={query} onChange={(e) => setQuery(e.target.value)} />
        <div className="tabs">
          <button className={show === 'in_hand' ? 'active' : ''} onClick={() => setShow('in_hand')}>
            On the shelf
          </button>
          <button className={show === 'all' ? 'active' : ''} onClick={() => setShow('all')}>
            All received
          </button>
        </div>
        <span className="grow" />
        <button className="btn primary" onClick={() => edit(null)}>
          + Samples received
        </button>
      </div>
      <p className="muted small">Samples are not for sale. They are kept apart from the pharmacy’s stock and never go on a bill.</p>
      {notice && <p className="notice">{notice}</p>}

      {draft && (
        <form className="catalogue-form" onSubmit={save} noValidate>
          <h3>{editing ? 'Change the entry' : 'Samples received'}</h3>
          <div className="grid three">
            <SelectField label="Company" required value={draft.rep_company_id} onChange={set('rep_company_id')} options={companies.map((c) => ({ value: String(c.id), label: c.name }))} placeholder="Select company" error={errors.rep_company_id} />
            <SelectField label="Left by" value={draft.medical_rep_id} onChange={set('medical_rep_id')} options={reps.map((r) => ({ value: String(r.id), label: r.name }))} placeholder="Not noted" error={errors.medical_rep_id} />
            <SelectField label="Left for" value={draft.doctor_id} onChange={set('doctor_id')} options={doctors.map((d) => ({ value: String(d.id), label: d.name }))} placeholder="The hospital, no one doctor" error={errors.doctor_id} />
            <label className="field">
              <span className="field-label">Sample *</span>
              <input list="sample-brands" value={draft.name} onChange={(e) => set('name')(e.target.value)} placeholder="Brand name, as on the pack" aria-invalid={Boolean(errors.name)} />
              <datalist id="sample-brands">
                {brands.map((b) => (
                  <option key={b} value={b} />
                ))}
              </datalist>
              {errors.name && <span className="field-error">{errors.name}</span>}
            </label>
            <TextField label="How many" required type="number" min={1} value={draft.quantity} onChange={set('quantity')} error={errors.quantity} />
            <TextField label="Counted in" value={draft.unit} onChange={set('unit')} placeholder="strips, tablets, bottles…" error={errors.unit} />
            <TextField label="Expiry" type="date" value={draft.expiry} onChange={set('expiry')} error={errors.expiry} />
            <TextField label="Received on" required type="date" max={today()} value={draft.received_on} onChange={set('received_on')} error={errors.received_on} />
            <TextField label="Notes" value={draft.notes} onChange={set('notes')} error={errors.notes} />
          </div>
          {(errors._ ?? errors.sample) && <p className="form-error">{errors._ ?? errors.sample}</p>}
          <div className="panel-foot">
            {editing !== null && (
              <button type="button" className="link danger" onClick={() => { const s = samples?.find((x) => x.id === editing); if (s) void remove(s) }}>
                Entered by mistake: take it off
              </button>
            )}
            <span className="grow" />
            <button type="button" className="btn ghost" onClick={() => { setDraft(null); setEditing(null) }}>
              Close
            </button>
            <button className="btn primary" disabled={saving || !draft.rep_company_id || draft.name.trim().length < 2 || !(Number(draft.quantity) > 0)}>
              {saving ? 'Saving…' : editing ? 'Save' : 'Enter in the register'}
            </button>
          </div>
        </form>
      )}

      {giving && (
        <form className="catalogue-form" onSubmit={give} noValidate>
          <h3>
            {giving.kind === 'given' ? 'Give out' : 'Throw away'} {giving.sample.name} <small className="muted">· {giving.sample.in_hand} {giving.sample.unit ?? ''} on the shelf</small>
          </h3>
          <div className="grid three">
            <TextField label="How many" required type="number" min={1} max={giving.sample.in_hand} value={giving.quantity} onChange={(v) => setGiving({ ...giving, quantity: v })} error={errors.quantity} />
            <TextField label="On" type="date" min={giving.sample.received_on} max={today()} value={giving.given_on} onChange={(v) => setGiving({ ...giving, given_on: v })} error={errors.given_on} />
            <TextField label={giving.kind === 'given' ? 'Note (optional)' : 'Why'} value={giving.note} onChange={(v) => setGiving({ ...giving, note: v })} placeholder={giving.kind === 'given' ? 'To whom, if not a patient' : 'Expired, damaged…'} error={errors.note} />
          </div>
          {giving.kind === 'given' && (
            <div className="gap-top">
              <span className="field-label">Given to a patient (optional)</span>
              <PatientPicker patient={giving.patient} onChange={(patient) => setGiving({ ...giving, patient })} error={errors.patient_id} />
            </div>
          )}
          {(errors._ ?? errors.kind) && <p className="form-error">{errors._ ?? errors.kind}</p>}
          <div className="panel-foot">
            <button type="button" className="btn ghost" onClick={() => setGiving(null)}>
              Close
            </button>
            <button className="btn primary" disabled={saving || !(Number(giving.quantity) > 0)}>
              {saving ? 'Saving…' : giving.kind === 'given' ? 'Give out' : 'Throw away'}
            </button>
          </div>
        </form>
      )}

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Sample</th>
              <th>Company</th>
              <th>Received</th>
              <th className="num">Came in</th>
              <th className="num">Given out</th>
              <th className="num">On the shelf</th>
              <th>Expiry</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {samples?.map((s) => {
              const expiry = expiryNote(s)
              return (
                <Fragment key={s.id}>
                  <tr className="static">
                    <td>
                      <button type="button" className="link" onClick={() => edit(s)} title="Change this entry">
                        {s.name}
                      </button>
                      <small className="muted block">{[s.unit ? `in ${s.unit}` : null, s.doctor ? `for ${s.doctor.name}` : null].filter(Boolean).join(' · ')}</small>
                    </td>
                    <td>
                      {s.company?.name}
                      {s.rep && <small className="muted block">{s.rep.name}</small>}
                    </td>
                    <td>{longDate(s.received_on)}</td>
                    <td className="num">{s.quantity}</td>
                    <td className="num">
                      {s.given}
                      {s.discarded > 0 && <small className="muted block">{s.discarded} thrown away</small>}
                    </td>
                    <td className="num strong">{s.in_hand}</td>
                    <td>{expiry && <span className={expiry.tone}>{expiry.text}</span>}</td>
                    <td className="num">
                      {s.in_hand > 0 && (
                        <>
                          <button className="btn ghost small-btn" onClick={() => { setErrors({}); setGiving({ sample: s, kind: 'given', quantity: '1', given_on: today(), note: '', patient: null }) }}>
                            Give out
                          </button>{' '}
                          <button className="link danger" onClick={() => { setErrors({}); setGiving({ sample: s, kind: 'discarded', quantity: String(s.in_hand), given_on: today(), note: '', patient: null }) }}>
                            Throw away
                          </button>
                        </>
                      )}
                      {s.issues.length > 0 && (
                        <button className="link sample-went" onClick={() => setOpen(open === s.id ? null : s.id)}>
                          {open === s.id ? 'Hide' : 'Where it went'}
                        </button>
                      )}
                    </td>
                  </tr>
                  {open === s.id && (
                    <tr className="static sub">
                      <td colSpan={8}>
                        <ul className="history">
                          {s.issues.map((i) => (
                            <li key={i.id}>
                              <span>
                                {i.quantity} {i.kind === 'given' ? 'given' : 'thrown away'} on {longDate(i.given_on)}
                                {i.patient ? ` to ${i.patient.name} (${i.patient.uhid})` : ''}
                                {i.note ? ` · ${i.note}` : ''}
                                {i.giver && <small className="muted"> · by {i.giver.name}</small>}
                              </span>
                              <button type="button" className="link" onClick={() => void takeBack(i)}>
                                Entered by mistake
                              </button>
                            </li>
                          ))}
                        </ul>
                      </td>
                    </tr>
                  )}
                </Fragment>
              )
            })}
            {samples && samples.length === 0 && (
              <tr className="empty static">
                <td colSpan={8}>{query ? 'No sample matches.' : show === 'in_hand' ? 'No samples are on the shelf.' : 'No samples have been entered yet.'}</td>
              </tr>
            )}
            {!samples && (
              <tr className="empty static">
                <td colSpan={8}>Loading…</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </>
  )
}
