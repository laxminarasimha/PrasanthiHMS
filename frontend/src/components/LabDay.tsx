import { useEffect, useRef, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../api'
import { PAYMENT_MODES, STAGE_CLASS, STAGE_LABEL, clock, longDate, modeLabel, rupees, settlement, today } from '../format'
import { STEP_CLASS, STEP_LABEL, stepOf } from '../pages/labSteps'
import type { LabOrder, LabSettings } from '../types'
import { SelectField, TextField } from './Field'
import { PatientLink } from './PatientLink'
import { useBillingPreferences } from './useBillingPreferences'
import { EarlierDate } from './EarlierDate'
import { earlier } from '../earlierDay'
import { testLabel } from '../labName'
import { IpdLink } from './RecordLinks'

type Props = {
  /** The patient's lab orders of one day, as the work list has them. The list refreshes them after every change. */
  orders: LabOrder[]
  settings: LabSettings | null
  /** Whether this person works in the lab, and so may collect samples and enter results. */
  canRun: boolean
  onClose: () => void
  /** Called after a sample is collected or money is taken, so the list behind can refresh. */
  onChange: () => void
  /** Opens one order's results: to enter, approve, or print it by itself. */
  onOpenResults: (orderId: number) => void
  onOpenBill: (billId: number) => void
}

/**
 * Everything the lab is doing for one patient on one day, in one window: each order with its tests and how far
 * each has got, what is owed, one payment for all of it, and one printout of every report that is ready.
 */
export function LabDay({ orders, settings, canRun, onClose, onChange, onOpenResults, onOpenBill }: Props) {
  const prefs = useBillingPreferences()
  const patient = orders[0].patient
  const approval = Boolean(settings?.approval_required)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [letterhead, setLetterhead] = useState(false)
  const [tabularChoice, setTabularChoice] = useState<boolean | null>(null)
  const tabular = tabularChoice ?? Boolean(settings?.tabular_print)
  const [typed, setTyped] = useState<string | null>(null)
  const [chosenMode, setMode] = useState<string | null>(null)
  const mode = chosenMode ?? prefs.default_payment_mode
  const [reference, setReference] = useState('')
  const [paidOn, setPaidOn] = useState(today)
  const box = useRef<HTMLDivElement>(null)

  useEffect(() => {
    box.current?.focus()
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const tests = orders.flatMap((o) => o.items)
  const isReady = (i: LabOrder['items'][number]) => Boolean(approval ? i.approved_at : i.reported_at)
  const ready = tests.filter(isReady).length
  // Orders with something to print, and whether an unpaid bill among them holds the printing back.
  const printable = orders.filter((o) => o.items.some(isReady))
  const held = Boolean(settings && !settings.allow_print_without_payment && printable.some((o) => o.bill && o.bill.due > 0))
  const printQuery = new URLSearchParams({
    ...(printable.length > 1 ? { with: printable.slice(1).map((o) => o.id).join(',') } : {}),
    ...(letterhead ? { letterhead: '1' } : {}),
    ...(tabular ? { tabular: '1' } : {}),
  }).toString()
  const printUrl = printable.length > 0 ? `/lab/orders/${printable[0].id}/print${printQuery ? `?${printQuery}` : ''}` : ''

  const owing = orders.filter((o) => o.bill && o.bill.due > 0)
  const billed = orders.reduce((sum, o) => sum + (o.bill?.total ?? 0), 0)
  const due = Math.round(owing.reduce((sum, o) => sum + (o.bill?.due ?? 0), 0) * 100) / 100
  const amount = typed ?? (due > 0 ? String(due) : '')

  const run = async (work: () => Promise<unknown>, done: string) => {
    setBusy(true)
    setError('')
    setNotice('')
    try {
      await work()
      setNotice(done)
      onChange()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'That was not saved. Please try again.')
    } finally {
      setBusy(false)
    }
  }
  const collectSample = (order: LabOrder) => run(() => api.put(`/lab/orders/${order.id}/collect`, { all: true }), `Sample collected for ${order.order_no}.`)
  const pay = (e: FormEvent) => {
    e.preventDefault()
    void run(
      () => api.post('/lab/collect', { patient_id: patient.id, bill_ids: owing.map((o) => o.bill!.id), amount, mode, reference: reference || null, paid_on: earlier(paidOn) }),
      `${rupees(Number(amount))} received by ${modeLabel(mode)}${owing.length > 1 ? `, shared over ${owing.length} bills` : ''}.`,
    ).then(() => {
      setTyped(null)
      setReference('')
      setPaidOn(today())
    })
  }

  return (
    <div className="modal-back" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className="modal" role="dialog" aria-modal="true" aria-label={`Lab tests for ${patient.name}`} tabIndex={-1} ref={box}>
        <div className="panel-head">
          <div>
            <h2>
              Lab tests for <PatientLink id={patient.id}>{patient.name}</PatientLink>
            </h2>
            <span className="muted">
              {patient.uhid} · {patient.age_label} / {patient.gender.charAt(0).toUpperCase()} · {longDate(orders[0].ordered_at)} · {orders.length} orders
            </span>
          </div>
          <button type="button" className="btn ghost" onClick={onClose}>
            Close
          </button>
        </div>

        <div className="tiles small">
          <div className="tile">
            <span>Tests</span>
            <strong>{tests.length}</strong>
          </div>
          <div className="tile">
            <span>Ready</span>
            <strong className={ready === tests.length ? 'settled' : ''}>{ready}</strong>
          </div>
          <div className="tile">
            <span>Billed</span>
            <strong>{rupees(billed)}</strong>
          </div>
          <div className="tile">
            <span>Still due</span>
            <strong className={due > 0 ? 'due' : ''}>{rupees(due)}</strong>
          </div>
        </div>

        <div className="print-bar">
          <label className="check">
            <input type="checkbox" checked={letterhead} onChange={(e) => setLetterhead(e.target.checked)} />
            Print on letterhead
          </label>
          <label className="check">
            <input type="checkbox" checked={tabular} onChange={(e) => setTabularChoice(e.target.checked)} />
            Compact table
          </label>
          <span className="muted small grow">
            {printable.length === 0
              ? `Nothing to print until a result is ${approval ? 'approved' : 'reported'}.`
              : held
                ? 'The bills must be paid before the reports can be printed.'
                : `${ready} of ${tests.length} tests are ready and come out as one report.`}
          </span>
          {printable.length > 0 && !held ? (
            <a className="btn primary" href={printUrl} target="_blank" rel="noreferrer">
              Print all reports
            </a>
          ) : (
            <button type="button" className="btn primary" disabled>
              Print all reports
            </button>
          )}
        </div>

        {orders.map((o) => {
          const step = stepOf(o, approval)
          return (
            <section key={o.id} className="day-bill">
              <header>
                <div>
                  <strong className="mono">{o.order_no}</strong>
                  <small className="muted block">
                    {clock(o.ordered_at)}
                    {o.referred_by ? ` · referred by ${o.referred_by}` : ''}
                  </small>
                  {o.inpatient && (
                    <small className="block">
                      Inpatient <IpdLink id={o.inpatient.admission_id}>{o.inpatient.ipd_no}</IpdLink>
                      {o.inpatient.bed ? ` · ${o.inpatient.bed}` : ''} · bill on the IPD bill
                    </small>
                  )}
                </div>
                <span className={`pill ${STEP_CLASS[step]}`}>{STEP_LABEL[step]}</span>
                <span className="row">
                  {step === 'collect' && canRun && (
                    <button type="button" className="link" disabled={busy} onClick={() => collectSample(o)} title="Marks the sample taken for every test on this order">
                      Mark collected
                    </button>
                  )}
                  <button type="button" className="link" onClick={() => onOpenResults(o.id)}>
                    {!canRun ? 'Results' : step === 'report' ? 'Enter results' : step === 'approve' ? 'Approve' : step === 'ready' ? 'View / print' : 'Open'}
                  </button>
                </span>
              </header>
              <table className="compact">
                <tbody>
                  {o.items.map((i) => (
                    <tr key={i.id} className="static">
                      <td className="wrap">
                        <i className={`stage-dot ${STAGE_CLASS[i.stage]}`} aria-hidden="true" /> {testLabel(i)}
                        {i.outside_lab && !i.reported_at && <small className="outside-lab"> · {i.sent_at ? 'at' : 'for'} {i.outside_lab}</small>}
                      </td>
                      <td className="num muted">{STAGE_LABEL[i.stage]}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {o.bill && (
                <p className="day-bill-foot">
                  <button type="button" className="link" onClick={() => onOpenBill(o.bill!.id)} title="Open this bill by itself: discount, refund or print">
                    Bill {o.bill.bill_no}
                  </button>
                  <span>
                    Total <b>{rupees(o.bill.total)}</b>
                  </span>
                  <span className={o.bill.due > 0 ? 'due' : 'settled'}>{settlement(o.bill.due, o.bill.moved_to_ipd, o.bill.total)}</span>
                </p>
              )}
            </section>
          )
        })}

        {notice && <p className="notice">{notice}</p>}
        {error && <div className="form-error">{error}</div>}

        {owing.length > 0 && (
          <form onSubmit={pay} className="day-collect">
            <h3>
              Collect {rupees(due)} for {owing.length === 1 ? 'the unpaid lab bill' : `all ${owing.length} unpaid lab bills`}
            </h3>
            <div className="grid three">
              <TextField label="Amount (₹)" required type="number" value={amount} onChange={setTyped} />
              <SelectField label="Mode" required value={mode} onChange={setMode} options={PAYMENT_MODES} />
              <TextField label="Reference" value={reference} onChange={setReference} placeholder="optional" />
            </div>
            <EarlierDate what="money" value={paidOn} onChange={setPaidOn} />
            <div className="panel-foot">
              <span className="muted small grow">A part payment is put against the bills in the order shown. Each bill keeps its own receipt.</span>
              <button className="btn primary" disabled={busy || !(Number(amount) > 0)}>
                {busy ? 'Saving…' : 'Collect payment'}
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  )
}
