import { useEffect, useState } from 'react'
import { api, fieldErrors } from '../api'
import { useAuth } from '../auth'
import { clock, longDate, STAGE_CLASS, STAGE_LABEL } from '../format'
import type { LabOrder, LabOrderFile, LabOrderItem, LabResult, LabSettings } from '../types'

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

type Draft = { values: Record<number, string>; remarks: string; text: string }

const draftOf = (item: LabOrderItem): Draft => ({
  values: Object.fromEntries((item.results ?? []).map((r) => [r.id, r.value ?? ''])),
  remarks: item.remarks ?? '',
  text: item.report_text ?? '',
})

const stamp = (at: string) => `${longDate(at)} ${clock(at)}`

export function LabResults({ orderId, onClose, onChange }: Props) {
  const { user } = useAuth()
  const canEdit = Boolean(user?.can_run_lab)
  const [order, setOrder] = useState<LabOrder | null>(null)
  const [settings, setSettings] = useState<LabSettings | null>(null)
  const [drafts, setDrafts] = useState<Record<number, Draft>>({})
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const [letterhead, setLetterhead] = useState(false)
  const [skip, setSkip] = useState<number[]>([])
  // Follows the lab's preference until the box is ticked or unticked here.
  const [tabularChoice, setTabularChoice] = useState<boolean | null>(null)
  const [fileFor, setFileFor] = useState('')
  const [fileTitle, setFileTitle] = useState('')
  const [fileError, setFileError] = useState('')

  /** Shows the saved order. Only the test that was just saved has its typed values replaced, so work on the others is kept. */
  const apply = (next: LabOrder, reset: number | 'all' | null) => {
    setOrder(next)
    setDrafts((current) => Object.fromEntries(next.items.map((item) => [item.id, reset === 'all' || reset === item.id || !current[item.id] ? draftOf(item) : current[item.id]])))
  }

  useEffect(() => {
    api.get<LabOrder>(`/lab/orders/${orderId}`).then((res) => apply(res.data, 'all'))
    api.get<LabSettings>('/lab/settings').then((res) => setSettings(res.data))
  }, [orderId])

  if (!order) return <aside className="card panel wide">Loading results…</aside>

  const send = async (url: string, body: object, reset: number | null = null) => {
    setSaving(true)
    setError('')
    try {
      apply((await api.put<LabOrder>(`/lab/orders/${order.id}/items/${url}`, body)).data, reset)
      onChange()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    } finally {
      setSaving(false)
    }
  }

  const save = (item: LabOrderItem, complete?: boolean) => {
    const draft = drafts[item.id]
    return send(String(item.id), {
      remarks: draft.remarks || null,
      ...(item.kind === 'text' ? { report_text: draft.text || null } : {}),
      ...(complete === undefined ? {} : { complete }),
      results: (item.results ?? [])
        .filter((r) => !r.is_heading)
        .map((r) => ({ id: r.id, value: draft.values[r.id] || null, flag: flagFor(draft.values[r.id] ?? '', r.reference_range) })),
    }, item.id)
  }

  const upload = async (file: File | undefined) => {
    if (!file) return
    const body = new FormData()
    body.append('file', file)
    if (fileTitle.trim()) body.append('title', fileTitle.trim())
    if (fileFor) body.append('lab_order_item_id', fileFor)
    setFileError('')
    try {
      setOrder((await api.post<LabOrder>(`/lab/orders/${order.id}/files`, body)).data)
      setFileTitle('')
    } catch (err) {
      setFileError(Object.values(fieldErrors(err))[0])
    }
  }
  const openFile = async (f: LabOrderFile) => {
    const res = await api.get<Blob>(`/lab/files/${f.id}/download`, { responseType: 'blob' })
    window.open(URL.createObjectURL(res.data), '_blank')
  }
  const removeFile = async (f: LabOrderFile) => {
    if (!window.confirm(`Remove "${f.title}" from this order?`)) return
    setOrder((await api.delete<LabOrder>(`/lab/files/${f.id}`)).data)
  }
  const tabular = tabularChoice ?? Boolean(settings?.tabular_print)

  const setDraft = (itemId: number, change: Partial<Draft>) => setDrafts((d) => ({ ...d, [itemId]: { ...d[itemId], ...change } }))

  // What may go on the printed report: approved tests if the lab insists on approval, otherwise reported ones.
  const printable = order.items.filter((item) => (settings?.approval_required ? item.approved_at : item.reported_at))
  const chosen = printable.filter((item) => !skip.includes(item.id))
  const unpaid = Boolean(settings && !settings.allow_print_without_payment && order.bill && order.bill.due > 0)
  const printQuery = new URLSearchParams({
    ...(letterhead ? { letterhead: '1' } : {}),
    ...(tabular ? { tabular: '1' } : {}),
    ...(chosen.length < printable.length ? { items: chosen.map((item) => item.id).join(',') } : {}),
  }).toString()
  const printUrl = `/lab/orders/${order.id}/print${printQuery ? `?${printQuery}` : ''}`

  return (
    <aside className="card panel wide">
      <div className="panel-head">
        <div>
          <h2>Results · {order.order_no}</h2>
          <span className="muted">
            {order.patient.name} · {order.patient.uhid} · {order.patient.age}y / {order.patient.gender.charAt(0).toUpperCase()}
          </span>
        </div>
        <button type="button" className="btn ghost" onClick={onClose}>
          Close
        </button>
      </div>

      <div className="print-bar">
        <label className="check flush">
          <input type="checkbox" checked={letterhead} onChange={(e) => setLetterhead(e.target.checked)} />
          Print on letterhead
        </label>
        <label className="check flush" title="All tests in one table, instead of a section for each test">
          <input type="checkbox" checked={tabular} onChange={(e) => setTabularChoice(e.target.checked)} />
          Tabular
        </label>
        <span className="muted small grow">
          {unpaid
            ? 'The bill must be paid before this report can be printed.'
            : printable.length === 0
              ? settings?.approval_required
                ? 'Nothing to print until a result is approved.'
                : 'Nothing to print until a result is reported.'
              : `${chosen.length} of ${order.items.length} test${order.items.length === 1 ? '' : 's'} will be printed. Untick a test below to leave it out.`}
        </span>
        {unpaid || chosen.length === 0 ? (
          <button className="btn primary" disabled>
            Print report
          </button>
        ) : (
          <a className="btn primary" href={printUrl} target="_blank" rel="noreferrer">
            Print report
          </a>
        )}
      </div>

      {error && <p className="form-error">{error}</p>}
      {!canEdit && <p className="muted">You can view results but not change them.</p>}

      {order.items.map((item) => {
        const draft = drafts[item.id]
        const reported = Boolean(item.reported_at)
        const locked = !canEdit || reported
        return (
          <section key={item.id} className="lab-test">
            <header>
              {printable.includes(item) && (
                <input
                  type="checkbox"
                  className="auto"
                  checked={!skip.includes(item.id)}
                  onChange={() => setSkip((ids) => (ids.includes(item.id) ? ids.filter((i) => i !== item.id) : [...ids, item.id]))}
                  aria-label={`Print ${item.test_name}`}
                  title="Include in the printed report"
                />
              )}
              <strong>{item.test_name}</strong>
              {item.sample_type && <small className="muted"> · {item.sample_type}</small>}
              {item.package_name && <small className="muted"> · {item.package_name}</small>}
              <span className={`pill ${STAGE_CLASS[item.stage]}`}>{STAGE_LABEL[item.stage]}</span>
            </header>

            {(item.collected_at || item.reported_at) && (
              <p className="muted small lab-trail">
                {item.collected_at && <>Sample collected {stamp(item.collected_at)}</>}
                {item.reported_at && <> · Reported {stamp(item.reported_at)}{item.reporter ? ` by ${item.reporter.name}` : ''}</>}
                {item.approved_at && <> · Approved {stamp(item.approved_at)}{item.approver ? ` by ${item.approver.name}` : ''}</>}
              </p>
            )}

            {item.kind === 'text' ? (
              <textarea
                rows={8}
                placeholder="Write the report here"
                value={draft.text}
                disabled={locked}
                onChange={(e) => setDraft(item.id, { text: e.target.value })}
                aria-label={`Report for ${item.test_name}`}
              />
            ) : (
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
                    if (r.is_heading) {
                      return (
                        <tr key={r.id} className="static lab-heading">
                          <td colSpan={4}>{r.parameter}</td>
                        </tr>
                      )
                    }
                    const flag = flagFor(draft.values[r.id] ?? '', r.reference_range)
                    return (
                      <tr key={r.id} className="static">
                        <td>{r.parameter}</td>
                        <td>
                          <div className="row">
                            <input
                              className={`result ${flag ?? ''}`}
                              value={draft.values[r.id] ?? ''}
                              disabled={locked}
                              onChange={(e) => setDraft(item.id, { values: { ...draft.values, [r.id]: e.target.value } })}
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
            )}
            <textarea
              className="gap-top"
              rows={draft.remarks.includes('\n') ? 3 : 1}
              placeholder="Comment printed under this test (optional)"
              value={draft.remarks}
              disabled={locked}
              onChange={(e) => setDraft(item.id, { remarks: e.target.value })}
              aria-label={`Comment for ${item.test_name}`}
            />
            {canEdit && (
              <div className="panel-foot row">
                {item.stage === 'booked' && (
                  <button className="btn ghost" disabled={saving} onClick={() => send(`${item.id}/collect`, { collected: true })}>
                    Sample collected
                  </button>
                )}
                {item.stage === 'collected' && (
                  <button className="link" disabled={saving} onClick={() => send(`${item.id}/collect`, { collected: false })}>
                    Undo collection
                  </button>
                )}
                {!reported && (
                  <>
                    <button className="btn ghost" disabled={saving} onClick={() => save(item)}>
                      Save draft
                    </button>
                    <button className="btn primary" disabled={saving} onClick={() => save(item, true)}>
                      Mark reported
                    </button>
                  </>
                )}
                {reported && (
                  <button className="btn ghost" disabled={saving} onClick={() => save(item, false)}>
                    Reopen to correct
                  </button>
                )}
                {item.stage === 'reported' && (
                  <button className="btn primary" disabled={saving} onClick={() => send(`${item.id}/approve`, { approved: true })}>
                    Approve
                  </button>
                )}
                {item.stage === 'approved' && (
                  <button className="link" disabled={saving} onClick={() => send(`${item.id}/approve`, { approved: false })}>
                    Withdraw approval
                  </button>
                )}
              </div>
            )}
          </section>
        )
      })}

      <section className="lab-test">
        <header>
          <strong>Result files</strong>
          <small className="muted"> · an outside lab's report, an ECG strip, a scanned sheet</small>
        </header>
        <ul className="history">
          {(order.files ?? []).map((f) => (
            <li key={f.id}>
              <span>
                <button type="button" className="link" onClick={() => openFile(f)}>
                  {f.title}
                </button>
                <small className="muted">
                  {' '}
                  {order.items.find((i) => i.id === f.lab_order_item_id)?.test_name ?? 'Whole order'} · {Math.max(1, Math.round(f.size / 1024))} KB
                  {f.uploader ? ` · ${f.uploader.name}` : ''} · {longDate(f.created_at)}
                </small>
              </span>
              {canEdit && (
                <button type="button" className="link danger" onClick={() => removeFile(f)}>
                  Remove
                </button>
              )}
            </li>
          ))}
          {(order.files ?? []).length === 0 && <li className="muted">No files attached.</li>}
        </ul>
        {canEdit && (
          <div className="file-add">
            <input placeholder="Title (optional)" value={fileTitle} onChange={(e) => setFileTitle(e.target.value)} aria-label="File title" />
            <select value={fileFor} onChange={(e) => setFileFor(e.target.value)} aria-label="Which test the file is for">
              <option value="">Whole order</option>
              {order.items.map((i) => (
                <option key={i.id} value={i.id}>
                  {i.test_name}
                </option>
              ))}
            </select>
            <label className="btn ghost">
              + Attach file
              <input
                type="file"
                hidden
                accept="application/pdf,image/jpeg,image/png,image/webp"
                onChange={(e) => {
                  upload(e.target.files?.[0])
                  e.target.value = ''
                }}
              />
            </label>
          </div>
        )}
        {fileError && <p className="form-error">{fileError}</p>}
        <p className="muted small">PDF or image, up to 10 MB. Files are kept with the order and are not part of the printed report.</p>
      </section>
    </aside>
  )
}
