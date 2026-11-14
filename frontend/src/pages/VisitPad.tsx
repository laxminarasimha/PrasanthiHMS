import { Fragment, useEffect, useRef, useState, type ReactNode } from 'react'
import { canLeave, setUnsaved } from '../unsaved'
import { PatientDocuments } from '../components/PatientDocuments'
import { IcdPicker, type IcdCode } from '../components/IcdPicker'
import { HealthSummary } from '../components/HealthSummary'
import { Link, useParams } from 'react-router-dom'
import { api, fieldErrors, type FieldErrors } from '../api'
import { useAuth } from '../auth'
import { Field, TextField } from '../components/Field'
import { FormsPanel } from '../components/FormsPanel'
import { MedicineRows, emptyMedicine, filledMedicines } from '../components/MedicineRows'
import { PhraseChips } from '../components/PhraseChips'
import { ReferralList } from '../components/ReferralList'
import { clock, longDate, rupees, stampDate, stampTime, today } from '../format'
import type { Consultation, DoctorLibrary, LabCatalogueTest, LabOrder, PhraseKind, PrescribedMedicine as Medicine, PrescriptionTemplate, RxGroup, Visit } from '../types'

const VITALS = [
  { key: 'weight_kg', label: 'Weight (kg)' },
  { key: 'height_cm', label: 'Height (cm)' },
  { key: 'bp_systolic', label: 'BP systolic' },
  { key: 'bp_diastolic', label: 'BP diastolic' },
  { key: 'pulse', label: 'Pulse (/min)' },
  { key: 'temperature_f', label: 'Temp (°F)' },
  { key: 'spo2', label: 'SpO₂ (%)' },
  { key: 'resp_rate', label: 'Resp. rate (/min)' },
] as const

/** The standard order of the pad, used until the doctor's own order has loaded. */
const PAD_ORDER = ['quick_notes', 'vitals', 'complaints', 'history', 'past_medication', 'general_exam', 'systemic_exam', 'diagnosis', 'assessment_plan', 'tests', 'results', 'prescription', 'advice', 'follow_up']

/** The general examination checklist, in the order doctors recite it. */
const SIGNS = [
  { key: 'pallor', label: 'Pallor' },
  { key: 'icterus', label: 'Icterus' },
  { key: 'cyanosis', label: 'Cyanosis' },
  { key: 'clubbing', label: 'Clubbing' },
  { key: 'lymphadenopathy', label: 'Lymphadenopathy' },
  { key: 'edema', label: 'Edema' },
] as const

const TEXT_KEYS = [
  'quick_notes', 'complaints', 'history', 'past_medication', 'general_exam_notes', 'findings', 'diagnosis',
  'assessment_plan', 'tests_advised', 'advice', 'refer_to', 'follow_up_on',
]

type Exam = Record<string, 'present' | 'absent' | ''>

/** The parts of a visit a template can fill in. */
const TEMPLATE_KEYS = ['complaints', 'findings', 'diagnosis', 'assessment_plan', 'tests_advised', 'advice'] as const

type Form = Record<string, string>

function toForm(c: Consultation | null, allergies: string | null = null): Form {
  const form: Form = { allergies: allergies ?? '' }
  for (const key of [...VITALS.map((v) => v.key), ...TEXT_KEYS]) {
    const value = c?.[key as keyof Consultation]
    form[key] = value === null || value === undefined ? '' : String(value)
  }
  return form
}

const toExam = (c: Consultation | null): Exam => Object.fromEntries(SIGNS.map((sign) => [sign.key, c?.general_exam?.[sign.key] ?? '']))

export function VisitPad() {
  const { id } = useParams()
  const { user } = useAuth()
  const [visit, setVisit] = useState<Visit | null>(null)
  const [form, setForm] = useState<Form>(toForm(null))
  const [medicines, setMedicines] = useState<Medicine[]>([emptyMedicine()])
  const [amendment, setAmendment] = useState('')
  const [baseline, setBaseline] = useState(0)
  const [savedPad, setSavedPad] = useState<string | null>(null)
  const [codes, setCodes] = useState<IcdCode[]>([])
  const [warnings, setWarnings] = useState<{ kind: string; medicine: string; message: string }[]>([])
  const [exam, setExam] = useState<Exam>(toExam(null))
  const [investigations, setInvestigations] = useState<{ name: string; result: string; date: string }[]>([])
  const [suggestions, setSuggestions] = useState<string[]>([])
  const [history, setHistory] = useState<Consultation[]>([])
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState('')
  const [labOrders, setLabOrders] = useState<LabOrder[]>([])
  const [labTests, setLabTests] = useState<LabCatalogueTest[]>([])
  const [picking, setPicking] = useState(false)
  const [chosenTests, setChosenTests] = useState<number[]>([])
  const [testFilter, setTestFilter] = useState('')
  const [library, setLibrary] = useState<DoctorLibrary | null>(null)
  const [naming, setNaming] = useState<{ what: 'group' | 'template'; name: string } | null>(null)

  const apply = (next: Visit) => {
    setVisit(next)
    setForm(toForm(next.consultation, next.patient.allergies))
    setExam(toExam(next.consultation))
    setInvestigations((next.consultation?.investigations ?? []).map((row) => ({ name: row.name, result: row.result ?? '', date: row.date ?? '' })))
    setMedicines(next.consultation?.medicines.length ? next.consultation.medicines : [emptyMedicine()])
    setCodes(next.consultation?.diagnosis_codes ?? [])
    setBaseline((n) => n + 1)
  }

  useEffect(() => {
    api.get<Visit>(`/appointments/${id}/consultation`).then((res) => {
      apply(res.data)
      api.get<Consultation[]>(`/patients/${res.data.patient.id}/consultations`).then((h) => setHistory(h.data))
      api.get<LabOrder[]>('/lab/orders', { params: { patient_id: res.data.patient.id } }).then((l) => setLabOrders(l.data))
      // The shortcuts belong to the doctor seeing the patient, whoever is typing.
      api.get<DoctorLibrary>('/doctor/library', { params: { doctor_id: res.data.doctor.id } }).then((l) => setLibrary(l.data)).catch(() => setLibrary(null))
    })
    api.get<LabCatalogueTest[]>('/lab/tests').then((res) => setLabTests(res.data))
    api.get<string[]>('/medicines/suggest').then((res) => setSuggestions(res.data))
  }, [id])

  // Notes typed since the pad was last loaded or saved. Leaving the page with these unsaved asks first.
  const pad = JSON.stringify([form, exam, medicines.filter((m) => m.medicine.trim()), investigations, codes])
  const latestPad = useRef(pad)
  latestPad.current = pad
  useEffect(() => {
    // Only when the pad is loaded or saved afresh: that is the point to measure changes from.
    if (baseline > 0) setSavedPad(latestPad.current)
  }, [baseline])
  const unsaved = savedPad !== null && pad !== savedPad && Boolean(user?.can_consult) && !visit?.locked
  useEffect(() => {
    setUnsaved(unsaved ? 'This visit has notes that are not saved yet. Leave without saving them?' : null)
    return () => setUnsaved(null)
  }, [unsaved])

  // A second pair of eyes on the prescription: allergies and the same medicine twice.
  const names = medicines.map((m) => m.medicine.trim()).filter(Boolean).join('|')
  const allergyText = String(form.allergies ?? '')
  const patientId = visit?.patient.id
  const checkable = Boolean(user?.can_consult) && Boolean(visit) && !visit?.locked
  useEffect(() => {
    if (!patientId || !checkable) return
    if (!names) {
      setWarnings([])
      return
    }
    let current = true
    const timer = setTimeout(() => {
      api.post<{ warnings: { kind: string; medicine: string; message: string }[] }>(`/patients/${patientId}/prescription-check`, { medicines: names.split('|'), allergies: allergyText || null })
        .then((res) => current && setWarnings(res.data.warnings))
        .catch(() => undefined)
    }, 600)
    return () => {
      current = false
      clearTimeout(timer)
    }
  }, [names, allergyText, patientId, checkable])

  if (!visit) return <p className="loading">Loading visit…</p>

  const canEdit = Boolean(user?.can_consult) && visit.appointment.status !== 'cancelled'
  const completed = Boolean(visit.consultation?.completed_at)
  const locked = Boolean(visit.locked)
  // The notes themselves can be changed only until the visit locks; forms and the health summary stay open.
  const canWrite = canEdit && !locked
  const set = (key: string) => (value: string) => setForm((f) => ({ ...f, [key]: value }))
  const reloadLibrary = () => api.get<DoctorLibrary>('/doctor/library', { params: { doctor_id: visit.doctor.id } }).then((l) => setLibrary(l.data))
  const phrases = (kind: PhraseKind) => (library && !Array.isArray(library.phrases) ? (library.phrases[kind] ?? []) : [])
  /** A part of the pad the doctor has switched off, unless this visit already has something in it. */
  const hidden = (section: string, ...keys: string[]) => Boolean(library?.pad_hidden_sections.includes(section)) && keys.every((key) => !form[key])
  const setInvestigation = (index: number, key: 'name' | 'result' | 'date', value: string) => setInvestigations((rows) => rows.map((row, i) => (i === index ? { ...row, [key]: value } : row)))
  const filled = () => medicines.filter((m) => m.medicine.trim())
  const addMedicines = (rows: Medicine[]) => setMedicines([...filled(), ...rows.map((m) => ({ ...emptyMedicine(), ...m }))])

  const applyTemplate = (template: PrescriptionTemplate) => {
    // Only the parts the template has are replaced; anything else already typed stays.
    setForm((f) => ({ ...f, ...Object.fromEntries(TEMPLATE_KEYS.filter((key) => template.content[key]).map((key) => [key, template.content[key] as string])) }))
    if (template.content.medicines?.length) addMedicines(template.content.medicines)
    setNotice(`Template "${template.name}" applied. Check it before completing the visit.`)
  }

  const saveNamed = async () => {
    if (!naming?.name.trim()) return
    setErrors({})
    try {
      if (naming.what === 'group') {
        await api.post<RxGroup>('/doctor/rx-groups', { doctor_id: visit.doctor.id, name: naming.name, medicines: filledMedicines(medicines) })
      } else {
        await api.post<PrescriptionTemplate>('/doctor/templates', {
          doctor_id: visit.doctor.id,
          name: naming.name,
          content: { ...Object.fromEntries(TEMPLATE_KEYS.map((key) => [key, form[key] || null])), medicines: filledMedicines(medicines) },
        })
      }
      setNotice(`${naming.what === 'group' ? 'Rx group' : 'Template'} "${naming.name}" saved to the library.`)
      setNaming(null)
      reloadLibrary()
    } catch (err) {
      setErrors(fieldErrors(err))
    }
  }

  const save = async (complete: boolean) => {
    setSaving(true)
    setErrors({})
    setNotice('')
    const payload: Record<string, unknown> = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]))
    payload.general_exam = Object.fromEntries(SIGNS.map((sign) => [sign.key, exam[sign.key] || null]))
    payload.medicines = filledMedicines(medicines)
    payload.diagnosis_codes = codes.map((c) => ({ code: c.code }))
    payload.investigations = investigations.filter((row) => row.name.trim() || row.result.trim()).map((row) => ({ name: row.name.trim(), result: row.result.trim() || null, date: row.date || null }))
    if (complete) payload.complete = true
    try {
      const res = await api.put<Visit>(`/appointments/${id}/consultation`, payload)
      apply(res.data)
      setNotice(complete ? 'Visit completed. You can print the prescription now.' : 'Draft saved.')
      if (complete) {
        api.get<Consultation[]>(`/patients/${res.data.patient.id}/consultations`).then((h) => setHistory(h.data))
        reloadLibrary()
      }
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  const orderTests = async () => {
    setErrors({})
    try {
      const res = await api.post<LabOrder>('/lab/orders', {
        patient_id: visit.patient.id,
        appointment_id: visit.appointment.id,
        referred_by: visit.doctor.name,
        test_ids: chosenTests,
      })
      setNotice(`Lab order ${res.data.order_no} sent to the lab. The patient pays at the lab or front desk.`)
      setPicking(false)
      setChosenTests([])
      api.get<LabOrder[]>('/lab/orders', { params: { patient_id: visit.patient.id } }).then((l) => setLabOrders(l.data))
    } catch (err) {
      setErrors(fieldErrors(err))
    }
  }

  const height = Number(form.height_cm) / 100
  const bmi = height > 0 && Number(form.weight_kg) > 0 ? (Number(form.weight_kg) / (height * height)).toFixed(1) : null

  const visibleTests = labTests.filter((t) => t.name.toLowerCase().includes(testFilter.trim().toLowerCase()))
  const errorList = Object.entries(errors)
  const pastVisits = history.filter((h) => h.id !== visit.consultation?.id)

  // Each movable part of the pad, so it can be laid out in the order this doctor prefers.
  const sections: Record<string, ReactNode> = {
    quick_notes: (
          <>
          {!hidden('quick_notes', 'quick_notes') && (
            <Field label="Quick notes (for you only; not printed)" error={errors.quick_notes}>
              <textarea rows={2} placeholder="" value={form.quick_notes} onChange={(e) => set('quick_notes')(e.target.value)} />
            </Field>
          )}
          </>
    ),
    vitals: (
          <>
          <h3>Vitals</h3>
          <div className="vitals">
            {VITALS.map((v) => (
              <TextField key={v.key} label={v.label} type="number" step="any" value={form[v.key]} onChange={set(v.key)} error={errors[v.key]} />
            ))}
            <label className="field">
              <span className="field-label">BMI</span>
              <output className="bmi">{bmi ?? '—'}</output>
            </label>
          </div>
          </>
    ),
    complaints: (
          <>
          <Field label="Complaints" error={errors.complaints}>
            <textarea rows={2} placeholder="What the patient reports, and for how long" value={form.complaints} onChange={(e) => set('complaints')(e.target.value)} />
          </Field>
          <PhraseChips phrases={phrases('complaint')} text={form.complaints} onPick={set('complaints')} />
          </>
    ),
    history: (
          <>
          {!hidden('history', 'history') && (
            <Field label="History" error={errors.history}>
              <textarea rows={2} placeholder="Past illnesses, surgeries, family and personal history" value={form.history} onChange={(e) => set('history')(e.target.value)} />
            </Field>
          )}
          </>
    ),
    past_medication: (
          <>
          {!hidden('past_medication', 'past_medication') && (
            <Field label="Past medication" error={errors.past_medication}>
              <textarea rows={2} placeholder="Medicines the patient is already taking" value={form.past_medication} onChange={(e) => set('past_medication')(e.target.value)} />
            </Field>
          )}
          </>
    ),
    general_exam: (
          <>
          {!(hidden('general_exam', 'general_exam_notes') && SIGNS.every((sign) => !exam[sign.key])) && (
          <>
          <h3>General examination</h3>
          <div className="signs">
            {SIGNS.map((sign) => (
              <div key={sign.key} className="sign">
                <span>{sign.label}</span>
                {(['present', 'absent'] as const).map((value) => (
                  <button
                    key={value}
                    type="button"
                    className={`chip ${exam[sign.key] === value ? value : ''}`}
                    aria-pressed={exam[sign.key] === value}
                    onClick={() => setExam((current) => ({ ...current, [sign.key]: current[sign.key] === value ? '' : value }))}
                  >
                    {value === 'present' ? 'Present' : 'Absent'}
                  </button>
                ))}
              </div>
            ))}
          </div>
          <Field label="General examination notes" error={errors.general_exam_notes}>
            <textarea rows={2} placeholder="" value={form.general_exam_notes} onChange={(e) => set('general_exam_notes')(e.target.value)} />
          </Field>
          </>
          )}
          </>
    ),
    systemic_exam: (
          <>
          {!hidden('systemic_exam', 'findings') && (
            <Field label="Systemic examination" error={errors.findings}>
              <textarea rows={2} placeholder="CVS, RS, P/A, CNS" value={form.findings} onChange={(e) => set('findings')(e.target.value)} />
            </Field>
          )}
          </>
    ),
    diagnosis: (
          <>
          <Field label="Diagnosis" error={errors.diagnosis}>
            <textarea rows={2} placeholder="" value={form.diagnosis} onChange={(e) => set('diagnosis')(e.target.value)} />
          </Field>
          <PhraseChips phrases={phrases('diagnosis')} text={form.diagnosis} onPick={set('diagnosis')} />
          <IcdPicker value={codes} onChange={setCodes} disabled={!canWrite} />
          </>
    ),
    assessment_plan: (
          <>
          {!hidden('assessment_plan', 'assessment_plan') && (
            <Field label="Assessment / plan" error={errors.assessment_plan}>
              <textarea rows={2} placeholder="" value={form.assessment_plan} onChange={(e) => set('assessment_plan')(e.target.value)} />
            </Field>
          )}
          </>
    ),
    tests: (
          <>
          {!hidden('tests', 'tests_advised') && (
          <>
          <h3>Tests prescribed</h3>
          {picking ? (
            <div className="lab-pick">
              <input type="search" placeholder="Filter tests" value={testFilter} onChange={(e) => setTestFilter(e.target.value)} aria-label="Filter lab tests" />
              <ul className="checklist short">
                {visibleTests.map((t) => (
                  <li key={t.id}>
                    <label>
                      <input type="checkbox" checked={chosenTests.includes(t.id)} onChange={() => setChosenTests((ids) => (ids.includes(t.id) ? ids.filter((i) => i !== t.id) : [...ids, t.id]))} />
                      <span className="grow">{t.name}</span>
                      <span className="mono">{rupees(t.price)}</span>
                    </label>
                  </li>
                ))}
              </ul>
              <div className="row gap-top">
                <button type="button" className="btn primary" disabled={chosenTests.length === 0} onClick={orderTests}>
                  Send {chosenTests.length || ''} test{chosenTests.length === 1 ? '' : 's'} to lab
                </button>
                <button type="button" className="btn ghost" onClick={() => setPicking(false)}>
                  Cancel
                </button>
              </div>
            </div>
          ) : (
            <button type="button" className="link" onClick={() => setPicking(true)}>
              + Order lab tests
            </button>
          )}
          <Field label="Other tests advised (done outside)" error={errors.tests_advised}>
            <textarea rows={2} placeholder="One test per line" value={form.tests_advised} onChange={(e) => set('tests_advised')(e.target.value)} />
          </Field>

          </>
          )}
          </>
    ),
    results: (
          <>
          {!(library?.pad_hidden_sections.includes('results') && investigations.every((row) => !row.name.trim())) && (
          <>
          <h3>Test results</h3>
          <p className="muted small">Type in results from reports the patient has brought. Results from our own lab show in the panel beside the pad.</p>
          {investigations.map((row, i) => (
            <div className="inv-row" key={i}>
              <input list="inv-tests" placeholder="Test" value={row.name} onChange={(e) => setInvestigation(i, 'name', e.target.value)} aria-label={`Test ${i + 1}`} aria-invalid={Boolean(errors[`investigations.${i}.name`])} />
              <input placeholder="Result" value={row.result} onChange={(e) => setInvestigation(i, 'result', e.target.value)} aria-label={`Result ${i + 1}`} />
              <input type="date" max={today()} value={row.date} onChange={(e) => setInvestigation(i, 'date', e.target.value)} aria-label={`Date of test ${i + 1}`} aria-invalid={Boolean(errors[`investigations.${i}.date`])} />
              <button type="button" className="link danger" onClick={() => setInvestigations((rows) => rows.filter((_, r) => r !== i))}>
                Remove
              </button>
            </div>
          ))}
          <datalist id="inv-tests">
            {labTests.map((t) => (
              <option key={t.id} value={t.name} />
            ))}
          </datalist>
          <button type="button" className="link" onClick={() => setInvestigations((rows) => [...rows, { name: '', result: '', date: '' }])}>
            + Add a result
          </button>
          </>
          )}
          </>
    ),
    prescription: (
          <>
          <h3>Prescription</h3>
          {library && (
            <div className="pad-tools">
              <select
                value=""
                disabled={library.rx_groups.length === 0}
                onChange={(e) => {
                  const group = library.rx_groups.find((g) => g.id === Number(e.target.value))
                  if (group) addMedicines(group.medicines)
                }}
                aria-label="Add an Rx group"
              >
                <option value="">{library.rx_groups.length ? 'Add an Rx group…' : 'No Rx groups saved yet'}</option>
                {library.rx_groups.map((g) => (
                  <option key={g.id} value={g.id}>
                    {g.name} ({g.medicines.length})
                  </option>
                ))}
              </select>
              {naming?.what === 'group' ? (
                <>
                  <input autoFocus placeholder="Group name, e.g. Fever basic" value={naming.name} onChange={(e) => setNaming({ what: 'group', name: e.target.value })} onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), saveNamed())} aria-label="Rx group name" />
                  <button type="button" className="btn primary small-btn" onClick={saveNamed}>
                    Save
                  </button>
                  <button type="button" className="btn ghost small-btn" onClick={() => setNaming(null)}>
                    Cancel
                  </button>
                </>
              ) : (
                filled().length > 0 && (
                  <button type="button" className="link" onClick={() => setNaming({ what: 'group', name: '' })}>
                    Save these medicines as an Rx group
                  </button>
                )
              )}
            </div>
          )}
          <MedicineRows rows={medicines} onChange={setMedicines} names={suggestions} defaults={library?.medicine_defaults} frequencies={library?.rx_settings.frequencies} timings={library?.rx_settings.timings} invalid={(i) => Boolean(errors[`medicines.${i}.medicine`])} />
          {warnings.length > 0 && (
            <div className="rx-warnings" role="alert">
              {warnings.map((w, i) => (
                <p key={i} className={w.kind}>
                  <b>{w.kind === 'allergy' ? 'Allergy' : 'Repeated'}:</b> {w.message}
                </p>
              ))}
              <small className="muted">A basic check of names only. It does not know doses or how different medicines act on each other.</small>
            </div>
          )}
          </>
    ),
    advice: (
          <>
          <Field label="Advice" error={errors.advice}>
            <textarea rows={2} placeholder="Diet, rest, warning signs" value={form.advice} onChange={(e) => set('advice')(e.target.value)} />
          </Field>
          <PhraseChips phrases={phrases('advice')} text={form.advice} onPick={set('advice')} />
          </>
    ),
    follow_up: (
          <>
          <ReferralList id="refer-to-doctors" direction="to" />
          <div className="grid gap-top">
            {!hidden('refer_to', 'refer_to') && <TextField label="Refer to" placeholder="Doctor or hospital, and why" list="refer-to-doctors" value={form.refer_to} onChange={set('refer_to')} error={errors.refer_to} />}
            <TextField label="Next visit date" type="date" value={form.follow_up_on} onChange={set('follow_up_on')} error={errors.follow_up_on} />
          </div>
          </>
    ),
  }

  return (
    <div className="split main-first">
      <section className="card">
        <div className="toolbar">
          <Link className="btn ghost" to="/doctor" onClick={(e) => !canLeave() && e.preventDefault()}>
            ← Queue
          </Link>
          <div className="grow">
            <h1>{visit.patient.name}</h1>
            <span className="muted">
              {visit.patient.uhid} · {visit.patient.age_label} / {visit.patient.gender.charAt(0).toUpperCase()} · {visit.patient.mobile}
              {visit.patient.blood_group ? ` · ${visit.patient.blood_group}` : ''}
              {visit.patient.allergies ? <strong className="due"> · Allergic to {visit.patient.allergies}</strong> : null} · {visit.service?.name ?? 'Visit'} with {visit.doctor.name},{' '}
              {longDate(visit.appointment.scheduled_at)} {clock(visit.appointment.scheduled_at)}
            </span>
          </div>
          {completed && <span className="pill ok">Completed</span>}
          {visit.consultation && (
            <a className="btn ghost" href={`/visits/${visit.appointment.id}/print`} target="_blank" rel="noreferrer">
              Print prescription
            </a>
          )}
        </div>

        {notice && <p className="notice">{notice}</p>}
        {errorList.length > 0 && (
          <div className="form-error">
            {errorList.map(([field, message]) => (
              <div key={field}>{message}</div>
            ))}
          </div>
        )}

        {locked && (
          <p className="notice lock-note">
            This visit was finished on {longDate(visit.consultation!.completed_at!)} and is locked, so its notes cannot be changed. To correct or add something, write an amendment below; it is kept with the visit, with your name and the date.
          </p>
        )}
        <fieldset disabled={!canWrite}>
          <div className={`allergy ${form.allergies.trim() ? 'has' : ''}`}>
            <TextField label="Allergies (kept on the patient's record)" placeholder="None known" value={form.allergies} onChange={set('allergies')} error={errors.allergies} />
          </div>
          {library && (
            <div className="pad-tools">
              <select
                value=""
                disabled={library.templates.length === 0}
                onChange={(e) => {
                  const template = library.templates.find((t) => t.id === Number(e.target.value))
                  if (template) applyTemplate(template)
                }}
                aria-label="Apply a template"
              >
                <option value="">{library.templates.length ? 'Apply a template…' : 'No templates saved yet'}</option>
                {library.templates.map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.name}
                  </option>
                ))}
              </select>
              {naming?.what === 'template' ? (
                <>
                  <input autoFocus placeholder="Template name, e.g. Viral fever" value={naming.name} onChange={(e) => setNaming({ what: 'template', name: e.target.value })} onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), saveNamed())} aria-label="Template name" />
                  <button type="button" className="btn primary small-btn" onClick={saveNamed}>
                    Save
                  </button>
                  <button type="button" className="btn ghost small-btn" onClick={() => setNaming(null)}>
                    Cancel
                  </button>
                </>
              ) : (
                <button type="button" className="link" onClick={() => setNaming({ what: 'template', name: '' })}>
                  Save this visit as a template
                </button>
              )}
            </div>
          )}
          {(library?.pad_section_order ?? PAD_ORDER).map((key) => (
            <Fragment key={key}>{sections[key]}</Fragment>
          ))}
        </fieldset>

        {canWrite ? (
          <div className="panel-foot row">
            <button className="btn ghost" disabled={saving} onClick={() => save(false)}>
              {completed ? 'Save changes' : 'Save draft'}
            </button>
            {!completed && (
              <button className="btn primary" disabled={saving} onClick={() => save(true)}>
                Complete visit
              </button>
            )}
          </div>
        ) : (
          !locked && <p className="muted gap-top">You can view this visit but not change it.</p>
        )}

        {completed && ((visit.consultation?.amendments?.length ?? 0) > 0 || (locked && canEdit)) && (
          <div className="amendments">
            <h3>Amendments</h3>
            {visit.consultation?.amendments?.map((a) => (
              <div key={a.id} className="amendment">
                <small className="muted">
                  {stampDate(a.created_at)} {stampTime(a.created_at)} · {a.author?.name ?? 'Staff'}
                </small>
                <p>{a.body}</p>
              </div>
            ))}
            {locked && canEdit && (
              <form
                onSubmit={async (e) => {
                  e.preventDefault()
                  setErrors({})
                  try {
                    apply((await api.post<Visit>(`/appointments/${id}/consultation/amendments`, { body: amendment })).data)
                    setAmendment('')
                    setNotice('Amendment added.')
                  } catch (err) {
                    setErrors(fieldErrors(err))
                  }
                }}
              >
                <textarea rows={3} maxLength={5000} placeholder="What needs correcting or adding, e.g. a result that came later" value={amendment} onChange={(e) => setAmendment(e.target.value)} aria-label="Amendment" />
                {errors.body && <p className="form-error">{errors.body}</p>}
                <div className="panel-foot">
                  <button className="btn" disabled={!amendment.trim()}>
                    Add amendment
                  </button>
                </div>
              </form>
            )}
          </div>
        )}

        <h3>Certificates and letters</h3>
        <PatientDocuments patient={visit.patient} canIssue={Boolean(user?.can_approve_ipd)} appointmentId={visit.appointment.id} />

        <h3>Forms</h3>
        <p className="muted small">Assessment sheets, consents and other forms designed under Admin → Forms. They are saved on their own, apart from the visit notes above.</p>
        <FormsPanel url={`/appointments/${visit.appointment.id}/forms`} use="doctor" canEdit={canEdit} />
      </section>

      <aside className="card panel">
        <h2>Health summary</h2>
        <HealthSummary
          patientId={visit.patient.id}
          canEdit={canEdit}
          hideAllergies
          onUseMedicines={(rows) => addMedicines(rows.map((r) => ({ ...emptyMedicine(), medicine: r.medicine, instructions: r.instructions ? r.instructions.slice(0, 255) : null })))}
        />

        <h2 className="gap-top">Lab results</h2>
        {labOrders.length === 0 && <p className="muted">No lab orders for this patient yet.</p>}
        {labOrders.map((o) => (
          <details key={o.id} className="past" open={o.status !== 'pending' && labOrders[0].id === o.id}>
            <summary>
              <strong>{longDate(o.ordered_at)}</strong>
              <span className="muted"> · {o.order_no} · </span>
              <span className={`pill ${o.status === 'completed' ? 'ok' : o.status === 'partial' ? 'status-ongoing' : 'status-arrived'}`}>
                {o.status === 'completed' ? 'Ready' : o.status === 'partial' ? 'Partly ready' : 'Pending'}
              </span>
            </summary>
            {o.items.map((item) => (
              <div key={item.id} className="lab-mini">
                <b>{item.test_name}</b>
                {item.reported_at ? (
                  <table className="compact">
                    <tbody>
                      {(item.results ?? []).map((r) => (
                        <tr key={r.id} className="static">
                          <td>{r.parameter}</td>
                          <td className={r.flag ? 'due strong' : ''}>
                            {r.value} {r.unit ?? ''} {r.flag === 'high' ? '↑' : r.flag === 'low' ? '↓' : ''}
                          </td>
                          <td className="muted">{r.reference_range ?? ''}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                ) : (
                  <span className="muted"> — awaiting result</span>
                )}
              </div>
            ))}
          </details>
        ))}

        <h2 className="gap-top">Past visits</h2>
        <a className="link" href={`/patients/${visit.patient.id}/history`} target="_blank" rel="noreferrer">
          Full case history and test trends ↗
        </a>
        {pastVisits.length === 0 && <p className="muted">No earlier visits recorded here.</p>}
        {pastVisits.map((h) => (
          <details key={h.id} className="past">
            <summary>
              <strong>{longDate(h.completed_at ?? '')}</strong>
              <span className="muted"> · {h.diagnosis?.split('\n')[0] || 'No diagnosis noted'}</span>
            </summary>
            {h.doctor && <p className="muted">{h.doctor.name}</p>}
            {h.complaints && <p><b>Complaints:</b> {h.complaints}</p>}
            {h.history && <p><b>History:</b> {h.history}</p>}
            {h.findings && <p><b>Examination:</b> {h.findings}</p>}
            {h.investigations?.length ? <p><b>Test results:</b> {h.investigations.map((r) => `${r.name}${r.result ? ` ${r.result}` : ''}`).join('; ')}</p> : null}
            {h.assessment_plan && <p><b>Plan:</b> {h.assessment_plan}</p>}
            {h.bp_systolic && <p><b>BP:</b> {h.bp_systolic}/{h.bp_diastolic}{h.weight_kg ? ` · ${h.weight_kg} kg` : ''}</p>}
            {h.medicines.length > 0 && (
              <ul>
                {h.medicines.map((m) => (
                  <li key={m.id}>
                    {m.medicine} {[m.dose, m.frequency, m.timing, m.duration].filter(Boolean).join(' · ')}
                  </li>
                ))}
              </ul>
            )}
            {h.diagnosis_codes?.length ? <p><b>Codes:</b> {h.diagnosis_codes.map((d) => `${d.code} ${d.name}`).join('; ')}</p> : null}
            {h.advice && <p><b>Advice:</b> {h.advice}</p>}
            {h.amendments?.map((a) => (
              <p key={a.id}>
                <b>Amendment, {stampDate(a.created_at)}:</b> {a.body}
              </p>
            ))}
            {h.medicines.length > 0 && canEdit && (
              <button type="button" className="link" onClick={() => setMedicines(h.medicines.map(({ id: _id, ...rest }) => rest))}>
                Repeat these medicines
              </button>
            )}
          </details>
        ))}
      </aside>
    </div>
  )
}
