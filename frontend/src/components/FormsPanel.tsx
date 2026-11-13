import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../api'
import { longDate } from '../format'
import type { FormRecord, FormTemplate } from '../types'

type Values = Record<string, string | boolean>

type Props = {
  /** Where this stay's or visit's forms are listed and added, e.g. `/ipd/admissions/4/forms`. */
  url: string
  /** Which forms are offered: those designed for IPD cases or for the doctor's room. */
  use: 'ipd' | 'doctor'
  canEdit: boolean
}

/** Forms filled in for one IPD case or one visit: start one from a template, fill it, print it. */
export function FormsPanel({ url, use, canEdit }: Props) {
  const [templates, setTemplates] = useState<FormTemplate[]>([])
  const [records, setRecords] = useState<FormRecord[] | null>(null)
  const [templateId, setTemplateId] = useState('')
  const [open, setOpen] = useState<{ id: number | null; name: string; fields: FormTemplate['fields']; templateId?: number } | null>(null)
  const [values, setValues] = useState<Values>({})
  const [error, setError] = useState('')

  const load = useCallback(() => {
    api.get<FormRecord[]>(url).then((res) => setRecords(res.data))
  }, [url])
  useEffect(load, [load])
  useEffect(() => {
    api.get<FormTemplate[]>('/form-templates').then((res) => setTemplates(res.data.filter((t) => t.access === 'both' || t.access === use)))
  }, [use])

  const start = () => {
    const template = templates.find((t) => String(t.id) === templateId)
    if (!template) return
    setOpen({ id: null, name: template.name, fields: template.fields, templateId: template.id })
    setValues({})
    setError('')
  }
  const edit = (r: FormRecord) => {
    setOpen({ id: r.id, name: r.name, fields: r.fields })
    setValues({ ...(r.values ?? {}) })
    setError('')
  }
  const save = async (e: FormEvent) => {
    e.preventDefault()
    if (!open) return
    try {
      await (open.id ? api.put(`/forms/${open.id}`, { values }) : api.post(url, { form_template_id: open.templateId, values }))
      setOpen(null)
      load()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    }
  }
  const remove = async (r: FormRecord) => {
    if (!window.confirm(`Delete this "${r.name}" form?`)) return
    await api.delete(`/forms/${r.id}`)
    load()
  }
  const set = (id: string, value: string | boolean) => setValues((v) => ({ ...v, [id]: value }))

  return (
    <div>
      {canEdit && !open && (
        <div className="row gap-bottom">
          <select className="narrow" value={templateId} onChange={(e) => setTemplateId(e.target.value)} aria-label="Form to add">
            <option value="">{templates.length ? 'Select a form…' : 'No forms have been designed yet'}</option>
            {templates.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}
              </option>
            ))}
          </select>
          <button className="btn primary" disabled={!templateId} onClick={start}>
            + Add form
          </button>
          {templates.length === 0 && <span className="muted small">Forms are designed under Admin → Forms.</span>}
        </div>
      )}

      {open && (
        <form className="note-form" onSubmit={save}>
          <h3 className="flush">{open.name}</h3>
          <fieldset disabled={!canEdit} className="filled-form">
            {open.fields.map((f) =>
              f.type === 'heading' ? (
                <h4 key={f.id}>{f.label}</h4>
              ) : f.type === 'signature' ? (
                <p key={f.id} className="muted small">
                  {f.label}: signed on the printed form
                </p>
              ) : f.type === 'checkbox' ? (
                <label className="check" key={f.id}>
                  <input type="checkbox" checked={Boolean(values[f.id])} onChange={(e) => set(f.id, e.target.checked)} />
                  {f.label}
                </label>
              ) : (
                <label className="field" key={f.id}>
                  <span className="field-label">{f.label}</span>
                  {f.type === 'textarea' ? (
                    <textarea rows={3} value={String(values[f.id] ?? '')} onChange={(e) => set(f.id, e.target.value)} />
                  ) : f.type === 'choice' ? (
                    <select value={String(values[f.id] ?? '')} onChange={(e) => set(f.id, e.target.value)}>
                      <option value="">Not answered</option>
                      {(f.options ?? []).map((o) => (
                        <option key={o} value={o}>
                          {o}
                        </option>
                      ))}
                    </select>
                  ) : (
                    <input type={f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : 'text'} step="any" value={String(values[f.id] ?? '')} onChange={(e) => set(f.id, e.target.value)} />
                  )}
                </label>
              ),
            )}
          </fieldset>
          {error && <p className="form-error">{error}</p>}
          <div className="panel-foot row">
            <button type="button" className="btn ghost" onClick={() => setOpen(null)}>
              {canEdit ? 'Cancel' : 'Close'}
            </button>
            {canEdit && <button className="btn primary">Save form</button>}
          </div>
        </form>
      )}

      {records === null ? (
        <p className="loading">Loading forms…</p>
      ) : (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Form</th>
                <th>Filled in</th>
                <th>By</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {records.map((r) => (
                <tr key={r.id} className={`static ${open?.id === r.id ? 'selected' : ''}`}>
                  <td>
                    <strong>{r.name}</strong>
                  </td>
                  <td>{longDate(r.created_at)}</td>
                  <td>{r.author?.name ?? '—'}</td>
                  <td className="num actions">
                    <button className="link" onClick={() => edit(r)}>
                      {canEdit ? 'Edit' : 'View'}
                    </button>
                    <a className="link" href={`/forms/${r.id}/print`} target="_blank" rel="noreferrer">
                      Print
                    </a>
                    {canEdit && (
                      <button className="link danger" onClick={() => remove(r)}>
                        Delete
                      </button>
                    )}
                  </td>
                </tr>
              ))}
              {records.length === 0 && (
                <tr className="empty">
                  <td colSpan={4}>No forms have been filled in {use === 'ipd' ? 'for this case' : 'for this visit'}.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
