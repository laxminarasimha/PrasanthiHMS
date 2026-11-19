import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../api'
import { PaperBar, PaperFooter, PaperHeader } from '../components/PrintPaper'
import { longDate } from '../format'
import { useAutoPrint, usePrintPaper } from '../printPaper'
import { inr, payModeLabel, type Slip } from './pay'
import { hours } from './shared'

/**
 * A payslip on the hospital's paper: the heading the hospital chose for its prints, the person, the month's days,
 * every earning and deduction with how it was arrived at, and what was paid. `own` reads a person's own payslip
 * from their own record; otherwise it is opened by someone who may see salaries, and that is written down.
 */
export function PayslipPrint({ own }: { own?: boolean }) {
  const { id } = useParams()
  const [slip, setSlip] = useState<Slip | null>(null)
  const [error, setError] = useState('')
  const paper = usePrintPaper()

  useEffect(() => {
    api
      .get<Slip>(own ? `/workforce/me/payslips/${id}` : `/workforce/payroll/payslips/${id}`)
      .then((res) => setSlip(res.data))
      .catch(() => setError('This payslip could not be opened.'))
  }, [id, own])
  // Only a payslip that stands is sent to the printer by itself: a draft or a reversed one is shown, marked as such.
  useAutoPrint(Boolean(slip?.final && !slip.reversed) && paper.ready)

  if (error) return <p className="form-error">{error}</p>
  if (!slip) return <p className="loading">Loading the payslip…</p>
  const e = slip.employee
  const d = slip.days
  const of = (kind: string) => slip.lines.filter((l) => l.kind === kind)
  const table = (kind: string, heading: string) =>
    of(kind).length > 0 && (
      <table>
        <thead>
          <tr>
            <th>{heading}</th>
            <th className="num">Amount</th>
          </tr>
        </thead>
        <tbody>
          {of(kind).map((l) => (
            <tr key={l.id}>
              <td>
                {l.name}
                <small className="muted block">{l.explanation}</small>
              </td>
              <td className="num">{inr(l.amount)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    )

  return (
    <div className={`receipt payslip ${slip.reversed ? 'cancelled' : ''}`}>
      <PaperBar paper={paper} />
      <PaperHeader paper={paper}>
        <h2>Payslip for {slip.period_name}</h2>
      </PaperHeader>

      {slip.reversed && <p className="cancelled-stamp">REVERSED: this payslip no longer stands.</p>}
      {!slip.final && <p className="cancelled-stamp">DRAFT: not yet a payslip. The payroll has not been finalised.</p>}

      <div className="receipt-meta">
        <div>
          <strong>{e?.name}</strong>
          <br />
          Employee no: {e?.employee_no}
          <br />
          {[e?.designation, e?.department?.name].filter(Boolean).join(' · ')}
        </div>
        <div className="right">
          Payslip no: <strong>{slip.slip_no ?? '—'}</strong>
          <br />
          {d && (
            <>
              {d.employed < d.in_month ? `Employed ${d.employed} of ${d.in_month} days` : `${d.in_month} days in the month`}
              <br />
              {d.present} worked · {d.paid_leave} paid leave · {d.holidays + d.weekly_offs} off · {d.unpaid} unpaid
              {d.overtime_minutes > 0 ? ` · ${hours(d.overtime_minutes)} overtime` : ''}
            </>
          )}
        </div>
      </div>

      {table('earning', 'Earnings')}
      {table('deduction', 'Deductions')}

      <dl className="totals">
        <dt>Gross earnings</dt>
        <dd>{inr(slip.gross)}</dd>
        <dt>Total deductions</dt>
        <dd>{inr(slip.deductions)}</dd>
        <dt className="strong">Net pay</dt>
        <dd className="strong">{inr(slip.net)}</dd>
      </dl>

      {table('employer', 'Paid by the hospital on top of the salary, not taken from it')}

      {slip.payment_status === 'paid' && (
        <p className="receipt-payments">
          Paid {inr(slip.net)} on {longDate(slip.paid_on!)} by {payModeLabel(slip.payment_mode).toLowerCase()}
          {slip.payment_reference ? ` (${slip.payment_reference})` : ''}
        </p>
      )}

      <PaperFooter paper={paper} />
    </div>
  )
}

/** A person's own payslip, from their own record. */
export function MyPayslipPrint() {
  return <PayslipPrint own />
}
