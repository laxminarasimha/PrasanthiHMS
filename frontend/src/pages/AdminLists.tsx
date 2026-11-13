import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import type { AdminEntry } from '../types'

type Field = { key: string; label: string; options?: { value: string; label: string }[] }

export const LISTS: Record<'department' | 'vendor' | 'payer', { title: string; add: string; hint: string; fields: Field[] }> = {
  department: {
    title: 'Departments',
    add: 'Add department',
    hint: 'Offered in the Department box when adding staff.',
    fields: [{ key: 'head', label: 'Head of department' }, { key: 'type', label: 'Type' }, { key: 'location', label: 'Location' }],
  },
  vendor: {
    title: 'Vendors',
    add: 'Add vendor',
    hint: 'Suppliers and service providers other than medicine stockists, kept here for reference.',
    fields: [{ key: 'phone', label: 'Phone' }, { key: 'gstin', label: 'GSTIN' }, { key: 'supplies', label: 'What they supply' }, { key: 'address', label: 'Address' }],
  },
  payer: {
    title: 'Insurers and payers',
    add: 'Add payer',
    hint: 'Insurance companies, employers or schemes that settle bills. One can be chosen when a patient is admitted.',
    fields: [
      { key: 'contact', label: 'Contact person or phone' },
      { key: 'billing_mode', label: 'Billing', options: [{ value: 'cash', label: 'Patient pays, claims later' }, { value: 'credit', label: 'Credit: payer settles the bill' }] },
      { key: 'notes', label: 'Notes' },
    ],
  },
}

/** One of the small hospital-wide lists: departments, vendors or payers. */
export function AdminList({ kind }: { kind: keyof typeof LISTS }) {
  const config = LISTS[kind]
  const [rows, setRows] = useState<AdminEntry[]>([])
  const [editing, setEditing] = useState<number | 'new' | null>(null)
  const [name, setName] = useState('')
  const [details, setDetails] = useState<Record<string, string>>({})
  const [active, setActive] = useState(true)
  const [errors, setErrors] = useState<FieldErrors>({})

  const load = useCallback(() => {
    api.get<AdminEntry[]>(`/admin-lists/${kind}`, { params: { all: 1 } }).then((res) => setRows(res.data))
  }, [kind])
  useEffect(load, [load])

  const open = (row: AdminEntry | null) => {
    setEditing(row?.id ?? 'new')
    setName(row?.name ?? '')
    setDetails({ ...(row?.details ?? {}) })
    setActive(row?.is_active ?? true)
    setErrors({})
  }
  const save = async (e: FormEvent) => {
    e.preventDefault()
    const body = { name, details, is_active: active }
    try {
      await (editing === 'new' ? api.post(`/admin-lists/${kind}`, body) : api.put(`/admin-lists/${kind}/${editing}`, body))
      setEditing(null)
      load()
    } catch (err) {
      setErrors(fieldErrors(err))
    }
  }
  const show = (row: AdminEntry, field: Field) => {
    const value = row.details?.[field.key]
    return (field.options?.find((o) => o.value === value)?.label ?? value) || '—'
  }

  return (
    <div>
      <div className="row gap-bottom">
        <p className="muted small grow">{config.hint}</p>
        <button className="btn primary" onClick={() => open(null)}>
          + {config.add}
        </button>
      </div>

      {editing && (
        <form className="note-form" onSubmit={save} noValidate>
          <div className="grid">
            <label className="field">
              <span className="field-label">Name</span>
              <input value={name} onChange={(e) => setName(e.target.value)} autoFocus aria-invalid={Boolean(errors.name)} />
              {errors.name && <span className="field-error">{errors.name}</span>}
            </label>
            {config.fields.map((field) => (
              <label className="field" key={field.key}>
                <span className="field-label">{field.label}</span>
                {field.options ? (
                  <select value={details[field.key] ?? ''} onChange={(e) => setDetails({ ...details, [field.key]: e.target.value })}>
                    <option value="">Not set</option>
                    {field.options.map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </select>
                ) : (
                  <input value={details[field.key] ?? ''} onChange={(e) => setDetails({ ...details, [field.key]: e.target.value })} />
                )}
              </label>
            ))}
          </div>
          {editing !== 'new' && (
            <label className="check">
              <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />
              In use (untick to hide it from pick lists without deleting it)
            </label>
          )}
          {errors._ && <p className="form-error">{errors._}</p>}
          <div className="panel-foot row">
            <button type="button" className="btn ghost" onClick={() => setEditing(null)}>
              Cancel
            </button>
            <button className="btn primary" disabled={!name.trim()}>
              Save
            </button>
          </div>
        </form>
      )}

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Name</th>
              {config.fields.map((f) => (
                <th key={f.key}>{f.label}</th>
              ))}
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id} onClick={() => open(row)} className={editing === row.id ? 'selected' : ''}>
                <td>
                  <strong>{row.name}</strong>
                </td>
                {config.fields.map((f) => (
                  <td key={f.key} className="wrap">
                    {show(row, f)}
                  </td>
                ))}
                <td>
                  <span className={row.is_active ? 'pill ok' : 'pill off'}>{row.is_active ? 'In use' : 'Hidden'}</span>
                </td>
              </tr>
            ))}
            {rows.length === 0 && (
              <tr className="empty">
                <td colSpan={config.fields.length + 2}>Nothing in this list yet.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
