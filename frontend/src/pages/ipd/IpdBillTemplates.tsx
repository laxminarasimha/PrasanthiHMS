import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../../api'
import { rupees } from '../../format'
import type { IpdBillTemplate, IpdService } from '../../types'

type Row = { ipd_service_id: string; quantity: string }
type Draft = { id: number | null; name: string; rows: Row[] }

/** Named sets of IPD services that can be added to a bill in one go. */
export function IpdBillTemplates({ services }: { services: IpdService[] }) {
  const [templates, setTemplates] = useState<IpdBillTemplate[]>([])
  const [draft, setDraft] = useState<Draft | null>(null)
  const [error, setError] = useState('')
  const active = services.filter((s) => s.is_active)
  const price = (id: number | string) => services.find((s) => String(s.id) === String(id))?.price ?? 0

  const load = useCallback(() => {
    api.get<IpdBillTemplate[]>('/ipd/templates').then((res) => setTemplates(res.data))
  }, [])
  useEffect(load, [load])

  const open = (t: IpdBillTemplate | null) => {
    setError('')
    setDraft(t ? { id: t.id, name: t.name, rows: t.items.map((i) => ({ ipd_service_id: String(i.ipd_service_id), quantity: String(i.quantity) })) } : { id: null, name: '', rows: [{ ipd_service_id: '', quantity: '1' }] })
  }
  const setRow = (index: number, change: Partial<Row>) => setDraft((d) => (d ? { ...d, rows: d.rows.map((r, i) => (i === index ? { ...r, ...change } : r)) } : d))

  const save = async (e: FormEvent) => {
    e.preventDefault()
    if (!draft) return
    const body = { name: draft.name, items: draft.rows.filter((r) => r.ipd_service_id).map((r) => ({ ipd_service_id: Number(r.ipd_service_id), quantity: Number(r.quantity || 1) })) }
    try {
      await (draft.id ? api.put(`/ipd/templates/${draft.id}`, body) : api.post('/ipd/templates', body))
      setDraft(null)
      load()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    }
  }
  const remove = async (t: IpdBillTemplate) => {
    if (!window.confirm(`Delete the template "${t.name}"?`)) return
    await api.delete(`/ipd/templates/${t.id}`)
    load()
  }

  return (
    <section className="card">
      <div className="toolbar">
        <h2 className="grow">Bill templates</h2>
        <button className="btn primary" onClick={() => open(null)} disabled={active.length === 0}>
          + Add template
        </button>
      </div>
      <p className="muted small">A template is a set of IPD services, such as a surgery package. On a case, choosing it adds all of them to the bill at today's prices.</p>

      {draft && (
        <form className="note-form" onSubmit={save}>
          <label className="field">
            <span className="field-label">Template name</span>
            <input required value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} autoFocus />
          </label>
          {draft.rows.map((row, i) => (
            <div className="inv-row template-row" key={i}>
              <select value={row.ipd_service_id} onChange={(e) => setRow(i, { ipd_service_id: e.target.value })} aria-label={`Service ${i + 1}`}>
                <option value="">Choose a service…</option>
                {active.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name} — {rupees(s.price)}
                  </option>
                ))}
              </select>
              <input type="number" min={0.5} step="0.5" value={row.quantity} onChange={(e) => setRow(i, { quantity: e.target.value })} aria-label={`Quantity ${i + 1}`} />
              <span className="mono num">{rupees(price(row.ipd_service_id) * Number(row.quantity || 0))}</span>
              <button type="button" className="link danger" onClick={() => setDraft({ ...draft, rows: draft.rows.length > 1 ? draft.rows.filter((_, r) => r !== i) : draft.rows })}>
                Remove
              </button>
            </div>
          ))}
          <button type="button" className="link" onClick={() => setDraft({ ...draft, rows: [...draft.rows, { ipd_service_id: '', quantity: '1' }] })}>
            + Add another service
          </button>
          {error && <p className="form-error">{error}</p>}
          <div className="panel-foot row">
            <button type="button" className="btn ghost" onClick={() => setDraft(null)}>
              Cancel
            </button>
            <button className="btn primary" disabled={!draft.name.trim() || !draft.rows.some((r) => r.ipd_service_id)}>
              Save template
            </button>
          </div>
        </form>
      )}

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Template</th>
              <th>Services</th>
              <th className="num">Value today</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {templates.map((t) => (
              <tr key={t.id} className="static">
                <td>
                  <strong>{t.name}</strong>
                </td>
                <td className="wrap">{t.items.map((i) => `${services.find((s) => s.id === i.ipd_service_id)?.name ?? 'Removed service'} × ${i.quantity}`).join(', ')}</td>
                <td className="num">{rupees(t.items.reduce((sum, i) => sum + price(i.ipd_service_id) * i.quantity, 0))}</td>
                <td className="num actions">
                  <button className="link" onClick={() => open(t)}>
                    Edit
                  </button>
                  <button className="link danger" onClick={() => remove(t)}>
                    Delete
                  </button>
                </td>
              </tr>
            ))}
            {templates.length === 0 && (
              <tr className="empty">
                <td colSpan={4}>{active.length === 0 ? 'Add IPD services first; a template is made from them.' : 'No bill templates yet.'}</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </section>
  )
}
