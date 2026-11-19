import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { SelectField, TextField } from '../components/Field'
import { useChoiceInAddress } from '../rangeInAddress'
import { BASIS_LABEL, KIND_LABEL, PAY_BASIS_LABEL, inr, monthlyPreview, ruleInWords, type Part, type PartKind, type Rule, type Salary, type SalaryRow } from './pay'
import { addMonths, monthName, thisMonth, type StaffBrief } from './shared'
import { WorkforceTabs } from './WorkforceTabs'

type Parts = { components: Part[]; bases: Record<PartKind, string[]>; settings: { salary_needs_approval: boolean } }

/** What each person is paid, and the parts salaries are made of. */
export function WorkforceSalaries() {
  const [view, setView] = useChoiceInAddress<'salaries' | 'parts'>('view', 'salaries', ['salaries', 'parts'] as const)
  const [parts, setParts] = useState<Parts | null>(null)
  const [failed, setFailed] = useState('')
  const loadParts = useCallback(() => {
    api
      .get<Parts>('/workforce/pay/components')
      .then((res) => setParts(res.data))
      .catch(() => setFailed('Could not load the parts salaries are made of. Check the connection and try again.'))
  }, [])
  useEffect(loadParts, [loadParts])

  return (
    <>
      <WorkforceTabs />
      <section className="card">
        <div className="toolbar">
          <h1>Salaries</h1>
          <div className="tabs flush" role="group" aria-label="What to show">
            <button className={view === 'salaries' ? 'active' : ''} onClick={() => setView('salaries')}>
              Who is paid what
            </button>
            <button className={view === 'parts' ? 'active' : ''} onClick={() => setView('parts')}>
              Parts of a salary
            </button>
          </div>
        </div>
        {failed && <div className="form-error">{failed}</div>}
        {view === 'salaries' ? <SalaryList parts={parts} onOpenParts={() => setView('parts')} /> : <PartList parts={parts} onChanged={setParts} />}
      </section>
    </>
  )
}

function SalaryList({ parts, onOpenParts }: { parts: Parts | null; onOpenParts: () => void }) {
  const [rows, setRows] = useState<{ rows: SalaryRow[]; can_edit: boolean } | null>(null)
  const [failed, setFailed] = useState('')
  const [open, setOpen] = useState<StaffBrief | null>(null)
  const load = useCallback(() => {
    api
      .get<{ rows: SalaryRow[]; can_edit: boolean }>('/workforce/pay/salaries')
      .then((res) => {
        setRows(res.data)
        setFailed('')
      })
      .catch(() => setFailed('Could not load the salaries. Check the connection and try again.'))
  }, [])
  useEffect(load, [load])
  const noParts = parts !== null && parts.components.length === 0

  return (
    <>
      {failed && <div className="form-error">{failed}</div>}
      {noParts && (
        <p className="notice">
          Before a salary can be entered, say what salaries are made of (basic pay, allowances, deductions).{' '}
          <button className="link" onClick={onOpenParts}>
            Open Parts of a salary
          </button>
        </p>
      )}
      <div className={open ? 'split' : ''}>
        <div className="table-wrap grow">
          <table>
            <thead>
              <tr>
                <th>Who</th>
                <th>Paid</th>
                <th className="num">For a full month</th>
                <th>Since</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {rows?.rows.map((r) => (
                <tr key={r.id} className={open?.id === r.id ? 'selected' : ''} onClick={() => setOpen(r)}>
                  <td>
                    <strong>{r.name}</strong>
                    <small className="muted block">{[r.employee_no, r.designation, r.department?.name].filter(Boolean).join(' · ')}</small>
                  </td>
                  <td>{r.current ? PAY_BASIS_LABEL[r.current.pay_basis] : <span className="muted">No salary set</span>}</td>
                  <td className="num">{r.current ? inr(r.current.monthly) : '—'}</td>
                  <td>
                    {r.current ? monthName(r.current.effective_from.slice(0, 7)) : '—'}
                    {r.next && <small className="muted block">{inr(r.next.monthly)} from {monthName(r.next.effective_from.slice(0, 7))}</small>}
                  </td>
                  <td className="num">{r.waiting > 0 && <span className="pill status-arrived">Waiting for approval</span>}</td>
                </tr>
              ))}
              {rows && rows.rows.length === 0 && (
                <tr className="empty">
                  <td colSpan={5}>No staff here yet. Add them under Staff first.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        {open && <SalaryPanel key={open.id} person={open} parts={parts?.components ?? []} needsApproval={parts?.settings.salary_needs_approval ?? true} onClose={() => setOpen(null)} onChanged={load} />}
      </div>
    </>
  )
}

/** One person's salary history, and the form for a new salary from a month. */
function SalaryPanel({ person, parts, needsApproval, onClose, onChanged }: { person: StaffBrief; parts: Part[]; needsApproval: boolean; onClose: () => void; onChanged: () => void }) {
  const [data, setData] = useState<{ can_edit: boolean; structures: Salary[] } | null>(null)
  const [failed, setFailed] = useState('')
  const [notice, setNotice] = useState('')
  const [adding, setAdding] = useState(false)
  const [withdrawing, setWithdrawing] = useState(0)
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const load = useCallback(() => {
    api
      .get<{ can_edit: boolean; structures: Salary[] }>(`/workforce/pay/salaries/${person.id}`)
      .then((res) => setData(res.data))
      .catch(() => setFailed('Could not open this salary.'))
  }, [person.id])
  useEffect(load, [load])

  const act = async (path: string, body: Record<string, unknown>, done: string) => {
    setBusy(true)
    setNotice('')
    try {
      await api.post(`/workforce/pay/structures/${path}`, body)
      setNotice(done)
      setWithdrawing(0)
      setReason('')
      load()
      onChanged()
    } catch (err) {
      setNotice(Object.values(fieldErrors(err))[0] ?? 'That did not save. Try again.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <aside className="card panel">
      <div className="panel-head">
        <div>
          <h2>{person.name}</h2>
          <span className="muted">Salary · {person.employee_no}</span>
        </div>
        <button type="button" className="btn ghost" onClick={onClose}>
          Close
        </button>
      </div>
      {notice && <p className="notice">{notice}</p>}
      {failed && <div className="form-error">{failed}</div>}
      {!data && !failed && <p className="loading">Loading the salary…</p>}
      {data && !adding && (
        <>
          {data.can_edit && (
            <button className="btn primary" disabled={parts.length === 0} onClick={() => setAdding(true)}>
              {data.structures.length === 0 ? '+ Set the salary' : '+ New salary from a month'}
            </button>
          )}
          {data.structures.length === 0 && <p className="muted gap-top">No salary has been set. Until one is, payroll leaves this person out and says so.</p>}
          {data.structures.map((s) => (
            <div key={s.id} className="day-section">
              <div className="row between wrap">
                <h3>From {monthName(s.effective_from.slice(0, 7))}</h3>
                <span className={`pill ${s.approved ? 'ok' : 'status-arrived'}`}>{s.approved ? 'Approved' : 'Waiting for approval'}</span>
              </div>
              <p className="muted small">
                {PAY_BASIS_LABEL[s.pay_basis]} · {inr(s.monthly)} for a full month
                {s.overtime_rate !== null ? ` · overtime ${inr(s.overtime_rate)} an hour` : ''}
                <br />
                Entered by {s.entered_by ?? 'someone'}
                {s.approved && s.approved_by ? `, approved by ${s.approved_by}` : ''}
                {s.note ? ` · ${s.note}` : ''}
              </p>
              <table className="compact">
                <tbody>
                  {s.lines.map((l) => (
                    <tr key={l.component_id} className="static">
                      <td>
                        {l.name}
                        <small className="muted block">{KIND_LABEL[l.kind]}</small>
                      </td>
                      <td className="num">{l.by_rule ? <span className="muted">by its rule</span> : l.percent !== null ? `${Number(l.percent)}%` : `${inr(l.amount)}${l.basis === 'per_day' ? ' a day' : l.basis === 'per_hour' ? ' an hour' : ''}`}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {withdrawing === s.id ? (
                <div className="row wrap gap-top">
                  <input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Why it is withdrawn" aria-label="Reason" className="grow" />
                  <button className="btn small-btn" disabled={busy || !reason.trim()} onClick={() => void act(`${s.id}/withdraw`, { reason }, 'Withdrawn.')}>
                    Withdraw
                  </button>
                </div>
              ) : (
                <div className="row wrap gap-top">
                  {s.can_approve && (
                    <button className="btn primary small-btn" disabled={busy} onClick={() => void act(`${s.id}/approve`, {}, 'Approved. Payroll will use it from that month.')}>
                      Approve
                    </button>
                  )}
                  {s.can_withdraw && (
                    <button className="link" onClick={() => setWithdrawing(s.id)}>
                      Withdraw
                    </button>
                  )}
                </div>
              )}
            </div>
          ))}
        </>
      )}
      {data && adding && (
        <SalaryForm
          person={person}
          parts={parts.filter((p) => p.is_enabled)}
          latest={data.structures[0] ?? null}
          onClose={() => setAdding(false)}
          onSaved={(approved) => {
            setAdding(false)
            setNotice(approved ? 'Saved. Payroll will use it from that month.' : needsApproval ? 'Saved. It waits for someone else to approve it before payroll uses it.' : 'Saved.')
            load()
            onChanged()
          }}
        />
      )}
    </aside>
  )
}

function SalaryForm({ person, parts, latest, onClose, onSaved }: { person: StaffBrief; parts: Part[]; latest: Salary | null; onClose: () => void; onSaved: (approved: boolean) => void }) {
  const was = (id: number) => latest?.lines.find((l) => l.component_id === id)
  const [month, setMonth] = useState(latest ? addMonths(thisMonth(), 1) : thisMonth())
  const [basis, setBasis] = useState(latest?.pay_basis ?? 'monthly')
  const [overtime, setOvertime] = useState(latest?.overtime_rate ?? '')
  const [note, setNote] = useState('')
  // What was typed against each part: an amount or a percentage, according to the part.
  const [typed, setTyped] = useState<Record<number, string>>(() => Object.fromEntries(parts.map((p) => [p.id, was(p.id)?.percent ?? was(p.id)?.amount ?? ''])))
  // Deductions and the hospital's share are ticked on; an earning is on when it has a figure.
  const [on, setOn] = useState<Record<number, boolean>>(() => Object.fromEntries(parts.map((p) => [p.id, Boolean(was(p.id))])))
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const isPercent = (p: Part) => p.basis.startsWith('percent')
  const included = parts.filter((p) => (p.kind === 'earning' ? typed[p.id] !== '' : on[p.id]))

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    try {
      const res = await api.post<Salary>(`/workforce/pay/salaries/${person.id}`, {
        effective_from: `${month}-01`,
        pay_basis: basis,
        overtime_rate: overtime === '' ? null : overtime,
        note: note || null,
        lines: included.map((p) => ({ component_id: p.id, [isPercent(p) ? 'percent' : 'amount']: typed[p.id] === '' ? null : typed[p.id] })),
      })
      onSaved(res.data.approved)
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }
  const problem = Object.entries(errors).find(([key]) => key.startsWith('lines'))?.[1]
  const group = (kind: PartKind, heading: string, hint: string) => {
    const list = parts.filter((p) => p.kind === kind)
    if (list.length === 0) return null
    return (
      <div className="day-section">
        <h3>{heading}</h3>
        <p className="muted small">{hint}</p>
        {list.map((p) => (
          <div key={p.id} className="row between wf-pay-line">
            <label className={kind === 'earning' ? '' : 'check flush'}>
              {kind !== 'earning' && <input type="checkbox" checked={Boolean(on[p.id])} onChange={(e) => setOn((o) => ({ ...o, [p.id]: e.target.checked }))} />}
              <span>
                {p.name}
                <small className="muted block">{BASIS_LABEL[p.basis]}</small>
              </span>
            </label>
            {p.basis === 'slab_of_gross' ? (
              <span className="muted small">by its slabs</span>
            ) : (
              <input
                type="number"
                min="0"
                step={isPercent(p) ? '0.01' : '1'}
                value={typed[p.id] ?? ''}
                disabled={kind !== 'earning' && !on[p.id]}
                onChange={(e) => setTyped((t) => ({ ...t, [p.id]: e.target.value }))}
                placeholder={kind !== 'earning' && p.rule && (isPercent(p) ? p.rule.percent !== undefined : p.rule.amount !== undefined) ? 'by its rule' : isPercent(p) ? '%' : '₹'}
                aria-label={`${p.name} ${isPercent(p) ? 'percentage' : 'amount'}`}
              />
            )}
          </div>
        ))}
      </div>
    )
  }

  return (
    <form onSubmit={save} noValidate>
      <h3>{latest ? 'A new salary' : 'Set the salary'}</h3>
      <p className="muted small">A salary starts on the first of a month. The salary before it is kept, so an earlier month’s payroll still finds what applied then.</p>
      <div className="grid">
        <TextField label="From the month of" required type="month" value={month} onChange={setMonth} error={errors.effective_from} />
        <SelectField label="Paid" value={basis} onChange={setBasis} options={Object.entries(PAY_BASIS_LABEL).map(([value, label]) => ({ value, label }))} />
      </div>
      {group('earning', 'Earnings', 'Fill in the ones this person gets. Leave the rest empty.')}
      {group('deduction', 'Deductions', 'Tick the ones that apply. Leave the figure empty to use the rule entered for that part.')}
      {group('employer', 'The hospital’s own share', 'Paid by the hospital on top of the salary, never taken from it.')}
      <div className="grid gap-top">
        <TextField label="Overtime, ₹ an hour" type="number" min="0" value={overtime} onChange={setOvertime} placeholder="None" error={errors.overtime_rate} />
        <TextField label="Note" value={note} onChange={setNote} placeholder="Annual rise, joining…" error={errors.note} />
      </div>
      <dl className="totals">
        <dt className="strong">For a full month</dt>
        <dd className="strong">{inr(monthlyPreview(parts, typed))}</dd>
      </dl>
      {problem && <div className="form-error">{problem}</div>}
      <div className="panel-foot row">
        <button type="button" className="btn ghost" onClick={onClose}>
          Cancel
        </button>
        <button className="btn primary" disabled={saving || included.length === 0 || !month}>
          {saving ? 'Saving…' : 'Save the salary'}
        </button>
      </div>
    </form>
  )
}

// ---- The parts salaries are made of ----

const EMPTY_PART = { code: '', name: '', kind: 'earning' as PartKind, basis: 'fixed', is_statutory: false, is_enabled: true, reduces_with_absence: true }

function PartList({ parts, onChanged }: { parts: Parts | null; onChanged: (parts: Parts) => void }) {
  const [editing, setEditing] = useState<Part | 'new' | null>(null)
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const reload = () => api.get<Parts>('/workforce/pay/components').then((res) => onChanged(res.data))
  const start = async () => {
    setBusy(true)
    try {
      onChanged((await api.post<Parts>('/workforce/pay/components/starter')).data)
      setNotice('The usual parts were added. The statutory ones are switched off and have no rate: enter each one’s rule as your accountant gives it, then switch it on.')
    } catch (err) {
      setNotice(Object.values(fieldErrors(err))[0] ?? 'That did not work. Try again.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <div className="toolbar minor">
        <p className="muted small grow">Basic pay, allowances and deductions. A statutory part (PF, ESI, professional tax, TDS) is worked out only from the rule entered here: nothing is assumed.</p>
        <button className="btn primary" onClick={() => setEditing('new')}>
          + Add a part
        </button>
      </div>
      {notice && <p className="notice">{notice}</p>}
      <div className={editing ? 'split' : ''}>
        <div className="table-wrap grow">
          <table>
            <thead>
              <tr>
                <th>Part</th>
                <th>Kind</th>
                <th>Worked out as</th>
                <th>Rule</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {parts?.components.map((p) => (
                <tr key={p.id} className={editing !== 'new' && editing?.id === p.id ? 'selected' : ''} onClick={() => setEditing(p)}>
                  <td>
                    <strong>{p.name}</strong>
                    <small className="muted block">
                      {p.code}
                      {p.is_statutory ? ' · statutory' : ''}
                    </small>
                  </td>
                  <td>{KIND_LABEL[p.kind]}</td>
                  <td>{BASIS_LABEL[p.basis] ?? p.basis}</td>
                  <td className="wrap">{ruleInWords(p)}</td>
                  <td className="num">
                    <span className={`pill ${p.is_enabled ? 'ok' : 'status-booked'}`}>{p.is_enabled ? 'On' : 'Off'}</span>
                  </td>
                </tr>
              ))}
              {parts && parts.components.length === 0 && (
                <tr className="empty">
                  <td colSpan={5}>
                    Nothing here yet.{' '}
                    <button className="link" disabled={busy} onClick={() => void start()}>
                      Start with the usual parts
                    </button>{' '}
                    (basic pay, HRA, an allowance, and the statutory ones switched off with no rates), or add your own.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        {editing && parts && (
          <PartForm
            key={editing === 'new' ? 'new' : editing.id}
            part={editing === 'new' ? null : editing}
            bases={parts.bases}
            onClose={() => setEditing(null)}
            onSaved={() => {
              setEditing(null)
              setNotice('Saved. A draft payroll that used the old rule will ask to be worked out again.')
              void reload()
            }}
          />
        )}
      </div>
    </>
  )
}

function PartForm({ part, bases, onClose, onSaved }: { part: Part | null; bases: Record<PartKind, string[]>; onClose: () => void; onSaved: () => void }) {
  const [form, setForm] = useState(part ? { code: part.code, name: part.name, kind: part.kind, basis: part.basis, is_statutory: part.is_statutory, is_enabled: part.is_enabled, reduces_with_absence: part.reduces_with_absence } : EMPTY_PART)
  const text = (v: number | string | null | undefined) => (v === undefined || v === null ? '' : String(v))
  const [rule, setRule] = useState({ percent: text(part?.rule?.percent), ceiling: text(part?.rule?.ceiling), only_if_pay_up_to: text(part?.rule?.only_if_pay_up_to), amount: text(part?.rule?.amount), round: part?.rule?.round ?? '' })
  const [slabs, setSlabs] = useState<{ up_to: string; amount: string }[]>(() => (part?.rule?.slabs ?? []).map((s) => ({ up_to: text(s.up_to), amount: text(s.amount) })))
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm((f) => ({ ...f, [key]: value }))
  const earning = form.kind === 'earning'
  const percent = form.basis.startsWith('percent')

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    const sent: Rule = {}
    if (!earning) {
      if (percent && rule.percent !== '') sent.percent = rule.percent
      if (percent && rule.ceiling !== '') sent.ceiling = rule.ceiling
      if (form.basis === 'fixed' && rule.amount !== '') sent.amount = rule.amount
      if (rule.only_if_pay_up_to !== '') sent.only_if_pay_up_to = rule.only_if_pay_up_to
      if (rule.round) sent.round = rule.round as Rule['round']
      if (form.basis === 'slab_of_gross') sent.slabs = slabs.filter((s) => s.amount !== '').map((s) => ({ up_to: s.up_to === '' ? null : s.up_to, amount: s.amount }))
    }
    try {
      const body = { ...form, code: form.code.trim().toUpperCase(), rule: sent }
      await (part ? api.put(`/workforce/pay/components/${part.id}`, body) : api.post('/workforce/pay/components', body))
      onSaved()
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <aside className="card panel">
      <form onSubmit={save} noValidate>
        <div className="panel-head">
          <h2>{part ? part.name : 'Add a part'}</h2>
          <button type="button" className="btn ghost" onClick={onClose}>
            Close
          </button>
        </div>
        <div className="grid">
          <TextField label="Name" required value={form.name} onChange={(v) => set('name', v)} error={errors.name} />
          <TextField label="Short code" required value={form.code} onChange={(v) => set('code', v.toUpperCase())} error={errors.code} disabled={part?.code === 'BASIC'} />
        </div>
        <div className="grid">
          <SelectField
            label="Kind"
            value={form.kind}
            onChange={(v) => setForm((f) => ({ ...f, kind: v as PartKind, basis: bases[v as PartKind].includes(f.basis) ? f.basis : 'fixed' }))}
            options={(Object.keys(KIND_LABEL) as PartKind[]).map((value) => ({ value, label: KIND_LABEL[value] }))}
          />
          <SelectField label="Worked out as" value={form.basis} onChange={(v) => set('basis', v)} options={bases[form.kind].map((value) => ({ value, label: BASIS_LABEL[value] ?? value }))} error={errors.basis} />
        </div>
        {earning && (
          <label className="check top">
            <input type="checkbox" checked={form.reduces_with_absence} onChange={(e) => set('reduces_with_absence', e.target.checked)} />
            <span>
              Shrinks with unpaid days
              <small className="muted block">On: an absent day or a day of leave without pay takes a day of this off. Off: it is paid in full whatever the attendance.</small>
            </span>
          </label>
        )}
        {!earning && (
          <div className="day-section">
            <h3>The rule</h3>
            <p className="muted small">Enter what your accountant gives you. Anything left empty is not applied, and a part with no rule can still be given a figure on each person’s salary.</p>
            {percent && (
              <div className="grid">
                <TextField label="Percentage" type="number" min="0" step="0.01" value={rule.percent} onChange={(v) => setRule((r) => ({ ...r, percent: v }))} error={errors['rule.percent']} />
                <TextField label="Worked on no more than (₹)" type="number" min="0" value={rule.ceiling} onChange={(v) => setRule((r) => ({ ...r, ceiling: v }))} error={errors['rule.ceiling']} />
              </div>
            )}
            {form.basis === 'fixed' && <TextField label="Amount a month (₹)" type="number" min="0" value={rule.amount} onChange={(v) => setRule((r) => ({ ...r, amount: v }))} error={errors['rule.amount']} />}
            {form.basis === 'slab_of_gross' && (
              <div className="field">
                <span className="field-label">Slabs, by the pay earned in the month</span>
                {slabs.map((s, i) => (
                  <div key={i} className="row wf-pay-line">
                    <input type="number" min="0" value={s.up_to} onChange={(e) => setSlabs((list) => list.map((x, j) => (j === i ? { ...x, up_to: e.target.value } : x)))} placeholder="Pay up to ₹ (empty: and above)" aria-label="Pay up to" />
                    <input type="number" min="0" value={s.amount} onChange={(e) => setSlabs((list) => list.map((x, j) => (j === i ? { ...x, amount: e.target.value } : x)))} placeholder="₹ a month" aria-label="Amount" />
                    <button type="button" className="link" onClick={() => setSlabs((list) => list.filter((_, j) => j !== i))}>
                      Remove
                    </button>
                  </div>
                ))}
                <button type="button" className="link" onClick={() => setSlabs((list) => [...list, { up_to: '', amount: '' }])}>
                  + Add a slab
                </button>
              </div>
            )}
            <div className="grid">
              <TextField label="Only for set pay up to (₹ a month)" type="number" min="0" value={rule.only_if_pay_up_to} onChange={(v) => setRule((r) => ({ ...r, only_if_pay_up_to: v }))} placeholder="Everyone" error={errors['rule.only_if_pay_up_to']} />
              <SelectField label="Rounding" value={rule.round} onChange={(v) => setRule((r) => ({ ...r, round: v as typeof r.round }))} options={[{ value: 'rupee', label: 'To the nearest rupee' }, { value: 'rupee_up', label: 'Up to the next rupee' }]} placeholder="None (to the paisa)" />
            </div>
          </div>
        )}
        <label className="check top">
          <input type="checkbox" checked={form.is_statutory} onChange={(e) => set('is_statutory', e.target.checked)} />
          <span>A statutory part (PF, ESI, professional tax, TDS and the like)</span>
        </label>
        <label className="check top">
          <input type="checkbox" checked={form.is_enabled} onChange={(e) => set('is_enabled', e.target.checked)} />
          <span>
            Switched on
            <small className="muted block">Off: payroll leaves it out for everyone and each payslip says so.</small>
          </span>
        </label>
        <div className="panel-foot row">
          <button type="button" className="btn ghost" onClick={onClose}>
            Cancel
          </button>
          <button className="btn primary" disabled={saving || !form.name.trim() || !form.code.trim()}>
            {saving ? 'Saving…' : 'Save'}
          </button>
        </div>
      </form>
    </aside>
  )
}
