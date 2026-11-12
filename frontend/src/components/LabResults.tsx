import { useEffect, useState } from 'react'
import { api, fieldErrors } from '../api'
import { useAuth } from '../auth'
import { clock, longDate } from '../format'
import type { LabOrder, LabOrderItem, LabResult } from '../types'

type Props = {
  orderId: number
  onClose: () => void
  onChange: () => void
}

/** Works out high/low when the reference range is two plain numbers, e.g. "70 - 140". */
export function flagFor(value: string, range: string | null): LabResult['flag'] {
  const match = range?.match(/^\s*(\d+(?:\.\d+)?)\s*[-–]\s*(\d+(?:\.\d+)?)\s*$/)
  const number = Number(value)
  if (!match || value.trim() === '' || Number.isNaN(number)) return null
  if (number < Number(match[1])) return 'low'
  if (number > Number(match[2])) return 'high'
  return null
}

type Draft = { values: Record<number, string>; remarks: string }

const draftOf = (item: LabOrderItem): Draft => ({
  values: Object.fromEntries((item.results ?? []).map((r) => [r.id, r.value ?? ''])),
  remarks: item.remarks ?? '',
})

export function LabResults({ orderId, onClose, onChange }: Props) {
  const { user } = useAuth()
  const canEdit = Boolean(user?.can_run_lab)
  const [order, setOrder] = useState<LabOrder | null>(null)
  const [drafts, setDrafts] = useState<Record<number, Draft>>({})
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  const apply = (next: LabOrder) => {
    setOrder(next)
    setDrafts(Object.fromEntries(next.items.map((item) => [item.id, draftOf(item)])))
  }

  useEffect(() => {
    api.get<LabOrder>(`/lab/orders/${orderId}`).then((res) => apply(res.data))
  }, [orderId])

  if (!order) return <aside className="card panel wide">Loading results…</aside>

  const save = async (item: LabOrderItem, complete?: boolean) => {
    setSaving(true)
    setError('')
    const draft = drafts[item.id]
    try {
      const res = await api.put<LabOrder>(`/lab/orders/${order.id}/items/${item.id}`, {
        remarks: draft.remarks || null,
        ...(complete === undefined ? {} : { complete }),
        results: (item.results ?? []).map((r) => ({ id: r.id, value: draft.values[r.id] || null, flag: flagFor(draft.values[r.id] ?? '', r.reference_range) })),
      })
      apply(res.data)
      onChange()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    } finally {
      setSaving(false)
    }
  }

  const setValue = (itemId: number, resultId: number, value: string) =>
    setDrafts((d) => ({ ...d, [itemId]: { ...d[itemId], values: { ...d[itemId].values, [resultId]: value } } }))

  return (
    <aside className="card panel wide">
      <div className="panel-head">
        <div>
          <h2>Results · {order.order_no}</h2>
          <span className="muted">
            {order.patient.name} · {order.patient.uhid} · {order.patient.age}y / {order.patient.gender.charAt(0).toUpperCase()}
          </span>
        </div>
        <div className="row">
          <a className="btn ghost" href={`/lab/orders/${order.id}/print`} target="_blank" rel="noreferrer">
            Print report
          </a>
          <button type="button" className="btn ghost" onClick={onClose}>
            Close
          </button>
        </div>
      </div>

      {error && <p className="form-error">{error}</p>}
      {!canEdit && <p className="muted">You can view results but not change them.</p>}

      {order.items.map((item) => {
        const draft = drafts[item.id]
        const reported = Boolean(item.reported_at)
        return (
          <section key={item.id} className="lab-test">
            <header>
              <strong>{item.test_name}</strong>
              {item.sample_type && <small className="muted"> · {item.sample_type}</small>}
              <span className={`pill ${reported ? 'ok' : 'status-arrived'}`}>
                {reported ? `Reported ${longDate(item.reported_at!)} ${clock(item.reported_at!)}` : 'Pending'}
              </span>
            </header>
            <table className="compact">
              <thead>
                <tr>
                  <th>Parameter</th>
                  <th>Result</th>
                  <th>Unit</th>
                  <th>Reference range</th>
                </tr>
              </thead>
              <tbody>
                {(item.results ?? []).map((r) => {
                  const flag = flagFor(draft.values[r.id] ?? '', r.reference_range)
                  return (
                    <tr key={r.id} className="static">
                      <td>{r.parameter}</td>
                      <td>
                        <div className="row">
                          <input
                            className={`result ${flag ?? ''}`}
                            value={draft.values[r.id] ?? ''}
                            disabled={!canEdit || reported}
                            onChange={(e) => setValue(item.id, r.id, e.target.value)}
                            aria-label={`Result for ${r.parameter}`}
                          />
                          {flag && <span className={`pill ${flag === 'high' ? 'off' : 'status-ongoing'}`}>{flag === 'high' ? 'High' : 'Low'}</span>}
                        </div>
                      </td>
                      <td>{r.unit ?? ''}</td>
                      <td>{r.reference_range ?? ''}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
            <input
              className="gap-top"
              placeholder="Remarks for this test (optional)"
              value={draft.remarks}
              disabled={!canEdit || reported}
              onChange={(e) => setDrafts((d) => ({ ...d, [item.id]: { ...d[item.id], remarks: e.target.value } }))}
              aria-label={`Remarks for ${item.test_name}`}
            />
            {canEdit && (
              <div className="panel-foot row">
                {reported ? (
                  <button className="btn ghost" disabled={saving} onClick={() => save(item, false)}>
                    Reopen to correct
                  </button>
                ) : (
                  <>
                    <button className="btn ghost" disabled={saving} onClick={() => save(item)}>
                      Save draft
                    </button>
                    <button className="btn primary" disabled={saving} onClick={() => save(item, true)}>
                      Mark reported
                    </button>
                  </>
                )}
              </div>
            )}
          </section>
        )
      })}
    </aside>
  )
}
