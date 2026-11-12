import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors, type FieldErrors } from '../api'
import { useAuth } from '../auth'
import { TextField } from '../components/Field'
import { rupees } from '../format'
import type { LabCatalogueTest, LabParameter } from '../types'

const emptyParameter = (): LabParameter => ({ name: '', unit: '', reference_range: '', method: '' })
const blank = { code: '', name: '', department: '', sample_type: 'Blood', price: '', print_order: '', is_active: true }
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

  const load = useCallback(() => {
    api.get<LabCatalogueTest[]>('/lab/tests', { params: { all: 1 } }).then((res) => setTests(res.data))
  }, [])
  useEffect(load, [load])

  const open = (target: LabCatalogueTest | 'new') => {
    setEditing(target)
    setErrors({})
    setNotice('')
    if (target === 'new') {
      setForm(blank)
      setParameters([emptyParameter()])
    } else {
      setForm({
        code: target.code ?? '', name: target.name, department: target.department ?? '', sample_type: target.sample_type ?? '',
        price: String(target.price), print_order: String(target.print_order), is_active: target.is_active,
      })
      setParameters(target.parameters.length ? target.parameters : [emptyParameter()])
    }
  }

  const set = (key: keyof Form) => (value: string) => setForm((f) => ({ ...f, [key]: value }))
  const setParameter = (index: number, key: keyof LabParameter, value: string) =>
    setParameters((rows) => rows.map((row, i) => (i === index ? { ...row, [key]: value } : row)))

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    const payload = {
      ...form,
      code: form.code || null,
      print_order: form.print_order || 0,
      parameters: parameters
        .filter((p) => p.name || p.unit || p.reference_range || p.method)
        .map(({ name, unit, reference_range, method }) => ({ name, unit: unit || null, reference_range: reference_range || null, method: method || null })),
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

  const visible = tests.filter((t) => t.name.toLowerCase().includes(filter.trim().toLowerCase()))
  const errorList = Object.entries(errors).filter(([field]) => field.startsWith('parameters') || field === '_')

  return (
    <div className="split">
      <section className="card">
        <div className="toolbar">
          <Link className="btn ghost" to="/lab">
            ← Lab
          </Link>
          <h1>Lab test list</h1>
          <input className="search" type="search" placeholder="Filter tests" value={filter} onChange={(e) => setFilter(e.target.value)} />
          {canEdit && (
            <button className="btn primary" onClick={() => open('new')}>
              + New test
            </button>
          )}
        </div>

        {notice && <p className="notice">{notice}</p>}

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
                  <td className="num">{t.parameters.length}</td>
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
      </section>

      {editing && (
        <form className="card panel wide" onSubmit={save} noValidate>
          <div className="panel-head">
            <h2>{editing === 'new' ? 'Add test' : editing.name}</h2>
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
                <div className="lab-param" key={i}>
                  <input placeholder="Haemoglobin" value={p.name} onChange={(e) => setParameter(i, 'name', e.target.value)} aria-label={`Parameter ${i + 1}`} />
                  <input placeholder="g/dL" value={p.unit ?? ''} onChange={(e) => setParameter(i, 'unit', e.target.value)} aria-label="Unit" />
                  <input placeholder="12 - 16" value={p.reference_range ?? ''} onChange={(e) => setParameter(i, 'reference_range', e.target.value)} aria-label="Reference range" />
                  <input value={p.method ?? ''} onChange={(e) => setParameter(i, 'method', e.target.value)} aria-label="Method" />
                  <button type="button" className="link danger" onClick={() => setParameters((rows) => (rows.length > 1 ? rows.filter((_, r) => r !== i) : [emptyParameter()]))}>
                    Remove
                  </button>
                </div>
              ))}
            </div>
            <button type="button" className="link add-medicine" onClick={() => setParameters((rows) => [...rows, emptyParameter()])}>
              + Add parameter
            </button>
            <p className="muted small">Write number ranges as "70 - 140" so high and low results are flagged automatically.</p>

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
  )
}
