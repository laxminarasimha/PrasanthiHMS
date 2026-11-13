import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { useAuth } from '../auth'
import { TextField } from '../components/Field'
import { LabTabs } from '../components/LabTabs'
import { rupees } from '../format'
import type { LabCatalogueTest, LabParameter } from '../types'

const emptyParameter = (is_heading = false): LabParameter => ({ name: '', is_heading, unit: '', reference_range: '', method: '' })
const blank = { code: '', name: '', kind: 'values' as 'values' | 'text', default_comment: '', department: '', sample_type: 'Blood', price: '', print_order: '', is_active: true }
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
      })
      setParameters(target.parameters.length ? target.parameters : [emptyParameter()])
    }
  }

  const set = (key: 'name' | 'code' | 'department' | 'sample_type' | 'price' | 'print_order' | 'default_comment') => (value: string) => setForm((f) => ({ ...f, [key]: value }))
  const setParameter = (index: number, key: 'name' | 'unit' | 'reference_range' | 'method', value: string) =>
    setParameters((rows) => rows.map((row, i) => (i === index ? { ...row, [key]: value } : row)))

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    const payload = {
      ...form,
      code: form.code || null,
      print_order: form.print_order || 0,
      default_comment: form.default_comment || null,
      parameters:
        form.kind === 'text'
          ? []
          : parameters
              .filter((p) => p.name || p.unit || p.reference_range || p.method)
              .map(({ name, is_heading, unit, reference_range, method }) =>
                is_heading ? { name, is_heading: true } : { name, is_heading: false, unit: unit || null, reference_range: reference_range || null, method: method || null },
              ),
    }
    try {
      const res = editing === 'new' ? await api.post<LabCatalogueTest>('/lab/tests', payload) : await api.put<LabCatalogueTest>(`/lab/tests/${(editing as LabCatalogueTest).id}`, payload)
      setNotice(`${res.data.name} was ${editing === 'new' ? 'added' : 'updated'}.`)
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

  const visible = tests.filter((t) => t.name.toLowerCase().includes(filter.trim().toLowerCase()))
  const errorList = Object.entries(errors).filter(([field]) => field.startsWith('parameters') || field === '_' || field === 'kind' || field === 'default_comment')

  return (
    <>
    <LabTabs />
    <div className="split">
      <section className="card">
        <div className="toolbar">
          <h1>Report templates</h1>
          <input className="search" type="search" placeholder="Filter tests" value={filter} onChange={(e) => setFilter(e.target.value)} />
          {canEdit && !ordering && tests.length > 1 && (
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
          {canEdit && !ordering && (
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

        {!ordering && (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Test / report</th>
                <th>Department</th>
                <th>Sample</th>
                <th className="num">Parameters</th>
                <th className="num">Price</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {visible.map((t) => (
                <tr key={t.id} onClick={() => open(t)} className={editing !== 'new' && editing?.id === t.id ? 'selected' : ''}>
                  <td>
                    <strong>{t.name}</strong>
                    {t.code && <small className="muted"> {t.code}</small>}
                  </td>
                  <td>{t.department ?? '—'}</td>
                  <td>{t.sample_type ?? '—'}</td>
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
        <form className="card panel wide" onSubmit={save} noValidate>
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
              <TextField label="Department" placeholder="Biochemistry" value={form.department} onChange={set('department')} error={errors.department} />
              <TextField label="Sample type" placeholder="Blood" value={form.sample_type} onChange={set('sample_type')} error={errors.sample_type} />
              <TextField label="Price (₹)" required type="number" min={0} step="0.01" value={form.price} onChange={set('price')} error={errors.price} />
              <TextField label="Print order" type="number" min={0} value={form.print_order} onChange={set('print_order')} error={errors.print_order} />
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
                    <div className={`lab-param ${p.is_heading ? 'heading' : ''}`} key={i}>
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
                        <button type="button" className="link danger" onClick={() => setParameters((rows) => (rows.length > 1 ? rows.filter((_, r) => r !== i) : [emptyParameter()]))}>
                          Remove
                        </button>
                      </span>
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
                <p className="muted small">Write number ranges as "70 - 140" so high and low results are flagged automatically.</p>
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
        </form>
      )}
    </div>
    </>
  )
}
