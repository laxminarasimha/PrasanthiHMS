import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { useAuth } from '../auth'
import { TextField } from '../components/Field'
import { LabTabs } from '../components/LabTabs'
import { rupees } from '../format'
import type { LabCatalogueTest, LabPackage } from '../types'

/** Health packages: several tests sold together for one price. */
export function LabPackages() {
  const { user } = useAuth()
  const canEdit = Boolean(user?.can_run_lab)
  const [packages, setPackages] = useState<LabPackage[]>([])
  const [tests, setTests] = useState<LabCatalogueTest[]>([])
  const [editing, setEditing] = useState<LabPackage | 'new' | null>(null)
  const [name, setName] = useState('')
  const [price, setPrice] = useState('')
  const [active, setActive] = useState(true)
  const [chosen, setChosen] = useState<number[]>([])
  const [filter, setFilter] = useState('')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState('')

  const load = useCallback(() => {
    api.get<LabPackage[]>('/lab/packages', { params: { all: 1 } }).then((res) => setPackages(res.data))
  }, [])
  useEffect(() => {
    load()
    api.get<LabCatalogueTest[]>('/lab/tests').then((res) => setTests(res.data))
  }, [load])

  const open = (target: LabPackage | 'new') => {
    setEditing(target)
    setErrors({})
    setNotice('')
    setFilter('')
    setName(target === 'new' ? '' : target.name)
    setPrice(target === 'new' ? '' : String(target.price))
    setActive(target === 'new' ? true : target.is_active)
    setChosen(target === 'new' ? [] : target.tests.map((t) => t.id))
  }

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    const payload = { name, price, is_active: active, test_ids: chosen }
    try {
      const res = editing === 'new' ? await api.post<LabPackage>('/lab/packages', payload) : await api.put<LabPackage>(`/lab/packages/${(editing as LabPackage).id}`, payload)
      setNotice(`${res.data.name} was ${editing === 'new' ? 'added' : 'updated'}.`)
      setEditing(null)
      load()
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  const separately = tests.filter((t) => chosen.includes(t.id)).reduce((sum, t) => sum + t.price, 0)
  const visible = tests.filter((t) => t.name.toLowerCase().includes(filter.trim().toLowerCase()))

  return (
    <>
      <LabTabs />
      <div className="split">
        <section className="card">
          <div className="toolbar">
            <h1>Packages</h1>
            <span className="grow" />
            {canEdit && (
              <button className="btn primary" onClick={() => open('new')}>
                + New package
              </button>
            )}
          </div>
          {notice && <p className="notice">{notice}</p>}
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Package</th>
                  <th>Tests included</th>
                  <th className="num">If bought separately</th>
                  <th className="num">Package price</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {packages.map((p) => (
                  <tr key={p.id} onClick={() => open(p)} className={editing !== 'new' && editing?.id === p.id ? 'selected' : ''}>
                    <td>
                      <strong>{p.name}</strong>
                    </td>
                    <td className="wrap">{p.tests.map((t) => t.name).join(', ')}</td>
                    <td className="num">{rupees(p.tests.reduce((sum, t) => sum + t.price, 0))}</td>
                    <td className="num">{rupees(p.price)}</td>
                    <td>
                      <span className={p.is_active ? 'pill ok' : 'pill off'}>{p.is_active ? 'Active' : 'Hidden'}</span>
                    </td>
                  </tr>
                ))}
                {packages.length === 0 && (
                  <tr className="empty">
                    <td colSpan={5}>No packages yet. A package sells several tests together for one price.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </section>

        {editing && (
          <form className="card panel" onSubmit={save} noValidate>
            <div className="panel-head">
              <h2>{editing === 'new' ? 'Add package' : editing.name}</h2>
              <button type="button" className="btn ghost" onClick={() => setEditing(null)}>
                Close
              </button>
            </div>
            <fieldset disabled={!canEdit}>
              <TextField label="Package name" required placeholder="Master health check" value={name} onChange={setName} error={errors.name} autoFocus />
              <TextField label="Package price (₹)" required type="number" min={0} step="0.01" value={price} onChange={setPrice} error={errors.price} />
              <h3>Tests in this package</h3>
              <input type="search" placeholder="Filter tests" value={filter} onChange={(e) => setFilter(e.target.value)} aria-label="Filter tests" />
              <ul className="checklist">
                {visible.map((t) => (
                  <li key={t.id}>
                    <label>
                      <input type="checkbox" checked={chosen.includes(t.id)} onChange={() => setChosen((ids) => (ids.includes(t.id) ? ids.filter((i) => i !== t.id) : [...ids, t.id]))} />
                      <span className="grow">{t.name}</span>
                      <span className="mono">{rupees(t.price)}</span>
                    </label>
                  </li>
                ))}
              </ul>
              {errors.test_ids && <span className="field-error">{errors.test_ids}</span>}
              <p className="muted small">
                {chosen.length} tests · {rupees(separately)} if bought separately
              </p>
              {editing !== 'new' && (
                <label className="check">
                  <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />
                  Active (untick to hide it from new orders)
                </label>
              )}
            </fieldset>
            {errors._ && <p className="form-error">{errors._}</p>}
            {canEdit && (
              <div className="panel-foot">
                <button className="btn primary" disabled={saving}>
                  {saving ? 'Saving…' : editing === 'new' ? 'Add package' : 'Save changes'}
                </button>
              </div>
            )}
          </form>
        )}
      </div>
    </>
  )
}
