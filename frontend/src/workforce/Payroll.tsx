import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors, type FieldErrors } from '../api'
import { SelectField, TextField } from '../components/Field'
import { longDate, today } from '../format'
import { useChoiceInAddress } from '../rangeInAddress'
import { PasswordAgain } from './PasswordAgain'
import { PAYMENT_CLASS, PAYMENT_LABEL, PAY_MODES, RUN_CLASS, RUN_LABEL, inr, payModeLabel, runActions, type Run, type RunCan, type RunDetail, type Slip, type SlipRow } from './pay'
import { saveFile, usePasswordAgain } from './sensitiveStep'
import { addMonths, hours, thisMonth } from './shared'
import { WorkforceTabs } from './WorkforceTabs'

/** Payroll: the runs month by month, and one run worked through from draft to paid. */
export function WorkforcePayroll() {
  const [open, setOpen] = useChoiceInAddress<string>('run', '')
  return (
    <>
      <WorkforceTabs />
      {open ? <RunPage key={open} id={Number(open)} onBack={() => setOpen('')} onOpen={(id) => setOpen(String(id))} /> : <RunList onOpen={(id) => setOpen(String(id))} />}
    </>
  )
}

function RunList({ onOpen }: { onOpen: (id: number) => void }) {
  const [data, setData] = useState<{ can: RunCan; runs: Run[] } | null>(null)
  const [failed, setFailed] = useState('')
  const [starting, setStarting] = useState(false)
  const [month, setMonth] = useState(addMonths(thisMonth(), -1))
  const [errors, setErrors] = useState<FieldErrors>({})
  const [busy, setBusy] = useState(false)
  const load = useCallback(() => {
    api
      .get<{ can: RunCan; runs: Run[] }>('/workforce/payroll/runs')
      .then((res) => {
        setData(res.data)
        setFailed('')
      })
      .catch(() => setFailed('Could not load the payroll runs. Check the connection and try again.'))
  }, [])
  useEffect(load, [load])

  const start = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    try {
      onOpen((await api.post<RunDetail>('/workforce/payroll/runs', { period: month })).data.id)
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="card">
      <div className="toolbar">
        <h1>Payroll</h1>
        <span className="grow" />
        {data?.can.create && !starting && (
          <button className="btn primary" onClick={() => setStarting(true)}>
            + Start a payroll
          </button>
        )}
      </div>
      {failed && <div className="form-error">{failed}</div>}
      {starting && (
        <form onSubmit={start} noValidate className="day-section">
          <h3>Start a payroll</h3>
          <p className="muted small">
            It is worked out as a draft from attendance, approved leave, each person’s salary and what is due on advances. Nothing is final until it has been reviewed, approved and finalised, each by whoever may.
          </p>
          <div className="row wrap">
            <TextField label="For the month of" required type="month" max={thisMonth()} value={month} onChange={setMonth} error={errors.period} />
            <button className="btn primary" disabled={busy || !month}>
              {busy ? 'Working it out…' : 'Work it out'}
            </button>
            <button type="button" className="btn ghost" onClick={() => setStarting(false)}>
              Cancel
            </button>
          </div>
        </form>
      )}
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Month</th>
              <th>Stage</th>
              <th className="num">Staff</th>
              <th className="num">Gross</th>
              <th className="num">Deductions</th>
              <th className="num">Net pay</th>
              <th>Payment</th>
            </tr>
          </thead>
          <tbody>
            {data?.runs.map((r) => (
              <tr key={r.id} onClick={() => onOpen(r.id)}>
                <td>
                  <strong>{r.period_name}</strong>
                  {(r.label || r.replaces_run_id) && <small className="muted block">{r.label ?? 'Run again after a reversal'}</small>}
                </td>
                <td>
                  <span className={`pill ${RUN_CLASS[r.status]}`}>{RUN_LABEL[r.status]}</span>
                </td>
                <td className="num">{r.staff}</td>
                <td className="num">{inr(r.gross)}</td>
                <td className="num">{inr(r.deductions)}</td>
                <td className="num">
                  <strong>{inr(r.net)}</strong>
                </td>
                <td>{r.unpaid ? r.unpaid.slips === 0 ? <span className="settled">All paid</span> : <span className="due">{inr(r.unpaid.net)} to {r.unpaid.slips} still to pay</span> : <span className="muted">—</span>}</td>
              </tr>
            ))}
            {data && data.runs.length === 0 && (
              <tr className="empty">
                <td colSpan={7}>No payroll has been run here yet.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </section>
  )
}

const STAGES: [keyof RunDetail['steps'], string][] = [['prepared', 'Prepared'], ['reviewed', 'Reviewed'], ['approved', 'Approved'], ['finalised', 'Finalised']]

function RunPage({ id, onBack, onOpen }: { id: number; onBack: () => void; onOpen: (id: number) => void }) {
  const [run, setRun] = useState<RunDetail | null>(null)
  const [failed, setFailed] = useState('')
  const [notice, setNotice] = useState<{ text: string; bad?: boolean } | null>(null)
  const [busy, setBusy] = useState(false)
  const [asking, setAsking] = useState<'send_back' | 'reverse' | 'pay' | null>(null)
  const [reason, setReason] = useState('')
  const [ticked, setTicked] = useState<number[]>([])
  const [payment, setPayment] = useState({ status: 'paid', paid_on: today(), payment_mode: 'bank', payment_reference: '', reason: '' })
  const [slip, setSlip] = useState<number | null>(null)
  const { run: withPassword, ask } = usePasswordAgain()

  useEffect(() => {
    api
      .get<RunDetail>(`/workforce/payroll/runs/${id}`)
      .then((res) => setRun(res.data))
      .catch(() => setFailed('This payroll could not be opened.'))
  }, [id])

  /** Sends one step to the server and shows the run as it comes back. */
  const send = async (step: () => Promise<{ data: RunDetail }>, done: string) => {
    setBusy(true)
    setNotice(null)
    try {
      setRun((await step()).data)
      setNotice({ text: done })
      setAsking(null)
      setReason('')
      setTicked([])
    } catch (err) {
      if ((err as Error).message !== 'Cancelled') setNotice({ text: Object.values(fieldErrors(err))[0] ?? 'That did not go through. Try again.', bad: true })
    } finally {
      setBusy(false)
    }
  }
  const post = (path: string, body: Record<string, unknown> = {}) => () => api.post<RunDetail>(`/workforce/payroll/runs/${id}/${path}`, body)

  if (failed) return <section className="card"><div className="form-error">{failed}</div></section>
  if (!run) return <section className="card"><p className="loading">Loading the payroll…</p></section>

  const actions = runActions(run)
  const unpaid = run.payslips.filter((s) => s.payment_status !== 'paid')
  const final = run.status === 'finalised' || run.status === 'paid'
  const paying = asking === 'pay'

  return (
    <div className={slip ? 'split' : ''}>
      <section className="card grow">
        <div className="toolbar">
          <button className="btn ghost" onClick={onBack}>
            ← All runs
          </button>
          <h1>Payroll for {run.period_name}</h1>
          <span className={`pill ${RUN_CLASS[run.status]}`}>{RUN_LABEL[run.status]}</span>
          <span className="grow" />
          {actions.includes('work_out') && (
            <button className="btn" disabled={busy} onClick={() => void send(post('work-out'), 'Worked out again from the records as they stand now.')}>
              Work it out again
            </button>
          )}
          {actions.includes('review') && (
            <button className="btn primary" disabled={busy} onClick={() => void send(post('step', { to: 'reviewed' }), 'Marked as reviewed. It now waits for approval by someone else.')}>
              Mark as reviewed
            </button>
          )}
          {actions.includes('approve') && (
            <button className="btn primary" disabled={busy} onClick={() => void send(post('step', { to: 'approved' }), 'Approved. It can now be finalised.')}>
              Approve
            </button>
          )}
          {actions.includes('finalise') && (
            <button
              className="btn primary"
              disabled={busy}
              onClick={() => window.confirm(`Finalise the payroll for ${run.period_name}?\n\nAfter this nothing in it can be changed. A mistake can only be put right by reversing the whole run.`) && void send(() => withPassword(post('finalise')), 'Finalised. The payslips are numbered and can be printed, and salaries can be recorded as paid.')}
            >
              Finalise
            </button>
          )}
          {actions.includes('send_back') && (
            <button className="btn ghost" disabled={busy} onClick={() => setAsking('send_back')}>
              Send back to draft
            </button>
          )}
          {actions.includes('export') && (
            <button className="btn ghost" disabled={busy} onClick={() => void withPassword(() => saveFile(`/workforce/payroll/runs/${id}/export`, {}, `payroll-${run.period}.csv`)).catch(() => undefined)}>
              Export the register
            </button>
          )}
          {actions.includes('reverse') && (
            <button className="btn ghost danger" disabled={busy} onClick={() => setAsking('reverse')}>
              Reverse
            </button>
          )}
        </div>

        {notice && <p className={notice.bad ? 'form-error' : 'notice'}>{notice.text}</p>}
        {run.status === 'reviewed' && run.can.approve && run.can.approve_this === false && <p className="muted small">You prepared or reviewed this payroll, so someone else must approve it.</p>}
        {run.status === 'reversed' && (
          <p className="form-error">
            This payroll was reversed{run.steps.reversed ? ` by ${run.steps.reversed.name} on ${longDate(run.steps.reversed.at!.slice(0, 10))}` : ''}: {run.reverse_reason}. Its payslips are kept for the record and no longer stand.
          </p>
        )}
        {(asking === 'send_back' || asking === 'reverse') && (
          <div className="day-section">
            <h3>{asking === 'reverse' ? 'Reverse this payroll' : 'Send it back to draft'}</h3>
            {asking === 'reverse' && (
              <p className="muted small">
                The run and its payslips stay on record, marked reversed. The month is freed so the payroll can be run again, and anything taken for advances is put back as owed.
                {run.payslips.some((s) => s.payment_status === 'paid') ? ' Some of these salaries are already recorded as paid: the money has left, and the new run will not know that. Say in the reason how it will be settled.' : ''}
              </p>
            )}
            <div className="row wrap">
              <input className="grow" value={reason} onChange={(e) => setReason(e.target.value)} placeholder={asking === 'reverse' ? 'Why it is reversed' : 'Why it is sent back'} aria-label="Reason" />
              <button
                className="btn primary"
                disabled={busy || reason.trim().length < 5}
                onClick={() => void (asking === 'reverse' ? send(() => withPassword(post('reverse', { reason })), 'Reversed. The month can be run again.') : send(post('step', { to: 'draft', reason }), 'Back in draft. Work it out again when the records are right.'))}
              >
                {asking === 'reverse' ? 'Reverse the payroll' : 'Send back'}
              </button>
              <button className="btn ghost" onClick={() => setAsking(null)}>
                Cancel
              </button>
            </div>
          </div>
        )}

        <div className="tiles">
          <div className="tile">
            <span>Staff paid</span>
            <strong>{run.payslips.length}</strong>
            {run.left_out.length > 0 && <small className="due">{run.left_out.length} left out</small>}
          </div>
          <div className="tile">
            <span>Gross</span>
            <strong>{inr(run.gross)}</strong>
          </div>
          <div className="tile">
            <span>Deductions</span>
            <strong>{inr(run.deductions)}</strong>
          </div>
          <div className="tile">
            <span>Net pay</span>
            <strong className="settled">{inr(run.net)}</strong>
          </div>
          <div className="tile">
            <span>Cost to the hospital</span>
            <strong>{inr(run.employer_cost)}</strong>
            <small className="muted">pay earned plus the hospital’s own share</small>
          </div>
        </div>
        <p className="muted small wf-stages">
          {STAGES.map(([key, label]) => (
            <span key={key} className={run.steps[key] ? 'done' : ''}>
              {label}
              {run.steps[key] ? ` by ${run.steps[key]!.name ?? 'someone'}` : ''}
            </span>
          ))}
          {run.status === 'draft' && run.worked_out_at && <span>Worked out {longDate(run.worked_out_at.slice(0, 10))} at {run.worked_out_at.slice(11)}</span>}
        </p>

        {run.left_out.length > 0 && (
          <ul className="todo">
            {run.left_out.map((l) => (
              <li key={l.employee_id} className="warn">
                <span className="wf-todo-line">
                  <span>
                    <strong>{l.name ?? 'A member of staff'}</strong> is not in this payroll. {l.reason}
                  </span>
                  {run.status === 'draft' && run.can.edit && l.reason.startsWith('Left out') && (
                    <button className="link" disabled={busy} onClick={() => void send(post('hold-back', { employee_id: l.employee_id, leave_out: false }), 'Brought back in.')}>
                      Bring back
                    </button>
                  )}
                </span>
              </li>
            ))}
          </ul>
        )}

        {final && run.can.approve && unpaid.length > 0 && !paying && (
          <div className="toolbar minor">
            <span className="muted small grow">Tick the payslips that have been paid at the bank or in cash, then record the payment. This only writes it down: it moves no money. What is paid is posted once to Expenses under “Salaries (from payroll)”, and cash comes off that day’s drawer.</span>
            <button className="btn primary" disabled={ticked.length === 0} onClick={() => setAsking('pay')}>
              Record payment for {ticked.length || 'the ticked'}
            </button>
          </div>
        )}
        {paying && (
          <div className="day-section">
            <h3>
              Record payment for {ticked.length} {ticked.length === 1 ? 'payslip' : 'payslips'}
            </h3>
            <div className="grid three">
              <SelectField label="What happened" value={payment.status} onChange={(v) => setPayment((p) => ({ ...p, status: v }))} options={[{ value: 'paid', label: 'Paid' }, { value: 'held', label: 'Held back for now' }]} />
              {payment.status === 'paid' ? (
                <>
                  <TextField label="Paid on" type="date" max={today()} value={payment.paid_on} onChange={(v) => setPayment((p) => ({ ...p, paid_on: v }))} />
                  <SelectField label="How" value={payment.payment_mode} onChange={(v) => setPayment((p) => ({ ...p, payment_mode: v }))} options={PAY_MODES} />
                  <TextField label="Reference" value={payment.payment_reference} onChange={(v) => setPayment((p) => ({ ...p, payment_reference: v }))} placeholder="Transfer or cheque number" />
                </>
              ) : (
                <TextField label="Why it is held" required value={payment.reason} onChange={(v) => setPayment((p) => ({ ...p, reason: v }))} />
              )}
            </div>
            <div className="row gap-top">
              <button
                className="btn primary"
                disabled={busy || (payment.status === 'paid' ? !payment.paid_on : !payment.reason.trim())}
                onClick={() => void send(post('pay', { payslip_ids: ticked, ...payment }), payment.status === 'paid' ? 'Payment recorded.' : 'Held back.')}
              >
                Save
              </button>
              <button className="btn ghost" onClick={() => setAsking(null)}>
                Cancel
              </button>
            </div>
          </div>
        )}

        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                {final && run.can.approve && <th />}
                <th>Who</th>
                <th className="num">Unpaid days</th>
                <th className="num">Gross</th>
                <th className="num">Deductions</th>
                <th className="num">Net pay</th>
                <th>{final ? 'Payment' : ''}</th>
              </tr>
            </thead>
            <tbody>
              {run.payslips.map((s) => (
                <SlipLine key={s.id} s={s} run={run} final={final} selected={slip === s.id} ticked={ticked.includes(s.id)} onTick={(on) => setTicked((t) => (on ? [...t, s.id] : t.filter((x) => x !== s.id)))} onOpen={() => run.can.see_pay && s.net !== null && setSlip(s.id)} />
              ))}
              {run.payslips.length === 0 && (
                <tr className="empty">
                  <td colSpan={7}>Nobody is in this payroll.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        {!run.can.see_pay && <p className="muted small gap-top">You can see that this payroll exists and what it totals. What each person is paid is shown only to those with “See salaries”.</p>}

        {run.departments && run.departments.length > 1 && (
          <div className="day-section">
            <h3>By department</h3>
            <table className="compact">
              <thead>
                <tr>
                  <th>Department</th>
                  <th className="num">Staff</th>
                  <th className="num">Gross</th>
                  <th className="num">Deductions</th>
                  <th className="num">Net pay</th>
                </tr>
              </thead>
              <tbody>
                {run.departments.map((d) => (
                  <tr key={d.name} className="static">
                    <td>{d.name}</td>
                    <td className="num">{d.staff}</td>
                    <td className="num">{inr(d.gross)}</td>
                    <td className="num">{inr(d.deductions)}</td>
                    <td className="num">{inr(d.net)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {run.replaces_run_id && (
          <p className="muted small gap-top">
            This run was made after an earlier one for the same month was reversed.{' '}
            <button className="link" onClick={() => onOpen(run.replaces_run_id!)}>
              Open the reversed run
            </button>
          </p>
        )}
        <PasswordAgain ask={ask} />
      </section>
      {slip && <SlipPanel key={slip} id={slip} run={run} onClose={() => setSlip(null)} onChanged={setRun} />}
    </div>
  )
}

function SlipLine({ s, run, final, selected, ticked, onTick, onOpen }: { s: SlipRow; run: RunDetail; final: boolean; selected: boolean; ticked: boolean; onTick: (on: boolean) => void; onOpen: () => void }) {
  return (
    <tr className={`${selected ? 'selected' : ''} ${s.net === null ? 'static' : ''}`} onClick={onOpen}>
      {final && run.can.approve && (
        <td onClick={(e) => e.stopPropagation()}>{s.payment_status !== 'paid' && <input type="checkbox" checked={ticked} onChange={(e) => onTick(e.target.checked)} aria-label={`Tick ${s.employee?.name ?? 'payslip'}`} />}</td>
      )}
      <td>
        <strong>{s.employee?.name ?? '—'}</strong>
        <small className="muted block">{[s.slip_no, s.employee?.employee_no, s.employee?.department?.name].filter(Boolean).join(' · ')}</small>
      </td>
      <td className="num">{s.unpaid_days ? s.unpaid_days : ''}</td>
      <td className="num">{inr(s.gross)}</td>
      <td className="num">{inr(s.deductions)}</td>
      <td className="num">
        <strong className={s.net !== null && Number(s.net) < 0 ? 'due' : ''}>{inr(s.net)}</strong>
      </td>
      <td>
        {final || run.status === 'reversed' ? (
          <>
            <span className={`pill ${PAYMENT_CLASS[s.payment_status]}`}>{PAYMENT_LABEL[s.payment_status]}</span>
            {s.paid_on && <small className="muted block">{longDate(s.paid_on)} · {payModeLabel(s.payment_mode)}</small>}
          </>
        ) : (
          <span className="muted small">
            {[s.notes ? `${s.notes} ${s.notes === 1 ? 'note' : 'notes'}` : '', s.one_offs ? `${s.one_offs} added by hand` : ''].filter(Boolean).join(' · ')}
          </span>
        )}
      </td>
    </tr>
  )
}

/** One payslip beside the run: every line with how it was arrived at, and, in a draft, lines added by hand. */
function SlipPanel({ id, run, onClose, onChanged }: { id: number; run: RunDetail; onClose: () => void; onChanged: (run: RunDetail) => void }) {
  const [slip, setSlip] = useState<Slip | null>(null)
  const [failed, setFailed] = useState('')
  const [form, setForm] = useState({ kind: 'earning', name: '', amount: '', note: '' })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [busy, setBusy] = useState(false)
  const [leaving, setLeaving] = useState(false)
  const [why, setWhy] = useState('')
  const load = useCallback(() => {
    api
      .get<Slip>(`/workforce/payroll/payslips/${id}`)
      .then((res) => setSlip(res.data))
      .catch(() => setFailed('This payslip could not be opened.'))
  }, [id])
  useEffect(load, [load])
  const draft = run.status === 'draft' && run.can.edit

  const change = async (step: () => Promise<{ data: RunDetail }>, after?: () => void) => {
    setBusy(true)
    setErrors({})
    try {
      onChanged((await step()).data)
      after?.()
      load()
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setBusy(false)
    }
  }
  const lines = (kind: string) => slip?.lines.filter((l) => l.kind === kind) ?? []
  const block = (kind: string, heading: string) =>
    lines(kind).length > 0 && (
      <>
        <h3>{heading}</h3>
        <table className="compact">
          <tbody>
            {lines(kind).map((l) => (
              <tr key={l.id} className="static">
                <td className="wrap">
                  {l.name}
                  <small className="muted block">{l.explanation}</small>
                  {l.one_off && draft && (
                    <button className="link small" disabled={busy} onClick={() => void change(() => api.delete<RunDetail>(`/workforce/payroll/runs/${run.id}/payslips/${id}/lines/${l.id}`))}>
                      Remove
                    </button>
                  )}
                </td>
                <td className="num">{inr(l.amount)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </>
    )

  return (
    <aside className="card panel">
      <div className="panel-head">
        <div>
          <h2>{slip?.employee?.name ?? 'Payslip'}</h2>
          <span className="muted">{[slip?.slip_no ?? (slip && !slip.final ? 'Draft' : ''), slip?.period_name].filter(Boolean).join(' · ')}</span>
        </div>
        <button type="button" className="btn ghost" onClick={onClose}>
          Close
        </button>
      </div>
      {failed && <div className="form-error">{failed}</div>}
      {!slip && !failed && <p className="loading">Loading the payslip…</p>}
      {slip && (
        <>
          {slip.days && (
            <p className="muted small">
              {slip.days.employed < slip.days.in_month ? `Employed ${slip.days.employed} of ${slip.days.in_month} days` : `${slip.days.in_month} days in the month`} · {slip.days.present} worked · {slip.days.paid_leave} paid leave · {slip.days.holidays + slip.days.weekly_offs} days off ·{' '}
              <span className={slip.days.unpaid > 0 ? 'due' : ''}>{slip.days.unpaid} unpaid</span>
              {slip.days.overtime_minutes > 0 ? ` · ${hours(slip.days.overtime_minutes)} overtime` : ''}
            </p>
          )}
          {slip.days?.notes.map((n) => (
            <p key={n} className="form-error">
              {n}
            </p>
          ))}
          {block('earning', 'Earnings')}
          {block('deduction', 'Deductions')}
          <dl className="totals">
            <dt>Gross</dt>
            <dd>{inr(slip.gross)}</dd>
            <dt>Deductions</dt>
            <dd>{inr(slip.deductions)}</dd>
            <dt className="strong">Net pay</dt>
            <dd className={`strong ${Number(slip.net) < 0 ? 'due' : ''}`}>{inr(slip.net)}</dd>
          </dl>
          {block('employer', 'Paid by the hospital on top, not taken from the pay')}
          {slip.payment_status === 'paid' && (
            <p className="muted small gap-top">
              Paid on {longDate(slip.paid_on!)} by {payModeLabel(slip.payment_mode).toLowerCase()}
              {slip.payment_reference ? ` (${slip.payment_reference})` : ''}.
              {Number(slip.already_paid) > 0 && ` ${inr(slip.already_paid)} of it had already been paid under a reversed payroll for this month, so ${inr(slip.paid_amount)} was paid now.`}
            </p>
          )}
          {slip.payment_status !== 'paid' && Number(slip.already_paid) > 0 && (
            <p className="muted small gap-top">
              {inr(slip.already_paid)} was already paid to this person for {slip.period_name} under a reversed payroll. Recording this payslip as paid posts only the rest
              {Number(slip.already_paid) > Number(slip.net) ? `; it is ${inr(Number(slip.already_paid) - Number(slip.net))} more than this payslip, which could be taken back as an advance` : ''}.
            </p>
          )}
          {slip.final && !slip.reversed && (
            <Link className="btn gap-top" to={`/workforce/payslips/${slip.id}/print`} target="_blank">
              Print the payslip
            </Link>
          )}

          {draft && (
            <form
              className="day-section"
              noValidate
              onSubmit={(e) => {
                e.preventDefault()
                void change(() => api.post<RunDetail>(`/workforce/payroll/runs/${run.id}/payslips/${id}/lines`, form), () => setForm({ kind: 'earning', name: '', amount: '', note: '' }))
              }}
            >
              <h3>Add a one-off line</h3>
              <p className="muted small">An incentive, a correction for last month, a fine. It is for this month only and is printed on the payslip with its reason.</p>
              <div className="grid">
                <SelectField label="It is" value={form.kind} onChange={(v) => setForm((f) => ({ ...f, kind: v }))} options={[{ value: 'earning', label: 'An addition' }, { value: 'deduction', label: 'A deduction' }]} />
                <TextField label="Amount (₹)" required type="number" min="0" value={form.amount} onChange={(v) => setForm((f) => ({ ...f, amount: v }))} error={errors.amount} />
              </div>
              <TextField label="Called" required value={form.name} onChange={(v) => setForm((f) => ({ ...f, name: v }))} placeholder="Night duty incentive" error={errors.name} />
              <TextField label="What it is for" required value={form.note} onChange={(v) => setForm((f) => ({ ...f, note: v }))} error={errors.note ?? errors.status} />
              <div className="panel-foot row between">
                {leaving ? (
                  <span className="row wrap grow">
                    <input className="grow" value={why} onChange={(e) => setWhy(e.target.value)} placeholder="Why they are left out" aria-label="Reason" />
                    <button type="button" className="btn small-btn" disabled={busy || !why.trim()} onClick={() => void change(() => api.post<RunDetail>(`/workforce/payroll/runs/${run.id}/hold-back`, { employee_id: slip.employee?.id, leave_out: true, reason: why }), onClose)}>
                      Leave out
                    </button>
                  </span>
                ) : (
                  <button type="button" className="link" onClick={() => setLeaving(true)}>
                    Leave this person out of the run
                  </button>
                )}
                <button className="btn primary" disabled={busy || !form.name.trim() || !form.amount || !form.note.trim()}>
                  Add the line
                </button>
              </div>
            </form>
          )}
        </>
      )}
    </aside>
  )
}
