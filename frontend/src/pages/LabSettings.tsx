import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors, type FieldErrors } from '../api'
import { useAuth } from '../auth'
import { TextField } from '../components/Field'
import { LabTabs } from '../components/LabTabs'
import type { LabSettings as Settings } from '../types'

type Toggle = [keyof Settings, string, string]

const RULES: Toggle[] = [
  ['approval_required', 'Only approved results can be printed', 'A second check: a reported result has to be approved before it appears on the printed report.'],
  ['allow_print_without_payment', 'Allow printing before the bill is paid', 'Untick to hold back the report until the lab bill has no balance.'],
]

const LAYOUT: Toggle[] = [
  ['group_by_department', 'Group the tests under their departments', 'A heading such as HAEMATOLOGY or BIOCHEMISTRY above each set of tests, in the order of the list above.'],
  ['department_on_new_page', 'Start each department on a new page', 'A separate sheet for haematology, another for biochemistry, each with the patient details at the top.'],
  ['each_test_on_new_page', 'Print each test on a separate page', ''],
  ['tabular_print', 'Print in compact layout', 'Everything in one table without page breaks, to save paper. It can still be changed when printing.'],
  ['print_method', 'Print the method under each parameter', 'Only where a method has been typed for the parameter.'],
  ['print_sample_date', 'Print the sample collection date', ''],
  ['print_approved_date', 'Print the approval date', ''],
  ['print_end_mark', 'Print "End of report" after the last result', ''],
  ['print_outside_lab', 'Print where a test was done, when it was an outside lab', 'A small “Processed at …” with the outside lab’s name after the test name.'],
  ['print_template_version', 'Print the template version beside each test', 'A small “Template v2” after the test name, so a printed report says which version of its template it was made with.'],
]

export function LabSettings() {
  const { user } = useAuth()
  const canEdit = Boolean(user?.can_run_lab)
  const [form, setForm] = useState<Settings | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState('')
  const [newDepartment, setNewDepartment] = useState('')

  useEffect(() => {
    api.get<Settings>('/lab/settings').then((res) => setForm(res.data))
  }, [])

  if (!form) return <p className="loading">Loading…</p>

  const text = (key: 'lab_name' | 'lab_address' | 'technician_1' | 'technician_2') => ({
    value: form[key] ?? '',
    onChange: (value: string) => setForm({ ...form, [key]: value }),
    error: errors[key],
  })
  const number = (key: 'letterhead_top_mm' | 'letterhead_bottom_mm' | 'label_width_mm' | 'label_height_mm') => ({
    value: String(form[key]),
    onChange: (value: string) => setForm({ ...form, [key]: value === '' ? 0 : Number(value) }),
    error: errors[key],
  })

  // The lab's departments, in the order they are printed.
  const departments = form.departments ?? []
  const setDepartments = (next: string[]) => setForm({ ...form, departments: next })
  const moveDepartment = (index: number, to: number) => {
    if (to < 0 || to >= departments.length) return
    const next = [...departments]
    next.splice(to, 0, next.splice(index, 1)[0])
    setDepartments(next)
  }
  const addDepartment = () => {
    const name = newDepartment.trim()
    if (!name || departments.some((d) => d.toLowerCase() === name.toLowerCase())) return
    setDepartments([...departments, name])
    setNewDepartment('')
  }
  const toggles = (rows: Toggle[]) =>
    rows.map(([key, label, hint]) => (
      <label className="check top" key={key}>
        <input type="checkbox" checked={Boolean(form[key])} onChange={(e) => setForm({ ...form, [key]: e.target.checked })} />
        <span>
          {label}
          {hint && <small className="muted block">{hint}</small>}
        </span>
      </label>
    ))

  // Signature pictures are saved straight away, apart from the rest of the form.
  const signature = async (slot: 1 | 2, file?: File) => {
    setErrors({})
    try {
      let res
      if (file) {
        const body = new FormData()
        body.append('slot', String(slot))
        body.append('file', file)
        res = await api.post<Settings>('/lab/settings/signature', body)
      } else {
        res = await api.delete<Settings>(`/lab/settings/signature/${slot}`)
      }
      setForm({ ...form, technician_1_signature: res.data.technician_1_signature, technician_2_signature: res.data.technician_2_signature })
      setNotice(file ? 'Signature saved.' : 'Signature removed.')
    } catch (err) {
      setErrors({ _: Object.values(fieldErrors(err))[0] })
    }
  }

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

          <h3>Technician signatures</h3>
          <p className="muted small">A picture of each signature (PNG or JPG, up to 300 KB) is printed above the name at the foot of reports.</p>
          <div className="grid">
            {([1, 2] as const).map((slot) => {
              const picture = form[`technician_${slot}_signature`]
              return (
                <div className="signature-box" key={slot}>
                  <span className="field-label">{form[`technician_${slot}`] || `Lab technician ${slot}`}</span>
                  {picture ? <img src={picture} alt={`Signature of technician ${slot}`} /> : <span className="muted small">No signature picture</span>}
                  <div className="row">
                    <label className="btn ghost small-btn">
                      {picture ? 'Replace' : 'Upload'}
                      <input
                        type="file"
                        hidden
                        accept="image/png,image/jpeg"
                        onChange={(e) => {
                          signature(slot, e.target.files?.[0])
                          e.target.value = ''
                        }}
                      />
                    </label>
                    {picture && (
                      <button type="button" className="link danger" onClick={() => signature(slot)}>
                        Remove
                      </button>
                    )}
                  </div>
                </div>
              )
            })}
          </div>

          <h3>Printing on letterhead</h3>
          <p className="muted small">When "Print on letterhead" is ticked, this much of every page is left blank for the pre-printed heading and footer.</p>
          <div className="grid">
            <TextField label="Space at the top (mm)" type="number" min={0} max={150} {...number('letterhead_top_mm')} />
            <TextField label="Space at the bottom (mm)" type="number" min={0} max={100} {...number('letterhead_bottom_mm')} />
          </div>

          <h3>Departments</h3>
          <p className="muted small">
            The sections of the lab. Each test is filed under one on the <Link to="/lab/tests">Report templates</Link> screen, and the printed report shows them in this order.
          </p>
          <ol className="pad-order">
            {departments.map((d, i) => (
              <li key={d}>
                <span className="muted mono">{i + 1}</span>
                <span className="grow">{d}</span>
                <button type="button" className="btn ghost small-btn" disabled={i === 0} onClick={() => moveDepartment(i, i - 1)} aria-label={`Move ${d} up`}>
                  ↑
                </button>
                <button type="button" className="btn ghost small-btn" disabled={i === departments.length - 1} onClick={() => moveDepartment(i, i + 1)} aria-label={`Move ${d} down`}>
                  ↓
                </button>
                <button type="button" className="link danger" onClick={() => setDepartments(departments.filter((x) => x !== d))} aria-label={`Remove ${d}`}>
                  Remove
                </button>
              </li>
            ))}
          </ol>
          <div className="row">
            <input
              placeholder="Add a department, e.g. Molecular Biology"
              value={newDepartment}
              onChange={(e) => setNewDepartment(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault()
                  addDepartment()
                }
              }}
              aria-label="New department"
            />
            <button type="button" className="btn ghost" onClick={addDepartment} disabled={!newDepartment.trim()}>
              Add
            </button>
          </div>
          <p className="muted small">Removing a department here does not change the tests filed under it; they print after the listed ones until they are moved.</p>
          {errors['departments.0'] && <p className="form-error">{errors['departments.0']}</p>}

          <h3>Layout of the printed report</h3>
          {toggles(LAYOUT)}
          <label className="field gap-top">
            <span className="field-label">A line at the foot of every report (optional)</span>
            <input
              placeholder="e.g. Results relate only to the sample tested. Please correlate clinically."
              maxLength={500}
              value={form.report_note ?? ''}
              onChange={(e) => setForm({ ...form, report_note: e.target.value })}
            />
          </label>

          <h3>Sample labels</h3>
          <p className="muted small">The sticker for a sample tube, printed from an order with "Sample labels". Set the size of the stickers in your label printer.</p>
          <div className="grid">
            <TextField label="Label width (mm)" type="number" min={20} max={120} {...number('label_width_mm')} />
            <TextField label="Label height (mm)" type="number" min={10} max={100} {...number('label_height_mm')} />
          </div>
          {toggles([['label_barcode', 'Print the order number as a barcode', 'A scanner pointed at the tube then types the order number, for example into the search box of the Lab screen.']])}

          <h3>Rules</h3>
          {toggles(RULES)}
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
