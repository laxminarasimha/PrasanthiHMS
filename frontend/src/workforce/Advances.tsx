import { Popup } from '../components/Popup'
import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { SelectField, TextField } from '../components/Field'
import { longDate, today } from '../format'
import { useChoiceInAddress } from '../rangeInAddress'
import { PasswordAgain } from './PasswordAgain'
import { ADVANCE_CLASS, ADVANCE_LABEL, INSTALMENT_LABEL, LEDGER_LABEL, PAY_MODES, inr, payModeLabel, type Advance, type AdvanceDetail } from './pay'
import { saveFile, usePasswordAgain } from './sensitiveStep'
import { addMonths, monthName, thisMonth, type StaffBrief } from './shared'
import { WorkforceTabs } from './WorkforceTabs'

type List = { can: { create: boolean; approve: boolean; let_go: boolean; export: boolean }; owed: { advances: number; amount: string }; waiting: number; rows: Advance[] }
const SHOW: [string, string][] = [['open', 'Open'], ['requested', 'Waiting'], ['closed', 'Repaid'], ['all', 'All']]

/** Advances and loans to staff: asked for, approved, given, and taken back through payroll. */
export function WorkforceAdvances() {
  const [show, setShow] = useChoiceInAddress<string>('show', 'open')
  const [open, setOpen] = useChoiceInAddress<string>('advance', '')
  const [data, setData] = useState<{ show: string; list: List } | null>(null)
  const [failed, setFailed] = useState('')
  const [notice, setNotice] = useState('')
  const [adding, setAdding] = useState(false)
  const { run: withPassword, ask } = usePasswordAgain()

  const load = useCallback(() => {
    api
      .get<List>('/workforce/advances', { params: { status: show } })
      .then((res) => {
        setData({ show, list: res.data })
        setFailed('')
      })
      .catch(() => setFailed('Could not load the advances. Check the connection and try again.'))
  }, [show])
  useEffect(load, [load])
  const shown = data?.show === show ? data.list : null
  const totals = data?.list

  return (
    <>
      <WorkforceTabs />
      <div className={open ? 'split' : ''}>
        <section className="card grow">
          <div className="toolbar">
            <h1>Advances and loans</h1>
            <div className="tabs flush" role="group" aria-label="Which to show">
              {SHOW.map(([value, label]) => (
                <button key={value} className={show === value ? 'active' : ''} onClick={() => setShow(value)}>
                  {label}
                </button>
              ))}
            </div>
            <span className="grow" />
            {totals?.can.export && (
              <button className="btn ghost" onClick={() => void withPassword(() => saveFile('/workforce/advances/export', {}, 'advances-and-loans.csv')).catch(() => undefined)}>
                Export
              </button>
            )}
            {totals?.can.create && (
              <button
                className="btn primary"
                onClick={() => {
                  setOpen('')
                  setAdding(true)
                }}
              >
                + New request
              </button>
            )}
          </div>
          {notice && <p className="notice">{notice}</p>}
          {failed && <div className="form-error">{failed}</div>}
          <div className="tiles">
            <div className="tile">
              <span>Still owed by staff</span>
              <strong className={totals && Number(totals.owed.amount) > 0 ? 'due' : ''}>{totals ? inr(totals.owed.amount) : ''}</strong>
              <small className="muted">{totals ? `on ${totals.owed.advances} ${totals.owed.advances === 1 ? 'advance or loan' : 'advances and loans'}` : ''}</small>
            </div>
            <div className="tile">
              <span>Waiting for a decision</span>
              <strong>{totals ? totals.waiting : ''}</strong>
            </div>
          </div>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Who</th>
                  <th>What</th>
                  <th className="num">Amount</th>
                  <th className="num">Still owed</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {shown?.rows.map((a) => (
                  <tr
                    key={a.id}
                    className={open === String(a.id) ? 'selected' : ''}
                    onClick={() => {
                      setAdding(false)
                      setOpen(String(a.id))
                    }}
                  >
                    <td className="fit-wrap">
                      <strong>{a.employee?.name ?? '—'}</strong>
                      <small className="muted block">{[a.employee?.employee_no, a.employee?.department?.name].filter(Boolean).join(' · ')}</small>
                    </td>
                    <td className="wrap">
                      {a.kind === 'loan' ? 'Loan' : 'Advance'} {a.advance_no}
                      <small className="muted block">
                        {a.reason} · {a.instalments} {a.instalments === 1 ? 'month' : 'months'}
                        {a.first_period ? ` from ${monthName(a.first_period)}` : ''}
                      </small>
                    </td>
                    <td className="num">{inr(a.amount)}</td>
                    <td className="num">{a.owed === null ? '—' : <strong className={Number(a.owed) > 0 ? 'due' : 'settled'}>{inr(a.owed)}</strong>}</td>
                    <td className="num">
                      <span className={`pill ${ADVANCE_CLASS[a.status]}`}>{ADVANCE_LABEL[a.status]}</span>
                    </td>
                  </tr>
                ))}
                {shown && shown.rows.length === 0 && (
                  <tr className="empty">
                    <td colSpan={5}>{show === 'open' ? 'Nothing is owed and nothing is waiting.' : 'Nothing here.'}</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
          <PasswordAgain ask={ask} />
        </section>
        {adding && (
          <RequestForm
            onClose={() => setAdding(false)}
            onSaved={(a) => {
              setAdding(false)
              setNotice(`${a.kind === 'loan' ? 'Loan' : 'Advance'} ${a.advance_no} is waiting for approval. Nothing is owed until it is approved and the money is recorded as given.`)
              load()
            }}
          />
        )}
        {open && !adding && <AdvancePanel key={open} id={Number(open)} onClose={() => setOpen('')} onChanged={load} />}
      </div>
    </>
  )
}

function RequestForm({ onClose, onSaved }: { onClose: () => void; onSaved: (advance: Advance) => void }) {
  const [staff, setStaff] = useState<StaffBrief[]>([])
  const [form, setForm] = useState({ employee_id: '', kind: 'advance', amount: '', reason: '', instalments: '1', first_period: addMonths(thisMonth(), 0) })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  useEffect(() => {
    api.get<{ data: StaffBrief[] }>('/workforce/employees').then((res) => setStaff(res.data.data)).catch(() => undefined)
  }, [])
  const set = (key: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [key]: value }))
  const each = Number(form.amount) > 0 && Number(form.instalments) > 0 ? Math.floor(Number(form.amount) / Number(form.instalments)) : 0

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    try {
      onSaved((await api.post<Advance>('/workforce/advances', form)).data)
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Popup title="New request" onClose={onClose} onSubmit={save}>
        <SelectField label="For" required value={form.employee_id} onChange={set('employee_id')} options={staff.map((s) => ({ value: String(s.id), label: `${s.name} (${s.employee_no})` }))} placeholder="Choose a member of staff" error={errors.employee_id} />
        <div className="grid">
          <SelectField label="It is" value={form.kind} onChange={set('kind')} options={[{ value: 'advance', label: 'An advance on salary' }, { value: 'loan', label: 'A loan' }]} />
          <TextField label="Amount (₹)" required type="number" min="1" value={form.amount} onChange={set('amount')} error={errors.amount} />
        </div>
        <TextField label="What it is for" required value={form.reason} onChange={set('reason')} error={errors.reason} />
        <div className="grid">
          <TextField label="Taken back over (months)" required type="number" min="1" max="120" value={form.instalments} onChange={set('instalments')} error={errors.instalments} />
          <TextField label="Starting with the salary for" required type="month" value={form.first_period} onChange={set('first_period')} error={errors.first_period} />
        </div>
        {each > 0 && (
          <p className="muted small">
            About {inr(each)} from each month’s salary{Number(form.instalments) > 1 ? ', the odd rupees on the last month' : ''}. Whoever approves it may change the plan.
          </p>
        )}
        <div className="panel-foot row">
          <button type="button" className="btn ghost" onClick={onClose}>
            Cancel
          </button>
          <button className="btn primary" disabled={saving || !form.employee_id || !form.amount || !form.reason.trim()}>
            {saving ? 'Saving…' : 'Put in the request'}
          </button>
        </div>
    </Popup>
  )
}

type Doing = 'approve' | 'refuse' | 'issue' | 'repay' | 'skip' | 'waiver' | 'write_off' | null

function AdvancePanel({ id, onClose, onChanged }: { id: number; onClose: () => void; onChanged: () => void }) {
  const [a, setA] = useState<AdvanceDetail | null>(null)
  const [failed, setFailed] = useState('')
  const [notice, setNotice] = useState('')
  const [doing, setDoing] = useState<Doing>(null)
  const [form, setForm] = useState({ note: '', amount: '', instalments: '', first_period: '', date: today(), payment_mode: 'cash', payment_reference: '', period: '' })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [busy, setBusy] = useState(false)
  const { run: withPassword, ask } = usePasswordAgain()
  const load = useCallback(() => {
    api
      .get<AdvanceDetail>(`/workforce/advances/${id}`)
      .then((res) => setA(res.data))
      .catch(() => setFailed('This could not be opened.'))
  }, [id])
  useEffect(load, [load])
  const set = (key: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [key]: value }))

  const send = async (path: string, body: Record<string, unknown>, done: string, sensitive = false) => {
    setBusy(true)
    setErrors({})
    setNotice('')
    try {
      const step = () => api.post(`/workforce/advances/${id}/${path}`, body)
      await (sensitive ? withPassword(step) : step())
      setNotice(done)
      setDoing(null)
      setForm((f) => ({ ...f, note: '', amount: '' }))
      load()
      onChanged()
    } catch (err) {
      if ((err as Error).message !== 'Cancelled') setErrors(fieldErrors(err))
    } finally {
      setBusy(false)
    }
  }
  const problem = Object.values(errors)[0]
  const submit = () => {
    if (!a) return
    switch (doing) {
      case 'approve':
        return send('decide', { approve: true, amount: form.amount || undefined, instalments: form.instalments || undefined, first_period: form.first_period || undefined, note: form.note || undefined }, 'Approved. Record the money as given once it has been handed over.')
      case 'refuse':
        return send('decide', { approve: false, note: form.note }, 'Refused.')
      case 'issue':
        return send('issue', { issued_on: form.date, payment_mode: form.payment_mode, payment_reference: form.payment_reference || undefined }, 'Recorded as given. It is now owed, and payroll will take it back month by month.')
      case 'repay':
        return send('repay', { amount: form.amount, paid_on: form.date, mode: form.payment_mode, note: form.note }, 'Written into the account.')
      case 'skip':
        return send('skip', { period: form.period, reason: form.note }, 'That month is skipped and added to the end of the plan.')
      case 'waiver':
        return send('let-go', { kind: 'waiver', amount: form.amount, reason: form.note }, 'Waived, and written into the account with the reason.', true)
      case 'write_off':
        return send('let-go', { kind: 'write_off', reason: form.note }, 'Written off. Nothing more is owed on it.', true)
    }
  }
  const due = a?.schedule.filter((s) => s.status === 'due') ?? []
  const start = (what: Doing) => {
    setErrors({})
    setForm((f) => ({ ...f, note: '', amount: '', instalments: String(a?.instalments ?? ''), first_period: a?.first_period ?? '', period: due[0]?.period ?? '' }))
    setDoing(what)
  }

  return (
    <aside className="card panel">
      <div className="panel-head">
        <div>
          <h2>{a ? `${a.kind === 'loan' ? 'Loan' : 'Advance'} ${a.advance_no ?? ''}` : 'Advance'}</h2>
          <span className="muted">{a?.employee ? `${a.employee.name} · ${a.employee.employee_no}` : ''}</span>
        </div>
        <button type="button" className="btn ghost" onClick={onClose}>
          Close
        </button>
      </div>
      {notice && <p className="notice">{notice}</p>}
      {failed && <div className="form-error">{failed}</div>}
      {!a && !failed && <p className="loading">Loading the account…</p>}
      {a && (
        <>
          <dl className="totals">
            <dt>Amount</dt>
            <dd>{inr(a.amount)}</dd>
            {a.owed !== null && (
              <>
                <dt className="strong">Still owed</dt>
                <dd className={`strong ${Number(a.owed) > 0 ? 'due' : 'settled'}`}>{inr(a.owed)}</dd>
              </>
            )}
          </dl>
          <p className="muted small">
            <span className={`pill ${ADVANCE_CLASS[a.status]}`}>{ADVANCE_LABEL[a.status]}</span> {a.reason}
            <br />
            Asked for by {a.requested_by ?? 'someone'}
            {a.approved_by ? ` · ${a.status === 'rejected' ? 'refused' : 'approved'} by ${a.approved_by}` : ''}
            {a.issued_on ? ` · given on ${longDate(a.issued_on)} by ${payModeLabel(a.payment_mode).toLowerCase()}${a.payment_reference ? ` (${a.payment_reference})` : ''}` : ''}
          </p>

          {!doing && (
            <div className="row wrap">
              {a.can_decide && (
                <>
                  <button className="btn primary small-btn" onClick={() => start('approve')}>
                    Approve
                  </button>
                  <button className="btn small-btn" onClick={() => start('refuse')}>
                    Refuse
                  </button>
                </>
              )}
              {a.can_issue && (
                <button className="btn primary small-btn" onClick={() => start('issue')}>
                  Record as given
                </button>
              )}
              {a.can_take_back && (
                <>
                  <button className="btn small-btn" onClick={() => start('repay')}>
                    Paid back by hand
                  </button>
                  {due.length > 0 && (
                    <button className="btn small-btn" onClick={() => start('skip')}>
                      Skip a month
                    </button>
                  )}
                </>
              )}
              {a.can_let_go && (
                <>
                  <button className="link" onClick={() => start('waiver')}>
                    Waive part
                  </button>
                  <button className="link" onClick={() => start('write_off')}>
                    Write off
                  </button>
                </>
              )}
            </div>
          )}
          {doing && (
            <form
              className="day-section"
              noValidate
              onSubmit={(e) => {
                e.preventDefault()
                void submit()
              }}
            >
              <h3>{{ approve: 'Approve the request', refuse: 'Refuse the request', issue: 'Record the money as given', repay: 'Money paid back by hand', skip: 'Skip a month', waiver: 'Waive part of what is owed', write_off: 'Write off all that is owed' }[doing]}</h3>
              {doing === 'approve' && (
                <div className="grid">
                  <TextField label="Amount (₹)" type="number" min="1" value={form.amount} onChange={set('amount')} placeholder={a.amount} />
                  <TextField label="Over (months)" type="number" min="1" max="120" value={form.instalments} onChange={set('instalments')} />
                  <TextField label="From the salary for" type="month" value={form.first_period} onChange={set('first_period')} />
                </div>
              )}
              {doing === 'issue' && (
                <>
                  <p className="muted small">This writes down a payment already made; it moves no money. It is posted to Expenses under “Staff advances and loans”, and cash comes off that day’s drawer.</p>
                  <div className="grid">
                    <TextField label="Given on" required type="date" max={today()} value={form.date} onChange={set('date')} error={errors.issued_on} />
                    <SelectField label="How" value={form.payment_mode} onChange={set('payment_mode')} options={PAY_MODES} />
                  </div>
                  <TextField label="Reference" value={form.payment_reference} onChange={set('payment_reference')} placeholder="Voucher, transfer or cheque number" />
                </>
              )}
              {(doing === 'repay' || doing === 'waiver') && (
                <div className="grid">
                  <TextField label="Amount (₹)" required type="number" min="1" value={form.amount} onChange={set('amount')} error={errors.amount} />
                  {doing === 'repay' && <TextField label="Paid back on" required type="date" max={today()} value={form.date} onChange={set('date')} error={errors.paid_on} />}
                  {doing === 'repay' && <SelectField label="How" value={form.payment_mode} onChange={set('payment_mode')} options={PAY_MODES} error={errors.mode} />}
                </div>
              )}
              {doing === 'repay' && form.payment_mode === 'cash' && <p className="muted small">Cash handed back goes into the drawer’s expected cash for that day.</p>}
              {doing === 'skip' && <SelectField label="Month to skip" value={form.period} onChange={set('period')} options={due.map((s) => ({ value: s.period, label: `${s.period_name} · ${inr(s.amount)}` }))} error={errors.period} />}
              {doing === 'write_off' && <p className="muted small">All {inr(a.owed)} still owed is let go. The account is closed and keeps this line and its reason.</p>}
              {doing !== 'issue' && (
                <TextField
                  label={{ approve: 'Note (optional)', refuse: 'Why it is refused', repay: 'Receipt or note', skip: 'Why this month is skipped', waiver: 'Why it is waived', write_off: 'Why it is written off' }[doing]}
                  required={doing !== 'approve'}
                  value={form.note}
                  onChange={set('note')}
                  error={errors.note ?? errors.reason}
                />
              )}
              {problem && !['note', 'reason', 'amount', 'issued_on', 'paid_on', 'period'].some((k) => errors[k]) && <div className="form-error">{problem}</div>}
              <div className="panel-foot row">
                <button type="button" className="btn ghost" onClick={() => setDoing(null)}>
                  Cancel
                </button>
                <button className="btn primary" disabled={busy || (doing !== 'approve' && doing !== 'issue' && !form.note.trim())}>
                  {busy ? 'Saving…' : 'Save'}
                </button>
              </div>
            </form>
          )}

          {a.schedule.length > 0 && (
            <div className="day-section">
              <h3>The plan</h3>
              <table className="compact">
                <tbody>
                  {a.schedule.map((s) => (
                    <tr key={s.id} className="static">
                      <td>Salary for {s.period_name}</td>
                      <td className="num">{inr(s.amount)}</td>
                      <td className="num">
                        <span className={s.status === 'deducted' ? 'settled' : 'muted'}>{a.status !== 'issued' && s.status === 'due' ? 'Not needed' : INSTALMENT_LABEL[s.status]}</span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <p className="muted small">If a month’s salary cannot cover its part, the rest is taken the month after. The plan itself is not rewritten.</p>
            </div>
          )}
          {a.ledger.length > 0 && (
            <div className="day-section">
              <h3>The account</h3>
              <table className="compact">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>What</th>
                    <th className="num">Amount</th>
                    <th className="num">Owed after</th>
                  </tr>
                </thead>
                <tbody>
                  {a.ledger.map((l) => (
                    <tr key={l.id} className="static">
                      <td>{longDate(l.date)}</td>
                      <td className="wrap">
                        {LEDGER_LABEL[l.kind] ?? l.kind}
                        <small className="muted block">{[l.reason, l.by].filter(Boolean).join(' · ')}</small>
                      </td>
                      <td className={`num ${Number(l.amount) < 0 ? 'settled' : 'due'}`}>{Number(l.amount) > 0 ? `+${inr(l.amount)}` : inr(l.amount)}</td>
                      <td className="num">{inr(l.balance_after)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
      <PasswordAgain ask={ask} />
    </aside>
  )
}
