import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors, type FieldErrors } from '../api'
import { DateRange } from '../components/DateRange'
import { SelectField, TextField } from '../components/Field'
import { PAYMENT_MODES, longDate, modeLabel, rupees, today } from '../format'
import { useLoadOnChange } from '../loading'
import { useChoiceInAddress, useRangeInAddress } from '../rangeInAddress'
import { useUnsaved } from '../unsaved'
import { saveFile } from '../workforce/sensitiveStep'
import { EXPENSE_STATUS_CLASS, EXPENSE_STATUS_LABEL, approvalHint, ordinal, type Expense, type ExpenseList, type Heading } from './expenseRules'

type Form = { id?: number; status?: string; spent_on: string; category: string; department_id: string; paid_to: string; bill_no: string; description: string; amount: string; tax: string; mode: string; reference: string; stage: 'paid' | 'submitted' | 'draft' }
type Show = 'all' | 'waiting' | 'to_pay' | 'drafts' | 'summary' | 'setup'

const blank = (day: string): Form => ({ spent_on: day, category: '', department_id: '', paid_to: '', bill_no: '', description: '', amount: '', tax: '', mode: 'cash', reference: '', stage: 'paid' })
const formOf = (r: Expense): Form => ({
  id: r.id, status: r.status, spent_on: r.spent_on, category: r.category, department_id: r.department_id ? String(r.department_id) : '', paid_to: r.paid_to ?? '', bill_no: r.bill_no ?? '', description: r.description ?? '',
  amount: String(r.amount), tax: r.tax ? String(r.tax) : '', mode: r.mode, reference: r.reference ?? '', stage: 'paid',
})

/**
 * Money paid out. The cashier writes each payment down as it is made, so the drawer agrees at the day close. A bigger
 * expense, under a heading that asks for it, goes for approval first and is paid once approved. Salaries and staff
 * advances are written in by Payroll and Advances themselves.
 */
export function Expenses() {
  const [from, to, setRange] = useRangeInAddress('today')
  const [show, setShow] = useChoiceInAddress<Show>('show', 'all', ['all', 'waiting', 'to_pay', 'drafts', 'summary', 'setup'] as const)
  const [query, setQuery] = useState('')
  const [data, setData] = useState<ExpenseList | null>(null)
  const [form, setForm] = useState<Form | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [acting, setActing] = useState<{ row: Expense; what: 'cancel' | 'refuse' | 'pay' } | null>(null)
  const [reason, setReason] = useState('')
  const [payment, setPayment] = useState({ paid_on: today(), mode: 'cash', reference: '' })
  const [notice, setNotice] = useState('')
  const [failed, setFailed] = useState('')
  const [busy, setBusy] = useState(false)
  const fileFor = useRef<number | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)
  const list = show === 'summary' || show === 'setup' ? 'all' : show

  const load = useCallback(() => {
    api
      .get<ExpenseList>('/expenses', { params: { from, to, q: query.trim() || undefined, show: list } })
      .then((res) => {
        setData(res.data)
        setFailed('')
      })
      .catch(() => setFailed('Could not load the expenses.'))
  }, [from, to, query, list])
  // At once when the screen opens; after a short pause when a search or filter changes.
  useLoadOnChange(load)
  const shown = data?.show === list ? data : null

  useUnsaved(Boolean(form && (form.amount !== '' || form.category !== '' || form.paid_to !== '')) && !form?.id, 'This expense is not saved yet. Leave without saving it?')

  const open = (next: Form | null) => {
    setForm(next)
    setErrors({})
    setActing(null)
    setNotice('')
  }

  const save = async (e: FormEvent) => {
    e.preventDefault()
    if (!form || busy) return
    setBusy(true)
    setErrors({})
    const body = {
      spent_on: form.spent_on, category: form.category.trim(), department_id: form.department_id || null, paid_to: form.paid_to.trim() || null, bill_no: form.bill_no.trim() || null,
      description: form.description.trim() || null, amount: form.amount, tax: form.tax || 0, mode: form.mode, reference: form.reference.trim() || null, stage: form.id ? undefined : form.stage,
    }
    try {
      const res = await (form.id ? api.put<Expense>(`/expenses/${form.id}`, body) : api.post<Expense>('/expenses', body))
      const r = res.data
      setNotice(
        form.id
          ? `${r.expense_no} corrected.`
          : r.status === 'paid'
            ? `${r.expense_no} saved: ${rupees(r.amount)} for ${r.category}.`
            : r.status === 'submitted'
              ? `${r.expense_no} sent for approval. It is not money out until it is approved and paid.`
              : `${r.expense_no} kept as a draft.`,
      )
      // Ready for the next one straight away, on the same day. The mode goes back to cash, the usual one, so a
      // UPI payment just entered does not quietly make the next cash payment UPI as well.
      setForm(form.id ? null : blank(form.spent_on))
      load()
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setBusy(false)
    }
  }

  /** One step on an expense: send, approve, refuse, pay or cancel. */
  const step = async (r: Expense, path: string, body: Record<string, unknown>, done: string) => {
    setBusy(true)
    setErrors({})
    setNotice('')
    setFailed('')
    try {
      await api.post(`/expenses/${r.id}/${path}`, body)
      setNotice(done)
      setActing(null)
      setReason('')
      load()
    } catch (err) {
      const problems = fieldErrors(err)
      // In the side panel the problem shows by its box; from a button in the list, above the list.
      if (acting) setErrors(problems)
      else setFailed(Object.values(problems)[0] ?? 'That did not go through. Try again.')
    } finally {
      setBusy(false)
    }
  }

  const attach = async (file: File | undefined) => {
    const id = fileFor.current
    if (!file || !id) return
    const body = new FormData()
    body.append('file', file)
    setBusy(true)
    try {
      await api.post(`/expenses/${id}/receipt`, body)
      setNotice('Bill attached.')
      load()
    } catch (err) {
      setFailed(Object.values(fieldErrors(err))[0] ?? 'The bill could not be attached.')
    } finally {
      setBusy(false)
      if (fileInput.current) fileInput.current.value = ''
    }
  }
  const viewBill = async (r: Expense) => {
    try {
      const res = await api.get<Blob>(`/expenses/${r.id}/receipt`, { responseType: 'blob' })
      window.open(URL.createObjectURL(res.data), '_blank')
    } catch {
      setFailed('The bill could not be opened.')
    }
  }

  const out = shown?.paid_out
  const headings = Object.entries(out?.by_category ?? {})
  const set = (key: keyof Form) => (value: string) => setForm((f) => (f ? { ...f, [key]: value } : f))
  const chosen: Heading | undefined = data?.headings.find((h) => h.name === form?.category.trim())
  const hint = form ? approvalHint(chosen, Number(form.amount || 0)) : null
  const tab = (value: Show, label: string, count?: number) => (
    <button key={value} className={show === value ? 'active' : ''} onClick={() => setShow(value)}>
      {label}
      {count ? <span> {count}</span> : null}
    </button>
  )

  return (
    <div className="split">
      <section className="card">
        <div className="toolbar">
          <h1>Expenses</h1>
          {(show === 'all' || show === 'summary') && <DateRange from={from} to={to} onChange={setRange} />}
          {show !== 'summary' && show !== 'setup' && <input className="search" type="search" placeholder="Find by who was paid, bill, details or number" value={query} onChange={(e) => setQuery(e.target.value)} />}
          <span className="grow" />
          {data?.can.export && (
            <button className="btn ghost" onClick={() => void saveFile('/expenses/export', { from, to }, `expenses-${from}-to-${to}.csv`).catch(() => setFailed('The export could not be made.'))}>
              Export
            </button>
          )}
          <button className="btn primary" onClick={() => open(blank(data?.today ?? today()))}>
            + Add expense
          </button>
        </div>
        <div className="tabs flush wf-expense-tabs" role="group" aria-label="What to show">
          {tab('all', 'Paid and entered')}
          {tab('waiting', 'Waiting for approval', data?.counts.waiting)}
          {tab('to_pay', 'Approved, to pay', data?.counts.to_pay)}
          {tab('drafts', 'Drafts', data?.counts.drafts)}
          {tab('summary', 'Summary')}
          {data?.can.set_up && tab('setup', 'Headings and repeats')}
        </div>
        <p className="muted small">
          Write down each payment out as it is made: tea, repairs, a courier. Cash ones come off the cash expected at the <Link to="/reports">day close</Link>. Salaries and staff advances are written here by Workforce → Payroll and Advances, and
          payments to medicine suppliers on the purchase invoice.
        </p>
        {failed && <p className="form-error">{failed}</p>}
        {notice && <p className="notice">{notice}</p>}
        <input ref={fileInput} type="file" accept="application/pdf,image/*" hidden onChange={(e) => void attach(e.target.files?.[0])} />

        {show === 'summary' && <Summary from={from} to={to} />}
        {show === 'setup' && <SetUp onChanged={load} />}
        {show !== 'summary' && show !== 'setup' && (
          <>
            {show === 'all' && out && (
              <div className="tiles small">
                <div className="tile"><span>Expenses paid</span><strong>{rupees(out.expenses)}</strong></div>
                <div className="tile"><span>Of these, in cash</span><strong>{rupees(out.expenses_cash)}</strong></div>
                <div className="tile"><span>Paid to medicine suppliers</span><strong>{rupees(out.suppliers)}</strong></div>
                <div className="tile"><span>All cash paid out</span><strong className={out.cash > 0 ? 'due' : ''}>{rupees(out.cash)}</strong></div>
              </div>
            )}
            {show === 'all' && headings.length > 0 && (
              <p className="expense-headings">
                {headings.map(([name, amount]) => (
                  <span key={name} className="chip">
                    {name} <strong>{rupees(amount)}</strong>
                  </span>
                ))}
              </p>
            )}

            <div className="table-wrap">
              <table className="expense-list">
                <thead>
                  <tr>
                    <th>No.</th>
                    <th>For</th>
                    <th className="num">Amount</th>
                    <th>Stage</th>
                    <th>Entered by</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {shown?.rows.map((r) => (
                    <tr key={r.id} className={`static ${r.cancelled_at ? 'faded' : ''} ${form?.id === r.id || acting?.row.id === r.id ? 'selected' : ''}`}>
                      <td data-label="No.">
                        <span className="mono">{r.expense_no}</span>
                        <small className="muted block">{longDate(r.paid_on ?? r.spent_on)}</small>
                      </td>
                      <td data-label="For">
                        <strong>{r.category}</strong>
                        {r.paid_to && <span> · {r.paid_to}</span>}
                        {(r.description || r.bill_no || r.department) && <small className="muted block">{[r.department?.name, r.bill_no ? `Bill ${r.bill_no}` : '', r.description].filter(Boolean).join(' · ')}</small>}
                        {r.decision_note && <small className="muted block">Decision: {r.decision_note}</small>}
                        {r.cancelled_at && (
                          <small className="block due">
                            Cancelled{r.canceller ? ` by ${r.canceller.name}` : ''}: {r.cancel_reason}
                          </small>
                        )}
                      </td>
                      <td className="num" data-label="Amount">
                        {r.cancelled_at ? <s>{rupees(r.amount)}</s> : <strong>{rupees(r.amount)}</strong>}
                        {r.tax > 0 && <small className="muted block">tax {rupees(r.tax)}</small>}
                      </td>
                      <td data-label="Stage">
                        {r.status === 'paid' ? (
                          <>
                            {modeLabel(r.mode)}
                            {r.reference && <small className="muted block">{r.reference}</small>}
                          </>
                        ) : (
                          <span className={`pill ${EXPENSE_STATUS_CLASS[r.status]}`}>{EXPENSE_STATUS_LABEL[r.status]}</span>
                        )}
                      </td>
                      <td data-label="Entered by">
                        {r.creator?.name ?? '—'}
                        {r.decider && <small className="muted block">{r.status === 'rejected' ? 'Refused' : 'Approved'} by {r.decider.name}</small>}
                      </td>
                      <td className="num actions">
                        {!r.cancelled_at && (
                          <span className="row end wrap">
                            {r.can.decide && (
                              <>
                                <button className="btn primary small-btn" disabled={busy} onClick={() => void step(r, 'decide', { approve: true }, `${r.expense_no} approved. It can now be paid.`)}>
                                  Approve
                                </button>
                                <button className="link" onClick={() => (setActing({ row: r, what: 'refuse' }), setForm(null), setReason(''))}>
                                  Refuse
                                </button>
                              </>
                            )}
                            {r.can.pay && (
                              <button className="btn primary small-btn" onClick={() => (setActing({ row: r, what: 'pay' }), setForm(null), setPayment({ paid_on: data?.today ?? today(), mode: r.mode, reference: r.reference ?? '' }))}>
                                Pay
                              </button>
                            )}
                            {r.can.submit && (
                              <button className="btn small-btn" disabled={busy} onClick={() => void step(r, 'submit', {}, `${r.expense_no} sent on.`)}>
                                Send
                              </button>
                            )}
                            {r.can.edit && (
                              <button className="link" onClick={() => open(formOf(r))}>
                                Edit
                              </button>
                            )}
                            {r.has_receipt ? (
                              <button className="link" onClick={() => void viewBill(r)}>
                                Bill
                              </button>
                            ) : (
                              !r.posted && (
                                <button className="link" onClick={() => ((fileFor.current = r.id), fileInput.current?.click())}>
                                  Attach bill
                                </button>
                              )
                            )}
                            {r.can.cancel && (
                              <button className="link danger" onClick={() => (open(null), setActing({ row: r, what: 'cancel' }), setReason(''))}>
                                Cancel
                              </button>
                            )}
                            {r.posted && <small className="muted">From {r.source_type === 'advance' ? 'Advances' : 'Payroll'}</small>}
                          </span>
                        )}
                      </td>
                    </tr>
                  ))}
                  {shown && shown.rows.length === 0 && (
                    <tr className="empty">
                      <td colSpan={6}>
                        {list === 'waiting'
                          ? 'Nothing is waiting for approval.'
                          : list === 'to_pay'
                            ? 'Nothing approved is waiting to be paid.'
                            : list === 'drafts'
                              ? 'No drafts.'
                              : `No expenses entered for ${from === to ? longDate(from) : 'this period'}.`}
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </>
        )}
      </section>

      {form && (
        <form className="card panel" onSubmit={save} noValidate>
          <div className="panel-head">
            <h2>{form.id ? 'Correct this expense' : 'Add an expense'}</h2>
            <button type="button" className="btn ghost" onClick={() => open(null)}>
              Close
            </button>
          </div>
          <div className="grid">
            <TextField label="Amount (₹)" required type="number" min={0} step="0.01" inputMode="decimal" autoFocus value={form.amount} onChange={set('amount')} error={errors.amount} />
            <SelectField label="Paid by" required value={form.mode} onChange={set('mode')} options={PAYMENT_MODES} error={errors.mode} />
            <div className="span-2">
              <TextField label="What for" required list="expense-headings" placeholder="Choose, or type your own" value={form.category} onChange={set('category')} error={errors.category ?? errors.category_id} />
              <datalist id="expense-headings">
                {[...(data?.headings.filter((h) => !h.is_payroll).map((h) => h.name) ?? []), ...(data?.categories ?? [])]
                  .filter((c, i, all) => all.indexOf(c) === i)
                  .map((c) => (
                    <option key={c} value={c} />
                  ))}
              </datalist>
            </div>
            <div className="span-2">
              <TextField label="Paid to" placeholder="Person or shop (optional)" value={form.paid_to} onChange={set('paid_to')} error={errors.paid_to} />
            </div>
            <TextField label="Bill no." placeholder="Optional" value={form.bill_no} onChange={set('bill_no')} error={errors.bill_no} />
            <TextField label="Of which tax (₹)" type="number" min={0} step="0.01" placeholder="0" value={form.tax} onChange={set('tax')} error={errors.tax} />
            {(data?.departments.length ?? 0) > 0 && (
              <div className="span-2">
                <SelectField label="Department" value={form.department_id} onChange={set('department_id')} options={(data?.departments ?? []).map((d) => ({ value: String(d.id), label: d.name }))} placeholder="None" error={errors.department_id} />
              </div>
            )}
            <div className="span-2">
              <TextField label="Details" placeholder="Optional" value={form.description} onChange={set('description')} error={errors.description} />
            </div>
            <TextField label="Date" required type="date" max={data?.today ?? today()} value={form.spent_on} onChange={set('spent_on')} error={errors.spent_on} />
            {form.mode !== 'cash' && <TextField label="Reference" placeholder="UPI or cheque number" value={form.reference} onChange={set('reference')} error={errors.reference} />}
          </div>
          {!form.id && (
            <div className="field">
              <span className="field-label">This is</span>
              <div className="tabs flush" role="group" aria-label="Stage">
                <button type="button" className={form.stage === 'paid' ? 'active' : ''} onClick={() => setForm((f) => f && { ...f, stage: 'paid' })}>
                  Paid now
                </button>
                <button type="button" className={form.stage === 'submitted' ? 'active' : ''} onClick={() => setForm((f) => f && { ...f, stage: 'submitted' })}>
                  To be approved
                </button>
                <button type="button" className={form.stage === 'draft' ? 'active' : ''} onClick={() => setForm((f) => f && { ...f, stage: 'draft' })}>
                  A draft
                </button>
              </div>
            </div>
          )}
          {hint && <p className={form.stage === 'paid' && !form.id ? 'form-error' : 'muted small'}>{hint}</p>}
          {form.stage === 'paid' && form.mode === 'cash' && !form.id && <p className="muted small">Cash: this comes off the cash expected in the drawer for {longDate(form.spent_on)}.</p>}
          {form.stage !== 'paid' && !form.id && <p className="muted small">Nothing comes off the drawer until it has been approved and paid.</p>}
          {errors._ && <p className="form-error">{errors._}</p>}
          <div className="panel-foot">
            <button className="btn primary" disabled={busy || !form.amount || Number(form.amount) <= 0 || !form.category.trim()}>
              {busy ? 'Saving…' : form.id ? 'Save changes' : form.stage === 'paid' ? 'Save expense' : form.stage === 'submitted' ? 'Send for approval' : 'Keep as a draft'}
            </button>
          </div>
        </form>
      )}

      {acting && (
        <form
          className="card panel"
          noValidate
          onSubmit={(e) => {
            e.preventDefault()
            const r = acting.row
            if (acting.what === 'cancel') void step(r, 'cancel', { reason }, `${r.expense_no} cancelled. It stays in the list, struck out, and no longer counts.`)
            if (acting.what === 'refuse') void step(r, 'decide', { approve: false, note: reason }, `${r.expense_no} refused. Whoever entered it can put it right and send it again.`)
            if (acting.what === 'pay') void step(r, 'pay', payment, `${r.expense_no} paid.`)
          }}
        >
          <div className="panel-head">
            <h2>
              {acting.what === 'cancel' ? 'Cancel' : acting.what === 'refuse' ? 'Refuse' : 'Pay'} {acting.row.expense_no}
            </h2>
            <button type="button" className="btn ghost" onClick={() => setActing(null)}>
              Close
            </button>
          </div>
          <p>
            <strong>{acting.row.category}</strong>
            {acting.row.paid_to ? ` · ${acting.row.paid_to}` : ''} · {rupees(acting.row.amount)}
          </p>
          {acting.what === 'pay' ? (
            <>
              <p className="muted small">This writes down a payment made at the counter or the bank. Cash comes off the drawer on the day it is paid.</p>
              <div className="grid">
                <TextField label="Paid on" required type="date" max={data?.today ?? today()} value={payment.paid_on} onChange={(v) => setPayment((p) => ({ ...p, paid_on: v }))} error={errors.paid_on} />
                <SelectField label="Paid by" value={payment.mode} onChange={(v) => setPayment((p) => ({ ...p, mode: v }))} options={PAYMENT_MODES} error={errors.mode} />
              </div>
              {payment.mode !== 'cash' && <TextField label="Reference" value={payment.reference} onChange={(v) => setPayment((p) => ({ ...p, reference: v }))} />}
            </>
          ) : (
            <>
              <p className="muted small">{acting.what === 'cancel' ? 'For an entry made by mistake. It stays in the list, struck out, and stops counting. To change the amount or heading, use Edit instead.' : 'Whoever entered it sees why, and can put it right and send it again.'}</p>
              <input placeholder={acting.what === 'cancel' ? 'Reason for cancelling (required)' : 'Why it is refused (required)'} value={reason} onChange={(e) => setReason(e.target.value)} aria-label="Reason" autoFocus />
            </>
          )}
          {(errors.reason || errors.note || errors._ || errors.approve) && <p className="form-error">{errors.reason ?? errors.note ?? errors.approve ?? errors._}</p>}
          <div className="panel-foot">
            <button className={acting.what === 'pay' ? 'btn primary' : 'btn danger-btn'} disabled={busy || (acting.what !== 'pay' && reason.trim().length < 3)}>
              {acting.what === 'cancel' ? 'Cancel this expense' : acting.what === 'refuse' ? 'Refuse' : 'Record the payment'}
            </button>
          </div>
        </form>
      )}
    </div>
  )
}

type SummaryData = {
  total: number
  tax: number
  count: number
  payroll: number
  by_heading: { name: string; count: number; amount: number; tax: number }[]
  by_department: { name: string; count: number; amount: number }[]
  by_month: { month: string; amount: number }[]
  waiting: { count: number; amount: number }
  to_pay: { count: number; amount: number }
}

/** Where the money went in the chosen dates, with what is still in hand. */
function Summary({ from, to }: { from: string; to: string }) {
  const [data, setData] = useState<{ key: string; s: SummaryData } | null>(null)
  const [failed, setFailed] = useState('')
  const key = `${from}|${to}`
  useEffect(() => {
    api
      .get<SummaryData>('/expenses/summary', { params: { from, to } })
      .then((res) => {
        setData({ key, s: res.data })
        setFailed('')
      })
      .catch(() => setFailed('Could not load the summary.'))
  }, [from, to, key])
  const s = data?.key === key ? data.s : null
  if (failed) return <p className="form-error">{failed}</p>
  if (!s) return <p className="loading">Loading the summary…</p>
  const table = (title: string, rows: { name: string; count?: number; amount: number }[]) => (
    <div className="day-section">
      <h3>{title}</h3>
      <table className="compact">
        <tbody>
          {rows.map((r) => (
            <tr key={r.name} className="static">
              <td>
                {r.name}
                {r.count !== undefined && (
                  <small className="muted block">
                    {r.count} {r.count === 1 ? 'entry' : 'entries'}
                  </small>
                )}
              </td>
              <td className="num">{rupees(r.amount)}</td>
            </tr>
          ))}
          {rows.length === 0 && (
            <tr className="empty">
              <td colSpan={2}>Nothing paid in these dates.</td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  )

  return (
    <>
      <div className="tiles small">
        <div className="tile">
          <span>Paid out</span>
          <strong>{rupees(s.total)}</strong>
          <small className="muted">{s.count} entries</small>
        </div>
        <div className="tile">
          <span>Of which salaries and staff advances</span>
          <strong>{rupees(s.payroll)}</strong>
          <small className="muted">from Payroll and Advances</small>
        </div>
        <div className="tile">
          <span>Tax inside these</span>
          <strong>{rupees(s.tax)}</strong>
        </div>
        <div className="tile">
          <span>Waiting for approval</span>
          <strong>{rupees(s.waiting.amount)}</strong>
          <small className="muted">{s.waiting.count} entries, any date</small>
        </div>
        <div className="tile">
          <span>Approved, not yet paid</span>
          <strong className={s.to_pay.count ? 'due' : ''}>{rupees(s.to_pay.amount)}</strong>
          <small className="muted">{s.to_pay.count} entries, any date</small>
        </div>
      </div>
      <div className="wf-lists">
        {table('By heading', s.by_heading)}
        {table('By department', s.by_department)}
      </div>
      {s.by_month.length > 1 && table('By month', s.by_month.map((m) => ({ name: new Date(`${m.month}-01T00:00:00`).toLocaleDateString('en-IN', { month: 'long', year: 'numeric' }), amount: m.amount })))}
    </>
  )
}

type Repeat = { id: number; name: string; category_id: number | null; category: string | null; department_id: number | null; department: string | null; paid_to: string | null; amount: string; mode: string | null; day_of_month: number | null; is_active: boolean; last_offered_period: string | null; due: boolean }

/** The headings with their approval rules, and the expenses that come round every month. */
function SetUp({ onChanged }: { onChanged: () => void }) {
  const [headings, setHeadings] = useState<Heading[] | null>(null)
  const [repeats, setRepeats] = useState<Repeat[] | null>(null)
  const [editing, setEditing] = useState<{ id?: number; name: string; approval_above: string; approver_limit: string; is_active: boolean } | null>(null)
  const [repeat, setRepeat] = useState<{ id?: number; name: string; category_id: string; paid_to: string; amount: string; mode: string; day_of_month: string; is_active: boolean } | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const load = useCallback(() => {
    api.get<Heading[]>('/expenses/headings').then((res) => setHeadings(res.data)).catch(() => setHeadings([]))
    api.get<Repeat[]>('/expenses/repeating').then((res) => setRepeats(res.data)).catch(() => setRepeats([]))
  }, [])
  useEffect(load, [load])
  const num = (v: string | number | null | undefined) => (v === null || v === undefined ? '' : String(Number(v)))

  const send = async (go: () => Promise<unknown>, done: string, after: () => void) => {
    setBusy(true)
    setErrors({})
    try {
      await go()
      setNotice(done)
      after()
      load()
      onChanged()
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      {notice && <p className="notice">{notice}</p>}
      <div className="day-section">
        <div className="row between wrap">
          <h3>Headings</h3>
          <button className="btn small-btn" onClick={() => (setEditing({ name: '', approval_above: '', approver_limit: '', is_active: true }), setErrors({}))}>
            + Add a heading
          </button>
        </div>
        <p className="muted small">A heading with an approval amount asks for approval above it (0: always). Above the approver’s limit it needs “Approve large expenses”. A heading with neither is entered as paid, as today.</p>
        <table className="compact">
          <tbody>
            {headings?.map((h) => (
              <tr key={h.id} className={h.is_payroll ? 'static' : ''} onClick={() => !h.is_payroll && (setEditing({ id: h.id, name: h.name, approval_above: num(h.approval_above), approver_limit: num(h.approver_limit), is_active: h.is_active !== false }), setErrors({}))}>
                <td>
                  {h.name}
                  <small className="muted block">{h.is_payroll ? 'Written by Payroll and Advances' : approvalHint(h, Infinity) ?? 'No approval needed'}</small>
                </td>
                <td className="num">{h.is_active === false && <span className="pill status-booked">Off</span>}</td>
              </tr>
            ))}
            {headings && headings.length === 0 && (
              <tr className="empty">
                <td colSpan={2}>No headings set up yet. Typed headings still work as before.</td>
              </tr>
            )}
          </tbody>
        </table>
        {editing && (
          <form
            className="gap-top"
            noValidate
            onSubmit={(e) => {
              e.preventDefault()
              const body = { name: editing.name, approval_above: editing.approval_above === '' ? null : editing.approval_above, approver_limit: editing.approver_limit === '' ? null : editing.approver_limit, is_active: editing.is_active }
              void send(() => (editing.id ? api.put(`/expenses/headings/${editing.id}`, body) : api.post('/expenses/headings', body)), 'Heading saved.', () => setEditing(null))
            }}
          >
            <div className="grid three">
              <TextField label="Name" required value={editing.name} onChange={(v) => setEditing((h) => h && { ...h, name: v })} error={errors.name} />
              <TextField label="Approval needed above (₹)" type="number" min={0} value={editing.approval_above} onChange={(v) => setEditing((h) => h && { ...h, approval_above: v })} placeholder="Never" error={errors.approval_above} />
              <TextField label="Large approval above (₹)" type="number" min={0} value={editing.approver_limit} onChange={(v) => setEditing((h) => h && { ...h, approver_limit: v })} placeholder="No limit" error={errors.approver_limit} />
            </div>
            <label className="check">
              <input type="checkbox" checked={editing.is_active} onChange={(e) => setEditing((h) => h && { ...h, is_active: e.target.checked })} />
              In use
            </label>
            <div className="row gap-top">
              <button className="btn primary" disabled={busy || !editing.name.trim()}>
                Save
              </button>
              <button type="button" className="btn ghost" onClick={() => setEditing(null)}>
                Cancel
              </button>
            </div>
          </form>
        )}
      </div>

      <div className="day-section">
        <div className="row between wrap">
          <h3>Every month</h3>
          <button className="btn small-btn" disabled={!headings?.some((h) => !h.is_payroll)} onClick={() => (setRepeat({ name: '', category_id: '', paid_to: '', amount: '', mode: 'bank_transfer', day_of_month: '1', is_active: true }), setErrors({}))}>
            + Add a repeating expense
          </button>
        </div>
        <p className="muted small">Rent, a service contract, the internet bill. Each month it offers a draft to check, approve and pay. Nothing is paid or counted by itself.</p>
        <table className="compact">
          <tbody>
            {repeats?.map((t) => (
              <tr key={t.id} className="static">
                <td>
                  <button className="link" onClick={() => (setRepeat({ id: t.id, name: t.name, category_id: String(t.category_id ?? ''), paid_to: t.paid_to ?? '', amount: num(t.amount), mode: t.mode ?? 'cash', day_of_month: String(t.day_of_month ?? 1), is_active: t.is_active }), setErrors({}))}>
                    {t.name}
                  </button>
                  <small className="muted block">{[t.category, t.paid_to, `on the ${ordinal(t.day_of_month ?? 1)}`].filter(Boolean).join(' · ')}</small>
                </td>
                <td className="num">{rupees(Number(t.amount))}</td>
                <td className="num">
                  {t.due ? (
                    <button className="btn primary small-btn" disabled={busy} onClick={() => void send(() => api.post(`/expenses/repeating/${t.id}/use`), `This month’s draft for ${t.name} is under Drafts. Check it and send it on.`, () => undefined)}>
                      Make this month’s draft
                    </button>
                  ) : (
                    <small className="muted">{!t.is_active ? 'Off' : t.last_offered_period ? 'Done this month' : 'Not due yet'}</small>
                  )}
                </td>
              </tr>
            ))}
            {repeats && repeats.length === 0 && (
              <tr className="empty">
                <td colSpan={3}>None yet.</td>
              </tr>
            )}
          </tbody>
        </table>
        {repeat && (
          <form
            className="gap-top"
            noValidate
            onSubmit={(e) => {
              e.preventDefault()
              const body = { ...repeat, category_id: repeat.category_id || null, day_of_month: Number(repeat.day_of_month) || 1 }
              void send(() => (repeat.id ? api.put(`/expenses/repeating/${repeat.id}`, body) : api.post('/expenses/repeating', body)), 'Repeating expense saved.', () => setRepeat(null))
            }}
          >
            <div className="grid">
              <TextField label="Called" required value={repeat.name} onChange={(v) => setRepeat((r) => r && { ...r, name: v })} placeholder="Building rent" error={errors.name} />
              <SelectField label="Heading" required value={repeat.category_id} onChange={(v) => setRepeat((r) => r && { ...r, category_id: v })} options={(headings ?? []).filter((h) => !h.is_payroll).map((h) => ({ value: String(h.id), label: h.name }))} placeholder="Choose" error={errors.category_id} />
              <TextField label="Paid to" value={repeat.paid_to} onChange={(v) => setRepeat((r) => r && { ...r, paid_to: v })} error={errors.paid_to} />
              <TextField label="Usual amount (₹)" required type="number" min={1} value={repeat.amount} onChange={(v) => setRepeat((r) => r && { ...r, amount: v })} error={errors.amount} />
              <SelectField label="Usually paid by" value={repeat.mode} onChange={(v) => setRepeat((r) => r && { ...r, mode: v })} options={PAYMENT_MODES} />
              <TextField label="Due on day" type="number" min={1} max={28} value={repeat.day_of_month} onChange={(v) => setRepeat((r) => r && { ...r, day_of_month: v })} error={errors.day_of_month} />
            </div>
            <label className="check">
              <input type="checkbox" checked={repeat.is_active} onChange={(e) => setRepeat((r) => r && { ...r, is_active: e.target.checked })} />
              In use
            </label>
            <div className="row gap-top">
              <button className="btn primary" disabled={busy || !repeat.name.trim() || !repeat.category_id || !repeat.amount}>
                Save
              </button>
              <button type="button" className="btn ghost" onClick={() => setRepeat(null)}>
                Cancel
              </button>
            </div>
          </form>
        )}
      </div>
    </>
  )
}
