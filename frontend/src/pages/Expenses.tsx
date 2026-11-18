import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors, type FieldErrors } from '../api'
import { DateRange } from '../components/DateRange'
import { SelectField, TextField } from '../components/Field'
import { PAYMENT_MODES, downloadCsv, longDate, modeLabel, rupees, today } from '../format'
import { useUnsaved } from '../unsaved'

type Expense = {
  id: number
  expense_no: string
  spent_on: string
  category: string
  paid_to: string | null
  description: string | null
  amount: number
  mode: string
  reference: string | null
  creator?: { id: number; name: string } | null
  cancelled_at: string | null
  cancel_reason: string | null
  canceller?: { id: number; name: string } | null
}
type PaidOut = { expenses: number; expenses_cash: number; suppliers: number; suppliers_cash: number; cash: number; total: number; by_category: Record<string, number>; by_mode: Record<string, number> }
type List = { from: string; to: string; rows: Expense[]; paid_out: PaidOut; categories: string[]; modes: string[]; today: string }
type Form = { id?: number; spent_on: string; category: string; paid_to: string; description: string; amount: string; mode: string; reference: string }

const blank = (day: string): Form => ({ spent_on: day, category: '', paid_to: '', description: '', amount: '', mode: 'cash', reference: '' })

/** Money paid out day to day. Whoever keeps the cash writes each payment down here as it is made, so the drawer agrees at the day close. */
export function Expenses() {
  const [from, setFrom] = useState(today())
  const [to, setTo] = useState(today())
  const [query, setQuery] = useState('')
  const [data, setData] = useState<List | null>(null)
  const [form, setForm] = useState<Form | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [cancelling, setCancelling] = useState<Expense | null>(null)
  const [reason, setReason] = useState('')
  const [notice, setNotice] = useState('')
  const [failed, setFailed] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    api
      .get<List>('/expenses', { params: { from, to, q: query.trim() || undefined } })
      .then((res) => {
        setData(res.data)
        setFailed('')
      })
      .catch(() => setFailed('Could not load the expenses.'))
  }, [from, to, query])

  useEffect(() => {
    const timer = setTimeout(load, 250)
    return () => clearTimeout(timer)
  }, [load])

  useUnsaved(Boolean(form && (form.amount !== '' || form.category !== '' || form.paid_to !== '')) && !form?.id, 'This expense is not saved yet. Leave without saving it?')

  const open = (next: Form | null) => {
    setForm(next)
    setErrors({})
    setCancelling(null)
    setNotice('')
  }

  const save = async (e: FormEvent) => {
    e.preventDefault()
    if (!form || busy) return
    setBusy(true)
    setErrors({})
    const body = { spent_on: form.spent_on, category: form.category.trim(), paid_to: form.paid_to.trim() || null, description: form.description.trim() || null, amount: form.amount, mode: form.mode, reference: form.reference.trim() || null }
    try {
      const res = await (form.id ? api.put<Expense>(`/expenses/${form.id}`, body) : api.post<Expense>('/expenses', body))
      setNotice(form.id ? `${res.data.expense_no} corrected.` : `${res.data.expense_no} saved: ${rupees(res.data.amount)} for ${res.data.category}.`)
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

  const cancel = async (e: FormEvent) => {
    e.preventDefault()
    if (!cancelling || busy) return
    setBusy(true)
    setErrors({})
    try {
      await api.post(`/expenses/${cancelling.id}/cancel`, { reason })
      setNotice(`${cancelling.expense_no} cancelled. It stays in the list, struck out, and no longer counts.`)
      setCancelling(null)
      setReason('')
      load()
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setBusy(false)
    }
  }

  const exportCsv = () =>
    downloadCsv(
      `expenses-${from}-to-${to}.csv`,
      ['No.', 'Date', 'For', 'Paid to', 'Details', 'Amount', 'Mode', 'Reference', 'Entered by', 'Cancelled', 'Reason'],
      (data?.rows ?? []).map((r) => [r.expense_no, r.spent_on, r.category, r.paid_to, r.description, r.amount, modeLabel(r.mode), r.reference, r.creator?.name, r.cancelled_at ? 'Yes' : '', r.cancel_reason]),
    )

  const out = data?.paid_out
  const headings = Object.entries(out?.by_category ?? {})
  const set = (key: keyof Form) => (value: string) => setForm((f) => (f ? { ...f, [key]: value } : f))

  return (
    <div className="split">
      <section className="card">
        <div className="toolbar">
          <h1>Expenses</h1>
          <DateRange from={from} to={to} onChange={(f, t) => { setFrom(f); setTo(t) }} />
          <input className="search" type="search" placeholder="Find by who was paid, details or number" value={query} onChange={(e) => setQuery(e.target.value)} />
          <span className="grow" />
          <button className="btn ghost" onClick={exportCsv} disabled={!data?.rows.length}>
            Export
          </button>
          <button className="btn primary" onClick={() => open(blank(data?.today ?? today()))}>
            + Add expense
          </button>
        </div>
        <p className="muted small">
          Write down each payment out as it is made: wages advanced, tea, repairs, a courier. Cash ones come off the cash expected at the <Link to="/reports">day close</Link>. Payments to medicine suppliers are entered on the purchase invoice, not here.
        </p>
        {failed && <p className="form-error">{failed}</p>}
        {notice && <p className="notice">{notice}</p>}

        {out && (
          <div className="tiles small">
            <div className="tile"><span>Expenses</span><strong>{rupees(out.expenses)}</strong></div>
            <div className="tile"><span>Of these, in cash</span><strong>{rupees(out.expenses_cash)}</strong></div>
            <div className="tile"><span>Paid to medicine suppliers</span><strong>{rupees(out.suppliers)}</strong></div>
            <div className="tile"><span>All cash paid out</span><strong className={out.cash > 0 ? 'due' : ''}>{rupees(out.cash)}</strong></div>
          </div>
        )}
        {headings.length > 0 && (
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
                <th>Mode</th>
                <th>Entered by</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {data?.rows.map((r) => (
                <tr key={r.id} className={`static ${r.cancelled_at ? 'faded' : ''} ${form?.id === r.id || cancelling?.id === r.id ? 'selected' : ''}`}>
                  <td data-label="No.">
                    <span className="mono">{r.expense_no}</span>
                    <small className="muted block">{longDate(r.spent_on)}</small>
                  </td>
                  <td data-label="For">
                    <strong>{r.category}</strong>
                    {r.paid_to && <span> · {r.paid_to}</span>}
                    {r.description && <small className="muted block">{r.description}</small>}
                    {r.cancelled_at && (
                      <small className="block due">
                        Cancelled{r.canceller ? ` by ${r.canceller.name}` : ''}: {r.cancel_reason}
                      </small>
                    )}
                  </td>
                  <td className="num" data-label="Amount">
                    {r.cancelled_at ? <s>{rupees(r.amount)}</s> : <strong>{rupees(r.amount)}</strong>}
                  </td>
                  <td data-label="Mode">
                    {modeLabel(r.mode)}
                    {r.reference && <small className="muted block">{r.reference}</small>}
                  </td>
                  <td data-label="Entered by">{r.creator?.name ?? '—'}</td>
                  <td className="num actions">
                    {!r.cancelled_at && (
                      <>
                        <button
                          className="link"
                          onClick={() => open({ id: r.id, spent_on: r.spent_on, category: r.category, paid_to: r.paid_to ?? '', description: r.description ?? '', amount: String(r.amount), mode: r.mode, reference: r.reference ?? '' })}
                        >
                          Edit
                        </button>
                        <button
                          className="link danger"
                          onClick={() => {
                            open(null)
                            setCancelling(r)
                            setReason('')
                          }}
                        >
                          Cancel
                        </button>
                      </>
                    )}
                  </td>
                </tr>
              ))}
              {data && data.rows.length === 0 && (
                <tr className="empty">
                  <td colSpan={6}>No expenses entered for {from === to ? longDate(from) : 'this period'}.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
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
              <TextField label="What for" required list="expense-headings" placeholder="Choose, or type your own" value={form.category} onChange={set('category')} error={errors.category} />
              <datalist id="expense-headings">
                {data?.categories.map((c) => (
                  <option key={c} value={c} />
                ))}
              </datalist>
            </div>
            <div className="span-2">
              <TextField label="Paid to" placeholder="Person or shop (optional)" value={form.paid_to} onChange={set('paid_to')} error={errors.paid_to} />
            </div>
            <div className="span-2">
              <TextField label="Details" placeholder="Optional" value={form.description} onChange={set('description')} error={errors.description} />
            </div>
            <TextField label="Date" required type="date" max={data?.today ?? today()} value={form.spent_on} onChange={set('spent_on')} error={errors.spent_on} />
            {form.mode !== 'cash' && <TextField label="Reference" placeholder="UPI or cheque number" value={form.reference} onChange={set('reference')} error={errors.reference} />}
          </div>
          {form.mode === 'cash' && <p className="muted small">Cash: this comes off the cash expected in the drawer for {longDate(form.spent_on)}.</p>}
          {errors._ && <p className="form-error">{errors._}</p>}
          <div className="panel-foot">
            <button className="btn primary" disabled={busy || !form.amount || Number(form.amount) <= 0 || !form.category.trim()}>
              {busy ? 'Saving…' : form.id ? 'Save changes' : 'Save expense'}
            </button>
          </div>
        </form>
      )}

      {cancelling && (
        <form className="card panel" onSubmit={cancel}>
          <div className="panel-head">
            <h2>Cancel {cancelling.expense_no}</h2>
            <button type="button" className="btn ghost" onClick={() => setCancelling(null)}>
              Close
            </button>
          </div>
          <p>
            <strong>{cancelling.category}</strong>
            {cancelling.paid_to ? ` · ${cancelling.paid_to}` : ''} · {rupees(cancelling.amount)} by {modeLabel(cancelling.mode)} on {longDate(cancelling.spent_on)}
          </p>
          <p className="muted small">For an entry made by mistake. It stays in the list, struck out, and stops counting. To change the amount or heading, use Edit instead.</p>
          <input placeholder="Reason for cancelling (required)" value={reason} onChange={(e) => setReason(e.target.value)} aria-label="Reason" autoFocus />
          {(errors.reason || errors._) && <p className="form-error">{errors.reason ?? errors._}</p>}
          <div className="panel-foot">
            <button className="btn danger-btn" disabled={busy || reason.trim().length < 3}>
              Cancel this expense
            </button>
          </div>
        </form>
      )}
    </div>
  )
}
