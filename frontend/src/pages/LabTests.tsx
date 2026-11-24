import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { useAuth } from '../auth'
import { TextField } from '../components/Field'
import { LabTabs } from '../components/LabTabs'
import { LabVersions } from '../components/LabVersions'
import { rupees } from '../format'
import type { LabCatalogueTest, LabParameter, LabRange, LabSettings } from '../types'

const emptyParameter = (is_heading = false): LabParameter => ({ name: '', is_heading, unit: '', reference_range: '', method: '' })
const emptyRange = (): LabRange => ({ sex: null, age_from: null, age_to: null, age_unit: 'years', range: '' })
const blank = { code: '', name: '', kind: 'values' as 'values' | 'text', default_comment: '', department: '', sample_type: 'Blood', price: '', print_order: '', is_active: true, outside_lab: '', outside_cost: '' }
type Form = typeof blank

export function LabTests() {
  const { user } = useAuth()
  const canEdit = Boolean(user?.can_run_lab)
  const [tests, setTests] = useState<LabCatalogueTest[]>([])
  const [filter, setFilter] = useState('')
  const [editing, setEditing] = useState<LabCatalogueTest | 'new' | null>(null)
  const [form, setForm] = useState<Form>(blank)
  const [parameters, setParameters] = useState<LabParameter[]>([emptyParameter()])
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState('')
  /** The tests in the order being arranged, while re-ordering; null otherwise. */
  const [ordering, setOrdering] = useState<LabCatalogueTest[] | null>(null)
  // The lab's departments, which test list is shown ('' all, '-' those with none), and the tests being filed in one go.
  const [departments, setDepartments] = useState<string[]>([])
  const [showing, setShowing] = useState('')
  const [sorting, setSorting] = useState<{ id: number; name: string; is_active: boolean; department: string; suggested: string | null }[] | null>(null)
  useEffect(() => {
    api.get<LabSettings>('/lab/settings').then((res) => setDepartments(res.data.departments ?? [])).catch(() => undefined)
  }, [])

  const load = useCallback(() => {
    api.get<LabCatalogueTest[]>('/lab/tests', { params: { all: 1 } }).then((res) => setTests(res.data))
  }, [])
  useEffect(load, [load])

  const open = (target: LabCatalogueTest | 'new', kind: Form['kind'] = 'values') => {
    setEditing(target)
    setErrors({})
    setNotice('')
    if (target === 'new') {
      setForm({ ...blank, kind, sample_type: kind === 'text' ? '' : 'Blood' })
      setParameters([emptyParameter()])
    } else {
      setForm({
        code: target.code ?? '', name: target.name, kind: target.kind, default_comment: target.default_comment ?? '', department: target.department ?? '', sample_type: target.sample_type ?? '',
        price: String(target.price), print_order: String(target.print_order), is_active: target.is_active,
        outside_lab: target.outside_lab ?? '', outside_cost: target.outside_cost == null ? '' : String(target.outside_cost),
      })
      setParameters(target.parameters.length ? target.parameters : [emptyParameter()])
    }
  }

  const set = (key: 'name' | 'code' | 'department' | 'sample_type' | 'price' | 'print_order' | 'default_comment' | 'outside_lab' | 'outside_cost') => (value: string) => setForm((f) => ({ ...f, [key]: value }))
  const setParameter = (index: number, key: 'name' | 'unit' | 'reference_range' | 'method', value: string) =>
    setParameters((rows) => rows.map((row, i) => (i === index ? { ...row, [key]: value } : row)))
  /** The extra normal ranges of one parameter, each for an age band, a sex, or both. */
  const setRanges = (index: number, change: (ranges: LabRange[]) => LabRange[]) =>
    setParameters((rows) => rows.map((row, i) => (i === index ? { ...row, ranges: change(row.ranges ?? []) } : row)))
  const setRange = (index: number, at: number, change: Partial<LabRange>) => setRanges(index, (ranges) => ranges.map((r, j) => (j === at ? { ...r, ...change } : r)))

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    const payload = {
      ...form,
      code: form.code || null,
      print_order: form.print_order || 0,
      default_comment: form.default_comment || null,
      outside_lab: form.outside_lab.trim() || null,
      outside_cost: form.outside_lab.trim() && form.outside_cost !== '' ? form.outside_cost : null,
      parameters:
        form.kind === 'text'
          ? []
          : parameters
              .filter((p) => p.name || p.unit || p.reference_range || p.method)
              .map(({ name, is_heading, unit, reference_range, method, ranges }) =>
                is_heading
                  ? { name, is_heading: true }
                  : {
                      name, is_heading: false, unit: unit || null, reference_range: reference_range || null, method: method || null,
                      // A line left completely empty is dropped; one half filled is sent, so the lab is told what is missing.
                      ranges: (ranges ?? []).filter((r) => r.range.trim() || r.sex || r.age_from !== null || r.age_to !== null),
                    },
              ),
    }
    try {
      const res = editing === 'new' ? await api.post<LabCatalogueTest>('/lab/tests', payload) : await api.put<LabCatalogueTest>(`/lab/tests/${(editing as LabCatalogueTest).id}`, payload)
      const was = editing === 'new' ? null : (editing as LabCatalogueTest).version
      setNotice(
        `${res.data.name} was ${editing === 'new' ? 'added' : 'updated'}.` +
          (was !== null && res.data.version > was ? ` Its template is now version ${res.data.version}: new orders use it, and reports already made keep the version they were made with.` : ''),
      )
      setEditing(null)
      load()
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  const moveTest = (index: number, to: number) =>
    setOrdering((rows) => {
      if (!rows || to < 0 || to >= rows.length) return rows
      const next = [...rows]
      next.splice(to, 0, next.splice(index, 1)[0])
      return next
    })
  const saveOrder = async () => {
    if (!ordering) return
    try {
      setTests((await api.put<LabCatalogueTest[]>('/lab/tests/order', { ids: ordering.map((t) => t.id) })).data)
      setOrdering(null)
      setNotice('Order saved. Tests appear in this order when ordering and on printed reports.')
    } catch (err) {
      setErrors(fieldErrors(err))
    }
  }
  const moveParameter = (index: number, to: number) =>
    setParameters((rows) => {
      if (to < 0 || to >= rows.length) return rows
      const next = [...rows]
      next.splice(to, 0, next.splice(index, 1)[0])
      return next
    })

  const unfiled = tests.filter((t) => !t.department?.trim())
  // Departments in the lab's order, then any a test carries that the lab has not listed.
  const inUse = [...departments, ...[...new Set(tests.map((t) => t.department?.trim() ?? '').filter((d) => d && !departments.some((x) => x.toLowerCase() === d.toLowerCase())))].sort()]
  const visible = tests
    .filter((t) => t.name.toLowerCase().includes(filter.trim().toLowerCase()))
    .filter((t) => showing === '' || (showing === '-' ? !t.department?.trim() : (t.department ?? '').trim().toLowerCase() === showing.toLowerCase()))

  /** Opens the list of tests with no department, each with the one its name suggests, to look over before saving. */
  const startSorting = async () => {
    setEditing(null)
    setNotice('')
    const res = await api.get<{ id: number; name: string; is_active: boolean; suggested: string | null }[]>('/lab/tests/department-suggestions')
    setSorting(res.data.map((t) => ({ ...t, department: t.suggested ?? '' })))
  }
  const saveSorting = async () => {
    const chosen = (sorting ?? []).filter((t) => t.department)
    if (chosen.length === 0) return setSorting(null)
    try {
      await api.put('/lab/tests/departments', { tests: chosen.map((t) => ({ id: t.id, department: t.department })) })
      setNotice(`${chosen.length} ${chosen.length === 1 ? 'test' : 'tests'} filed under ${chosen.length === 1 ? 'its department' : 'their departments'}. Reports print them under those headings from now on.`)
      setSorting(null)
      load()
    } catch (err) {
      setErrors(fieldErrors(err))
    }
  }
  const outsideLabs = [...new Set(tests.map((t) => t.outside_lab?.trim() ?? '').filter(Boolean))].sort()
  const errorList = Object.entries(errors).filter(([field]) => field.startsWith('parameters') || field === '_' || field === 'kind' || field === 'default_comment')

  return (
    <>
    <LabTabs />
    <div className="split">
      <section className="card">
        <div className="toolbar">
          <h1>Report templates</h1>
          <input className="search" type="search" placeholder="Filter tests" value={filter} onChange={(e) => setFilter(e.target.value)} />
          <select className="dept-filter" value={showing} onChange={(e) => setShowing(e.target.value)} aria-label="Department">
            <option value="">All departments{tests.length > 0 ? ` (${tests.length})` : ''}</option>
            {inUse.map((d) => (
              <option key={d} value={d}>
                {d} ({tests.filter((t) => (t.department ?? '').trim().toLowerCase() === d.toLowerCase()).length})
              </option>
            ))}
            <option value="-">No department{tests.length > 0 ? ` (${unfiled.length})` : ''}</option>
          </select>
          {canEdit && !ordering && !sorting && unfiled.length > 0 && (
            <button className="btn ghost" onClick={startSorting} title="File the tests that have no department">
              Sort into departments ({unfiled.length})
            </button>
          )}
          {canEdit && !ordering && !sorting && tests.length > 1 && (
            <button
              className="btn ghost"
              onClick={() => {
                setEditing(null)
                setNotice('')
                setOrdering(tests)
              }}
            >
              Re-order tests
            </button>
          )}
          {canEdit && !ordering && !sorting && (
            <>
              <button className="btn ghost" onClick={() => open('new', 'text')}>
                + New written report
              </button>
              <button className="btn primary" onClick={() => open('new')}>
                + New test
              </button>
            </>
          )}
        </div>

        {notice && <p className="notice">{notice}</p>}

        {ordering && (
          <div>
            <p className="muted small">Move tests up or down. This is the order they appear in when ordering and on a printed report with several tests.</p>
            <ol className="pad-order wide">
              {ordering.map((t, i) => (
                <li key={t.id} className={t.is_active ? '' : 'off'}>
                  <span className="muted mono">{i + 1}</span>
                  <span className="grow">
                    {t.name}
                    {t.department && <small className="muted"> · {t.department}</small>}
                  </span>
                  <button type="button" className="btn ghost small-btn" disabled={i === 0} onClick={() => moveTest(i, 0)} aria-label={`Move ${t.name} to the top`} title="To the top">
                    ⤒
                  </button>
                  <button type="button" className="btn ghost small-btn" disabled={i === 0} onClick={() => moveTest(i, i - 1)} aria-label={`Move ${t.name} up`}>
                    ↑
                  </button>
                  <button type="button" className="btn ghost small-btn" disabled={i === ordering.length - 1} onClick={() => moveTest(i, i + 1)} aria-label={`Move ${t.name} down`}>
                    ↓
                  </button>
                </li>
              ))}
            </ol>
            <div className="panel-foot row">
              <button className="btn ghost" onClick={() => setOrdering(null)}>
                Cancel
              </button>
              <button className="btn ghost" onClick={() => setOrdering((rows) => (rows ? [...rows].sort((a, b) => a.name.localeCompare(b.name)) : rows))}>
                Sort A to Z
              </button>
              <button className="btn primary" onClick={saveOrder}>
                Save order
              </button>
            </div>
          </div>
        )}

        {sorting && (
          <div>
            <p className="muted small">
              These tests have no department yet. Each one shows the department its name suggests: check it, change it where it is wrong, and leave it empty to decide later. Nothing is saved until you press the button.
            </p>
            <div className="table-wrap">
              <table className="compact">
                <thead>
                  <tr>
                    <th>Test / report</th>
                    <th>Department</th>
                  </tr>
                </thead>
                <tbody>
                  {sorting.map((t) => (
                    <tr key={t.id} className={`static ${t.is_active ? '' : 'faded'}`}>
                      <td>
                        {t.name}
                        {!t.suggested && <small className="muted block">No suggestion: the name does not say</small>}
                      </td>
                      <td>
                        <select value={t.department} onChange={(e) => setSorting((rows) => rows && rows.map((r) => (r.id === t.id ? { ...r, department: e.target.value } : r)))} aria-label={`Department of ${t.name}`}>
                          <option value="">Leave for later</option>
                          {departments.map((d) => (
                            <option key={d} value={d}>
                              {d}
                            </option>
                          ))}
                        </select>
                      </td>
                    </tr>
                  ))}
                  {sorting.length === 0 && (
                    <tr className="empty">
                      <td colSpan={2}>Every test already has a department.</td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
            <div className="panel-foot row">
              <button className="btn ghost" onClick={() => setSorting(null)}>
                Cancel
              </button>
              <button className="btn primary" onClick={saveSorting} disabled={!sorting.some((t) => t.department)}>
                File {sorting.filter((t) => t.department).length} under their departments
              </button>
            </div>
          </div>
        )}

        {!ordering && !sorting && (
        <div className="table-wrap">
          <table className={`fit-table ${editing ? 'beside-panel' : ''}`}>
            <thead>
              <tr>
                <th>Test / report</th>
                <th>Department</th>
                <th className="when-roomy">Sample</th>
                <th className="num">Parameters</th>
                <th className="num">Price</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {visible.map((t) => (
                <tr key={t.id} onClick={() => open(t)} className={editing !== 'new' && editing?.id === t.id ? 'selected' : ''}>
                  <td className="fit-wrap">
                    <strong>{t.name}</strong>
                    {t.code && <small className="muted"> {t.code}</small>}
                    {t.outside_lab && <small className="muted block">Done at {t.outside_lab}</small>}
                    {t.version > 1 && (
                      <small className="muted" title="The version of the report template in use now">
                        {' '}
                        · v{t.version}
                      </small>
                    )}
                  </td>
                  <td className="wrap">{t.department ?? '—'}</td>
                  <td className="wrap when-roomy">{t.sample_type ?? '—'}</td>
                  <td className="num">{t.kind === 'text' ? 'Written' : t.parameters.filter((p) => !p.is_heading).length}</td>
                  <td className="num">{rupees(t.price)}</td>
                  <td>
                    <span className={t.is_active ? 'pill ok' : 'pill off'}>{t.is_active ? 'Active' : 'Hidden'}</span>
                  </td>
                </tr>
              ))}
              {visible.length === 0 && (
                <tr className="empty">
                  <td colSpan={6}>No tests found.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        )}
      </section>

      {editing && (
        <form className="card panel wide template-editor" onSubmit={save} noValidate>
          <div className="panel-head">
            <h2>{editing === 'new' ? (form.kind === 'text' ? 'Add written report' : 'Add test') : editing.name}</h2>
            <button type="button" className="btn ghost" onClick={() => setEditing(null)}>
              Close
            </button>
          </div>
          <fieldset disabled={!canEdit}>
            <div className="grid">
              <div className="span-2">
                <TextField label="Test / report name" required value={form.name} onChange={set('name')} error={errors.name} autoFocus />
              </div>
              <div>
                <TextField label="Department" placeholder="Choose, or type your own" list="lab-departments" value={form.department} onChange={set('department')} error={errors.department} />
                <datalist id="lab-departments">
                  {inUse.map((d) => (
                    <option key={d} value={d} />
                  ))}
                </datalist>
              </div>
              <TextField label="Sample type" placeholder="Blood" value={form.sample_type} onChange={set('sample_type')} error={errors.sample_type} />
              <TextField label="Price (₹)" required type="number" min={0} step="0.01" value={form.price} onChange={set('price')} error={errors.price} />
              <TextField label="Print order" type="number" min={0} value={form.print_order} onChange={set('print_order')} error={errors.print_order} />
              <div>
                <TextField label="Done at an outside lab" placeholder="Leave empty when it is done here" list="lab-outside-labs" value={form.outside_lab} onChange={set('outside_lab')} error={errors.outside_lab} />
                <datalist id="lab-outside-labs">
                  {outsideLabs.map((name) => (
                    <option key={name} value={name} />
                  ))}
                </datalist>
              </div>
              {form.outside_lab.trim() !== '' && (
                <TextField label="What that lab charges (₹)" type="number" min={0} step="0.01" value={form.outside_cost} onChange={set('outside_cost')} error={errors.outside_cost} />
              )}
            </div>

            {form.kind === 'text' ? (
              <p className="muted small gap-top">A written report (ECG, X-ray, scan) has no parameters: the result is typed as free text when the test is reported.</p>
            ) : (
              <>
                <h3>Parameters reported</h3>
                <div className="rx">
                  <div className="lab-param rx-head">
                    <span>Parameter</span>
                    <span>Unit</span>
                    <span>Reference range</span>
                    <span>Method</span>
                    <span />
                  </div>
                  {parameters.map((p, i) => (
                    <div className="lab-param-block" key={i}>
                    <div className={`lab-param ${p.is_heading ? 'heading' : ''}`}>
                      <input placeholder={p.is_heading ? 'Sub heading, e.g. Differential count' : 'Haemoglobin'} value={p.name} onChange={(e) => setParameter(i, 'name', e.target.value)} aria-label={p.is_heading ? `Sub heading ${i + 1}` : `Parameter ${i + 1}`} />
                      {!p.is_heading && (
                        <>
                          <input placeholder="g/dL" value={p.unit ?? ''} onChange={(e) => setParameter(i, 'unit', e.target.value)} aria-label="Unit" />
                          <input placeholder="12 - 16" value={p.reference_range ?? ''} onChange={(e) => setParameter(i, 'reference_range', e.target.value)} aria-label="Reference range" />
                          <input value={p.method ?? ''} onChange={(e) => setParameter(i, 'method', e.target.value)} aria-label="Method" />
                        </>
                      )}
                      <span className="row param-move">
                        <button type="button" className="link" disabled={i === 0} onClick={() => moveParameter(i, i - 1)} aria-label={`Move row ${i + 1} up`} title="Move up">
                          ↑
                        </button>
                        <button type="button" className="link" disabled={i === parameters.length - 1} onClick={() => moveParameter(i, i + 1)} aria-label={`Move row ${i + 1} down`} title="Move down">
                          ↓
                        </button>
                        {!p.is_heading && (
                          <button type="button" className="link" onClick={() => setRanges(i, (ranges) => [...ranges, emptyRange()])} title="A different normal range for an age or a sex" aria-label={`Add a range by age or sex to row ${i + 1}`}>
                            + Range
                          </button>
                        )}
                        <button type="button" className="link danger" onClick={() => setParameters((rows) => (rows.length > 1 ? rows.filter((_, r) => r !== i) : [emptyParameter()]))}>
                          Remove
                        </button>
                      </span>
                    </div>
                    {!p.is_heading &&
                      (p.ranges ?? []).map((r, j) => (
                        <div className="lab-range" key={j}>
                          <select value={r.sex ?? ''} onChange={(e) => setRange(i, j, { sex: (e.target.value || null) as LabRange['sex'] })} aria-label="Sex this range is for">
                            <option value="">Any sex</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                          </select>
                          <span className="muted small">age</span>
                          <input type="number" min={0} placeholder="from" value={r.age_from ?? ''} onChange={(e) => setRange(i, j, { age_from: e.target.value === '' ? null : Number(e.target.value) })} aria-label="Age from" />
                          <span className="muted small">to</span>
                          <input type="number" min={0} placeholder="to" value={r.age_to ?? ''} onChange={(e) => setRange(i, j, { age_to: e.target.value === '' ? null : Number(e.target.value) })} aria-label="Age to" />
                          <select value={r.age_unit} onChange={(e) => setRange(i, j, { age_unit: e.target.value as LabRange['age_unit'] })} aria-label="Age counted in">
                            <option value="years">years</option>
                            <option value="months">months</option>
                            <option value="days">days</option>
                          </select>
                          <input className="grow" placeholder="Normal range, e.g. 12 - 15" value={r.range} onChange={(e) => setRange(i, j, { range: e.target.value })} aria-label="Normal range for these patients" />
                          <button type="button" className="link danger" onClick={() => setRanges(i, (ranges) => ranges.filter((_, k) => k !== j))} aria-label="Remove this range">
                            Remove
                          </button>
                        </div>
                      ))}
                    </div>
                  ))}
                </div>
                <div className="row gap-top">
                  <button type="button" className="link" onClick={() => setParameters((rows) => [...rows, emptyParameter()])}>
                    + Add parameter
                  </button>
                  <button type="button" className="link" onClick={() => setParameters((rows) => [...rows, emptyParameter(true)])}>
                    + Add sub heading
                  </button>
                </div>
                <p className="muted small">
                  Write ranges as "70 - 140", "&lt; 200" or "&gt; 40" so high and low results are flagged automatically. "+ Range" adds a different normal range for an age or a sex: the first line that fits the
                  patient is printed on the report, and the range in the row itself for everyone else. Leave the ages empty for a range that depends only on sex.
                </p>
              </>
            )}

            <label className="field">
              <span className="field-label">Default comment</span>
              <textarea rows={2} placeholder="Printed under this test on every report unless changed" value={form.default_comment} onChange={(e) => set('default_comment')(e.target.value)} />
            </label>

            {editing !== 'new' && (
              <label className="check">
                <input type="checkbox" checked={form.is_active} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))} />
                Active (untick to hide it from new orders)
              </label>
            )}
          </fieldset>

          {errorList.length > 0 && (
            <div className="form-error">
              {[...new Set(errorList.map(([, message]) => message))].map((message) => (
                <div key={message}>{message}</div>
              ))}
            </div>
          )}
          {canEdit && (
            <div className="panel-foot">
              <button className="btn primary" disabled={saving}>
                {saving ? 'Saving…' : editing === 'new' ? 'Add test' : 'Save changes'}
              </button>
            </div>
          )}
          {editing !== 'new' && <LabVersions key={`${editing.id}-${editing.version}`} testId={editing.id} />}
        </form>
      )}
    </div>
    </>
  )
}
