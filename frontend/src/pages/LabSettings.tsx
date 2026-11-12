import { useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { useAuth } from '../auth'
import { TextField } from '../components/Field'
import { LabTabs } from '../components/LabTabs'
import type { LabSettings as Settings } from '../types'

const TOGGLES: [keyof Settings, string, string][] = [
  ['approval_required', 'Only approved results can be printed', 'A second check: a reported result has to be approved before it appears on the printed report.'],
  ['allow_print_without_payment', 'Allow printing before the bill is paid', 'Untick to hold back the report until the lab bill has no balance.'],
  ['each_test_on_new_page', 'Print each test on a separate page', ''],
  ['print_sample_date', 'Print the sample collection date', ''],
  ['print_approved_date', 'Print the approval date', ''],
]

export function LabSettings() {
  const { user } = useAuth()
  const canEdit = Boolean(user?.can_run_lab)
  const [form, setForm] = useState<Settings | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState('')

  useEffect(() => {
    api.get<Settings>('/lab/settings').then((res) => setForm(res.data))
  }, [])

  if (!form) return <p className="loading">Loading…</p>

  const text = (key: 'lab_name' | 'lab_address' | 'technician_1' | 'technician_2') => ({
    value: form[key] ?? '',
    onChange: (value: string) => setForm({ ...form, [key]: value }),
    error: errors[key],
  })
  const number = (key: 'letterhead_top_mm' | 'letterhead_bottom_mm') => ({
    value: String(form[key]),
    onChange: (value: string) => setForm({ ...form, [key]: value === '' ? 0 : Number(value) }),
    error: errors[key],
  })

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    setNotice('')
    try {
      setForm((await api.put<Settings>('/lab/settings', form)).data)
      setNotice('Lab preferences saved.')
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <>
      <LabTabs />
      <form className="card narrow" onSubmit={save} noValidate>
        <div className="toolbar">
          <h1>Lab preferences</h1>
        </div>
        {notice && <p className="notice">{notice}</p>}
        {!canEdit && <p className="muted">Only lab staff can change these.</p>}
        <fieldset disabled={!canEdit}>
          <h3>Report heading</h3>
          <div className="grid">
            <TextField label="Lab name" placeholder="Shown at the top of reports; the hospital name is used if empty" {...text('lab_name')} />
            <TextField label="Lab address" {...text('lab_address')} />
            <TextField label="Lab technician 1" placeholder="Name printed at the foot of reports" {...text('technician_1')} />
            <TextField label="Lab technician 2" {...text('technician_2')} />
          </div>

          <h3>Printing on letterhead</h3>
          <p className="muted small">When "Print on letterhead" is ticked, this much of every page is left blank for the pre-printed heading and footer.</p>
          <div className="grid">
            <TextField label="Space at the top (mm)" type="number" min={0} max={150} {...number('letterhead_top_mm')} />
            <TextField label="Space at the bottom (mm)" type="number" min={0} max={100} {...number('letterhead_bottom_mm')} />
          </div>

          <h3>Rules</h3>
          {TOGGLES.map(([key, label, hint]) => (
            <label className="check top" key={key}>
              <input type="checkbox" checked={Boolean(form[key])} onChange={(e) => setForm({ ...form, [key]: e.target.checked })} />
              <span>
                {label}
                {hint && <small className="muted block">{hint}</small>}
              </span>
            </label>
          ))}
        </fieldset>
        {errors._ && <p className="form-error">{errors._}</p>}
        {canEdit && (
          <div className="panel-foot">
            <button className="btn primary" disabled={saving}>
              {saving ? 'Saving…' : 'Save preferences'}
            </button>
          </div>
        )}
      </form>
    </>
  )
}
