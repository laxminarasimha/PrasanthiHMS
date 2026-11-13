import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { SetupTabs } from '../components/SetupTabs'
import { api, fieldErrors, type FieldErrors } from '../api'
import { TextField } from '../components/Field'
import { PAYMENT_MODES, longDate } from '../format'
import { AdminList } from './AdminLists'
import { FormDesigner } from './FormDesigner'
import type { BillingPreferences, Clinic, PatientMerge, ReferralDoctor } from '../types'

type Tab = 'clinic' | 'billing' | 'forms' | 'department' | 'payer' | 'vendor' | 'by' | 'to' | 'merge'
const TABS: [Tab, string][] = [
  ['clinic', 'Clinic details'],
  ['billing', 'Billing preferences'],
  ['forms', 'Forms'],
  ['department', 'Departments'],
  ['payer', 'Insurers and payers'],
  ['vendor', 'Vendors'],
  ['by', 'Doctors who refer to us'],
  ['to', 'Doctors we refer to'],
  ['merge', 'Merge patients'],
]

/** The hospital's name, address, logo and the lines printed on every document. */
function ClinicDetails() {
  const [form, setForm] = useState<Clinic | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [notice, setNotice] = useState('')

  useEffect(() => {
    api.get<Clinic>('/clinic').then((res) => setForm(res.data))
  }, [])
  if (!form) return <p className="loading">Loading…</p>

  const text = (key: Exclude<keyof Clinic, 'logo'>) => ({ value: form[key] ?? '', onChange: (value: string) => setForm({ ...form, [key]: value }), error: errors[key] })
  const done = (clinic: Clinic, message: string) => {
    setForm(clinic)
    setNotice(message)
    setErrors({})
    // The prints read the last copy seen; give them the new one straight away.
    try {
      localStorage.setItem('hms_clinic', JSON.stringify(clinic))
    } catch {
      // Not being able to store it only means the next print fetches it.
    }
  }
  const save = async (e: FormEvent) => {
    e.preventDefault()
    try {
      done((await api.put<Clinic>('/clinic', form)).data, 'Clinic details saved. They appear on every print.')
    } catch (err) {
      setErrors(fieldErrors(err))
    }
  }
  const logo = async (file?: File) => {
    try {
      if (file) {
        const body = new FormData()
        body.append('file', file)
        done((await api.post<Clinic>('/clinic/logo', body)).data, 'Logo saved.')
      } else {
        done((await api.delete<Clinic>('/clinic/logo')).data, 'Logo removed.')
      }
    } catch (err) {
      setErrors({ _: Object.values(fieldErrors(err))[0] })
    }
  }

  return (
    <form className="narrow" onSubmit={save} noValidate>
      {notice && <p className="notice">{notice}</p>}
      <p className="muted small">These are printed at the top of bills, prescriptions, lab reports, IPD bills and discharge summaries.</p>
      <div className="grid">
        <TextField label="Hospital name" required {...text('name')} />
        <TextField label="Line under the name (optional)" placeholder="e.g. Multi-speciality hospital" {...text('tagline')} />
        <div className="span-2">
          <TextField label="Address" {...text('address')} />
        </div>
        <TextField label="Phone" {...text('phone')} />
        <TextField label="Second phone" {...text('phone_alt')} />
        <TextField label="Email" type="email" {...text('email')} />
        <TextField label="Line at the foot of prints (optional)" placeholder="e.g. Get well soon" {...text('footer')} />
      </div>

      <h3>Logo</h3>
      <div className="signature-box">
        {form.logo ? <img src={form.logo} alt="Hospital logo" /> : <span className="muted small">No logo. The hospital name is printed on its own.</span>}
        <div className="row">
          <label className="btn ghost small-btn">
            {form.logo ? 'Replace' : 'Upload'}
            <input
              type="file"
              hidden
              accept="image/png,image/jpeg"
              onChange={(e) => {
                logo(e.target.files?.[0])
                e.target.value = ''
              }}
            />
          </label>
          {form.logo && (
            <button type="button" className="link danger" onClick={() => logo()}>
              Remove
            </button>
          )}
          <span className="muted small">PNG or JPG, up to 500 KB.</span>
        </div>
      </div>

      {errors._ && <p className="form-error">{errors._}</p>}
      <div className="panel-foot">
        <button className="btn primary">Save details</button>
      </div>
    </form>
  )
}

/** How the front desk bills a visit. */
function BillingPrefs() {
  const [form, setForm] = useState<BillingPreferences | null>(null)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')

  useEffect(() => {
    api.get<BillingPreferences>('/billing-preferences').then((res) => setForm(res.data))
  }, [])
  if (!form) return <p className="loading">Loading…</p>

  const save = async (e: FormEvent) => {
    e.preventDefault()
    try {
      const res = await api.put<BillingPreferences>('/billing-preferences', form)
      setForm(res.data)
      setNotice('Billing preferences saved.')
      setError('')
      try {
        localStorage.setItem('hms_billing', JSON.stringify(res.data))
      } catch {
        // The booking form fetches it again if it cannot be stored.
      }
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    }
  }

  return (
    <form className="narrow" onSubmit={save}>
      {notice && <p className="notice">{notice}</p>}
      <h3>Consultation billing</h3>
      {(
        [
          ['before', 'Pay before', 'The consultation is paid for when the visit is booked. The booking form offers "Paid in full".'],
          ['after', 'Pay after', 'The bill is made at booking and left unpaid; the patient pays after seeing the doctor.'],
        ] as const
      ).map(([value, label, hint]) => (
        <label className="check top" key={value}>
          <input type="radio" name="pay_timing" checked={form.pay_timing === value} onChange={() => setForm({ ...form, pay_timing: value })} />
          <span>
            {label}
            <small className="muted block">{hint}</small>
          </span>
        </label>
      ))}

      <h3>Usual payment mode</h3>
      <p className="muted small">Chosen to begin with when taking money at the front desk. It can be changed for each payment.</p>
      <select className="narrow" value={form.default_payment_mode} onChange={(e) => setForm({ ...form, default_payment_mode: e.target.value })} aria-label="Usual payment mode">
        {PAYMENT_MODES.map((m) => (
          <option key={m.value} value={m.value}>
            {m.label}
          </option>
        ))}
      </select>

      <h3>IPD case numbers</h3>
      <p className="muted small">The letters placed before the running number of each new IPD case, for example {form.ipd_prefix || 'IP'}00012. Cases already made keep their numbers.</p>
      <input className="narrow" maxLength={8} value={form.ipd_prefix ?? 'IP'} onChange={(e) => setForm({ ...form, ipd_prefix: e.target.value })} aria-label="IPD case number prefix" />

      <h3>IPD bill numbers</h3>
      <p className="muted small">The letters placed before the running number of each numbered IPD bill, for example {form.ipd_bill_prefix || 'IPB'}00012.</p>
      <input className="narrow" maxLength={8} value={form.ipd_bill_prefix ?? 'IPB'} onChange={(e) => setForm({ ...form, ipd_bill_prefix: e.target.value })} aria-label="IPD bill number prefix" />

      {error && <p className="form-error">{error}</p>}
      <div className="panel-foot">
        <button className="btn primary">Save preferences</button>
      </div>
    </form>
  )
}

const blankDoctor = { name: '', specialization: '', phone: '', place: '' }

/** Outside doctors in one direction: those who send patients here, or those patients are sent to. */
function ReferralDoctors({ direction }: { direction: 'by' | 'to' }) {
  const [doctors, setDoctors] = useState<ReferralDoctor[]>([])
  const [editing, setEditing] = useState<number | 'new' | null>(null)
  const [form, setForm] = useState(blankDoctor)
  const [errors, setErrors] = useState<FieldErrors>({})

  const load = useCallback(() => {
    api.get<ReferralDoctor[]>('/referral-doctors').then((res) => setDoctors(res.data))
  }, [])
  useEffect(load, [load])

  const open = (d: ReferralDoctor | null) => {
    setEditing(d?.id ?? 'new')
    setErrors({})
    setForm(d ? { name: d.name, specialization: d.specialization ?? '', phone: d.phone ?? '', place: d.place ?? '' } : blankDoctor)
  }
  const save = async (e: FormEvent) => {
    e.preventDefault()
    const body = { ...form, direction, specialization: form.specialization || null, phone: form.phone || null, place: form.place || null }
    try {
      await (editing === 'new' ? api.post('/referral-doctors', body) : api.put(`/referral-doctors/${editing}`, body))
      setEditing(null)
      load()
    } catch (err) {
      setErrors(fieldErrors(err))
    }
  }
  const remove = async (d: ReferralDoctor) => {
    if (!window.confirm(`Remove ${d.name} from the list?`)) return
    await api.delete(`/referral-doctors/${d.id}`)
    load()
  }
  const set = (key: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [key]: value }))
  const shown = doctors.filter((d) => d.direction === direction)

  return (
    <div>
      <div className="row gap-bottom">
        <p className="muted small grow">
          {direction === 'by'
            ? 'Suggested in the "Referred by" box when registering a patient or making a lab order.'
            : 'Suggested in the "Refer to" box on the visit pad.'}
        </p>
        <button className="btn primary" onClick={() => open(null)}>
          + Add doctor
        </button>
      </div>

      {editing && (
        <form className="note-form" onSubmit={save} noValidate>
          <div className="grid">
            <TextField label="Name" required value={form.name} onChange={set('name')} error={errors.name} autoFocus />
            <TextField label="Specialization" value={form.specialization} onChange={set('specialization')} error={errors.specialization} />
            <TextField label="Phone" inputMode="tel" value={form.phone} onChange={set('phone')} error={errors.phone} />
            <TextField label="Hospital or place" value={form.place} onChange={set('place')} error={errors.place} />
          </div>
          {errors._ && <p className="form-error">{errors._}</p>}
          <div className="panel-foot row">
            <button type="button" className="btn ghost" onClick={() => setEditing(null)}>
              Cancel
            </button>
            <button className="btn primary">Save</button>
          </div>
        </form>
      )}

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Specialization</th>
              <th>Phone</th>
              <th>Hospital or place</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {shown.map((d) => (
              <tr key={d.id} className="static">
                <td>
                  <strong>{d.name}</strong>
                </td>
                <td>{d.specialization ?? '—'}</td>
                <td className="mono">{d.phone ?? '—'}</td>
                <td>{d.place ?? '—'}</td>
                <td className="num actions">
                  <button className="link" onClick={() => open(d)}>
                    Edit
                  </button>
                  <button className="link danger" onClick={() => remove(d)}>
                    Remove
                  </button>
                </td>
              </tr>
            ))}
            {shown.length === 0 && (
              <tr className="empty">
                <td colSpan={5}>No doctors in this list yet.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}

/** Folds a duplicate registration into the right one, and shows what has been merged before. */
function MergePatients() {
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [log, setLog] = useState<PatientMerge[]>([])
  const [errors, setErrors] = useState<FieldErrors>({})
  const [notice, setNotice] = useState('')

  const load = useCallback(() => {
    api.get<PatientMerge[]>('/patient-merges').then((res) => setLog(res.data))
  }, [])
  useEffect(load, [load])

  const moved = (m: PatientMerge) => Object.entries(m.moved ?? {}).map(([what, count]) => `${count} ${what}`).join(', ') || 'nothing to move'

  const merge = async (e: FormEvent) => {
    e.preventDefault()
    setNotice('')
    if (!window.confirm(`Move everything from patient ${from.trim()} into patient ${to.trim()}? This cannot be undone here.`)) return
    try {
      const res = await api.post<PatientMerge>('/patient-merges', { from: from.trim(), to: to.trim() })
      setNotice(`${res.data.from_name} (${res.data.from_uhid}) was merged into ${res.data.to_name} (${res.data.to_uhid}): ${moved(res.data)}.`)
      setFrom('')
      setTo('')
      setErrors({})
      load()
    } catch (err) {
      setErrors(fieldErrors(err))
    }
  }

  return (
    <div>
      <p className="muted small">
        When one person was registered twice, merge the duplicate into the record you want to keep. Their visits, bills, lab orders, pharmacy bills, admissions and attachments all move across, and the duplicate stops appearing in
        searches. The two patient IDs are on the Patients page.
      </p>
      {notice && <p className="notice">{notice}</p>}
      <form className="grid three" onSubmit={merge} noValidate>
        <TextField label="Duplicate patient ID (merge from)" required value={from} onChange={setFrom} error={errors.from} />
        <TextField label="Patient ID to keep (merge into)" required value={to} onChange={setTo} error={errors.to} />
        <div className="field">
          <span className="field-label">&nbsp;</span>
          <button className="btn primary" disabled={!from.trim() || !to.trim()}>
            Merge
          </button>
        </div>
      </form>
      {errors._ && <p className="form-error">{errors._}</p>}

      <h3>Merged so far</h3>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Duplicate</th>
              <th>Merged into</th>
              <th>Moved</th>
              <th>By</th>
            </tr>
          </thead>
          <tbody>
            {log.map((m) => (
              <tr key={m.id} className="static">
                <td>{longDate(m.created_at)}</td>
                <td>
                  {m.from_name} <small className="muted mono">{m.from_uhid}</small>
                </td>
                <td>
                  {m.to_name} <small className="muted mono">{m.to_uhid}</small>
                </td>
                <td className="wrap">{moved(m)}</td>
                <td>{m.staff?.name ?? '—'}</td>
              </tr>
            ))}
            {log.length === 0 && (
              <tr className="empty">
                <td colSpan={5}>No patients have been merged.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}

export function Admin() {
  const [tab, setTab] = useState<Tab>('clinic')

  return (
    <>
    <SetupTabs />
    <section className="card">
      <div className="toolbar">
        <h1>Hospital setup</h1>
      </div>
      <div className="tabs flush gap-bottom">
        {TABS.map(([key, label]) => (
          <button key={key} className={tab === key ? 'active' : ''} onClick={() => setTab(key)}>
            {label}
          </button>
        ))}
      </div>
      {tab === 'clinic' && <ClinicDetails />}
      {tab === 'billing' && <BillingPrefs />}
      {tab === 'forms' && <FormDesigner />}
      {(tab === 'department' || tab === 'payer' || tab === 'vendor') && <AdminList key={tab} kind={tab} />}
      {(tab === 'by' || tab === 'to') && <ReferralDoctors key={tab} direction={tab} />}
      {tab === 'merge' && <MergePatients />}
    </section>
    </>
  )
}
