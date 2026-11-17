import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { SetupTabs } from '../components/SetupTabs'
import { api, fieldErrors, type FieldErrors } from '../api'
import { TextField } from '../components/Field'
import { useAuth } from '../auth'
import { PAYMENT_MODES, clock, longDate } from '../format'
import { AdminList } from './AdminLists'
import { FormDesigner } from './FormDesigner'
import type { BillingPreferences, Clinic, PatientMerge, ReferralDoctor } from '../types'

type Tab = 'clinic' | 'billing' | 'forms' | 'department' | 'payer' | 'vendor' | 'by' | 'to' | 'merge' | 'reading' | 'backups'
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
  ['reading', 'Reading scanned papers'],
  ['backups', 'Backups'],
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

type ReadingState = { marker: { set_up: boolean; online: boolean; seen_at: string | null }; ai: { set_up: boolean }; waiting?: number; key?: string }

/** How scanned old papers get read: the hospital's own reading computer, and the outside AI service for hard pages. */
function ReadingSetup() {
  const [state, setState] = useState<ReadingState | null>(null)
  const [key, setKey] = useState('')
  const [error, setError] = useState('')

  useEffect(() => {
    api.get<ReadingState>('/past-records/setup').then((res) => setState(res.data)).catch(() => setError('Could not load this page.'))
  }, [])

  const makeKey = async () => {
    if (state?.marker.set_up && !window.confirm('Make a new key? The reading computer stops working until the new key is put into it.')) return
    const res = await api.post<ReadingState>('/past-records/setup/reader-key', {})
    setState(res.data)
    setKey(res.data.key ?? '')
  }
  const switchOff = async () => {
    if (!window.confirm('Switch the reading computer off? Its key stops working, and papers waiting to be read go back to being typed by hand.')) return
    const res = await api.delete<ReadingState>('/past-records/setup/reader-key')
    setState(res.data)
    setKey('')
  }

  if (error) return <p className="form-error">{error}</p>
  if (!state) return <p className="loading">Loading…</p>
  const m = state.marker

  return (
    <div className="stack gap-top">
      <p className="muted">
        Old papers that patients bring are scanned on the patient’s page. A machine reads each scan into a draft, and a member of staff checks the draft against the scan before anything is filed. Two things can do the reading.
      </p>
      <div>
        <h2>1. The reading computer (inside the hospital)</h2>
        <p>
          One ordinary computer in the hospital, left switched on, runs a free reading program called Marker. It reads printed papers well, and the scans never leave the hospital. It is not good at handwriting.
        </p>
        <p>
          <span className={`pill ${m.set_up ? (m.online ? 'ok' : 'warn') : 'status-booked'}`}>{m.set_up ? (m.online ? 'Switched on and reading' : 'Set up, but not switched on just now') : 'Not set up'}</span>{' '}
          {m.seen_at && <span className="muted">Last heard from on {longDate(m.seen_at)} at {clock(m.seen_at)}.</span>} {state.waiting ? <span className="muted">{state.waiting} waiting to be read.</span> : null}
        </p>
        {key && (
          <p className="notice">
            The key for the reading computer, shown this once: <code className="key">{key}</code>
            <br />
            Put it in the file <code>reader.ini</code> on the reading computer. Anyone holding it can fetch scans waiting to be read, so do not send it by message or email.
          </p>
        )}
        <div className="foot-buttons">
          <button className="btn ghost" onClick={makeKey}>
            {m.set_up ? 'Make a new key' : 'Make a key for the reading computer'}
          </button>
          {m.set_up && (
            <button className="link danger" onClick={switchOff}>
              Switch the reading computer off
            </button>
          )}
        </div>
        <p className="muted small">Setting the computer up takes about half an hour once: the steps are in the file tools/record-reader/README.md that comes with this system.</p>
      </div>
      <div>
        <h2>2. AI reading (outside the hospital, only when asked)</h2>
        <p>
          For handwriting and pages the reading computer cannot manage, staff can press “Send to AI reading” on one paper. That page is sent over the internet to Anthropic’s Claude service, read, and the draft comes back in about a minute. Nothing is sent
          unless someone presses the button, and every send is written in the patient’s record log. Each page costs a few rupees.
        </p>
        <p>
          <span className={`pill ${state.ai.set_up ? 'ok' : 'status-booked'}`}>{state.ai.set_up ? 'Switched on' : 'Not set up'}</span>{' '}
          {!state.ai.set_up && <span className="muted">It is switched on by adding the service’s key to the server settings; the key is never typed into this screen.</span>}
        </p>
      </div>
      <p className="muted small">With neither set up, scanning and filing still work: staff type what matters from the scan on the checking screen.</p>
    </div>
  )
}

type BackupList = { backups: { name: string; size: number; made_at: string }[]; keep: number; automatic: boolean }

/** Copies of the whole database: made once a day by the app, and on demand here. */
function Backups() {
  const [data, setData] = useState<BackupList | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    api.get<BackupList>('/backups').then((res) => setData(res.data)).catch(() => setError('Could not load the backups.'))
  }, [])

  const make = async () => {
    setBusy(true)
    setError('')
    try {
      setData((await api.post<BackupList>('/backups')).data)
    } catch {
      setError('The backup could not be made. Try again in a minute.')
    } finally {
      setBusy(false)
    }
  }

  const download = async (name: string) => {
    setError('')
    try {
      const res = await api.get<Blob>(`/backups/${name}/download`, { responseType: 'blob' })
      const link = document.createElement('a')
      link.href = URL.createObjectURL(res.data)
      link.download = name
      link.click()
      setTimeout(() => URL.revokeObjectURL(link.href), 1000)
    } catch {
      setError('Could not download that backup.')
    }
  }

  const size = (bytes: number) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`)
  const newest = data?.backups[0]

  return (
    <div>
      <div className="toolbar">
        <span className="grow">
          {newest ? (
            <>
              Last backup: <strong>{longDate(newest.made_at)} {clock(newest.made_at)}</strong>
            </>
          ) : (
            'No backup has been made yet.'
          )}
          <small className="muted block">
            {data?.automatic ? 'The app makes one by itself every day, when the first person opens it.' : 'Automatic daily backups are switched off on this server.'} The newest {data?.keep ?? 30} are kept.
          </small>
        </span>
        <button className="btn primary" onClick={make} disabled={busy}>
          {busy ? 'Making backup…' : 'Back up now'}
        </button>
      </div>
      <p className="notice">
        These backups sit on the same server as the hospital's data. Download one to your own computer every week, so there is a copy if the server itself is lost. A backup holds every patient's records: keep the file somewhere safe and do not send it by email or WhatsApp.
      </p>
      {error && <p className="form-error">{error}</p>}
      <div className="table-wrap">
        <table className="compact">
          <thead>
            <tr>
              <th>Made</th>
              <th>File</th>
              <th className="num">Size</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {data?.backups.map((b) => (
              <tr key={b.name} className="static">
                <td>
                  {longDate(b.made_at)} <small className="muted">{clock(b.made_at)}</small>
                </td>
                <td className="mono">{b.name}</td>
                <td className="num">{size(b.size)}</td>
                <td className="num">
                  <button className="link" onClick={() => download(b.name)}>
                    Download
                  </button>
                </td>
              </tr>
            ))}
            {data && data.backups.length === 0 && (
              <tr className="empty">
                <td colSpan={4}>No backups yet. Press “Back up now” to make the first one.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
      <p className="muted small">Uploaded files (attachments, lab report files, signatures and the logo) are not inside these backups; they hold the database only. To put a backup back, the file is loaded through the hosting panel's phpMyAdmin.</p>
    </div>
  )
}

export function Admin() {
  const { user } = useAuth()
  const [tab, setTab] = useState<Tab>('clinic')
  const tabs = TABS.filter(([key]) => key !== 'backups' || user?.role === 'admin')

  return (
    <>
    <SetupTabs />
    <section className="card">
      <div className="toolbar">
        <h1>Hospital setup</h1>
      </div>
      <div className="tabs flush gap-bottom">
        {tabs.map(([key, label]) => (
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
      {tab === 'reading' && <ReadingSetup />}
      {tab === 'backups' && <Backups />}
    </section>
    </>
  )
}
