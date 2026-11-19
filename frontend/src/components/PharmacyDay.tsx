import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors } from '../api'
import { PAYMENT_MODES, clock, longDate, modeLabel, rupees, settlement } from '../format'
import { SelectField, TextField } from './Field'
import { PatientLink } from './PatientLink'
import { useBillingPreferences } from './useBillingPreferences'

type Sale = {
  id: number
  bill_no: string
  sold_at: string
  seller: string | null
  total: number
  returned: number
  paid: number
  ipd_transfer: number
  due: number
  modes: string[]
  items: { id: number; name: string; batch_no: string | null; quantity: number; returned: number; unit_price: number; amount: number }[]
}
type Bills = {
  scope: 'day' | 'stay'
  date: string
  customer: { name: string; phone: string | null; patient: { id: number; uhid: string; title: string | null; name: string } | null }
  admission: { id: number; ipd_no: string; status: string; admitted_at: string; discharged_at: string | null } | null
  sales: Sale[]
  medicines: { name: string; quantity: number; returned: number; amount: number }[]
  billed: number
  paid: number
  on_ipd: number
  due: number
}

type Props = {
  /** Any one bill of the customer: the rest are found from it. */
  saleId: number
  /** Whether this person may take money in the pharmacy. */
  canCollect: boolean
  onClose: () => void
  /** Called after money is taken, so the list behind can refresh. */
  onChange: () => void
  /** Opens one bill by itself: to return medicines, add to it, or correct it. */
  onOpenSale: (saleId: number) => void
}

/**
 * Everything the pharmacy has billed to one customer, in one window: the bills of the day, or for an inpatient
 * the whole stay. The medicines of all the bills are added up into one list, and one payment settles what is owed.
 */
export function PharmacyDay({ saleId, canCollect, onClose, onChange, onOpenSale }: Props) {
  const prefs = useBillingPreferences()
  const [scope, setScope] = useState<'day' | 'stay'>('day')
  const [data, setData] = useState<Bills | null>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const [typed, setTyped] = useState<string | null>(null)
  const [chosenMode, setMode] = useState<string | null>(null)
  const mode = chosenMode ?? prefs.default_payment_mode
  const [reference, setReference] = useState('')
  const box = useRef<HTMLDivElement>(null)

  const load = useCallback(
    () =>
      api
        .get<Bills>('/pharmacy/customer-bills', { params: { sale_id: saleId, scope } })
        .then((res) => setData(res.data))
        .catch((err) => setError(Object.values(fieldErrors(err))[0] ?? 'Could not load the bills. Close this and try again.')),
    [saleId, scope],
  )
  useEffect(() => {
    void load()
  }, [load])

  useEffect(() => {
    box.current?.focus()
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const owing = data?.sales.filter((s) => s.due > 0) ?? []
  const amount = typed ?? (data && data.due > 0 ? String(data.due) : '')
  const collect = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError('')
    setNotice('')
    try {
      await api.post('/pharmacy/collect', { sale_ids: owing.map((s) => s.id), amount, mode, reference: reference || null })
      setNotice(`${rupees(Number(amount))} received by ${modeLabel(mode)}${owing.length > 1 ? `, shared over ${owing.length} bills` : ''}.`)
      setTyped(null)
      setReference('')
      await load()
      onChange()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'The payment was not saved. Check the bills and try again.')
    } finally {
      setBusy(false)
    }
  }

  const name = data?.customer.name ?? ''
  const stay = data?.scope === 'stay'
  return (
    <div className="modal-back" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className="modal" role="dialog" aria-modal="true" aria-label={`Pharmacy bills for ${name}`} tabIndex={-1} ref={box}>
        <div className="panel-head">
          <div>
            <h2>
              Pharmacy bills for{' '}
              <PatientLink id={data?.customer.patient?.id} bills>
                {name || '…'}
              </PatientLink>
            </h2>
            {data && (
              <span className="muted">
                {data.customer.patient ? data.customer.patient.uhid : (data.customer.phone ?? 'Walk-in customer')} ·{' '}
                {stay && data.admission ? `Stay ${data.admission.ipd_no}, since ${longDate(data.admission.admitted_at)}` : longDate(data.date)} · {data.sales.length} {data.sales.length === 1 ? 'bill' : 'bills'}
              </span>
            )}
          </div>
          <div className="row">
            {data?.customer.patient && canCollect && (
              <Link className="btn primary" to={`/pharmacy?patient=${data.customer.patient.id}`} onClick={onClose}>
                + New bill
              </Link>
            )}
            <button type="button" className="btn ghost" onClick={onClose}>
              Close
            </button>
          </div>
        </div>

        {!data && !error && <p className="loading">Loading the bills…</p>}
        {data && (
          <>
            {data.admission && (
              <div className="tabs flush" role="group" aria-label="Which bills to show">
                <button type="button" className={!stay ? 'active' : ''} onClick={() => setScope('day')}>
                  {longDate(data.date)}
                </button>
                <button type="button" className={stay ? 'active' : ''} onClick={() => setScope('stay')}>
                  Whole stay · {data.admission.ipd_no}
                </button>
              </div>
            )}

            <div className="tiles small">
              <div className="tile">
                <span>Billed</span>
                <strong>{rupees(data.billed)}</strong>
                <small className="muted">net of returns</small>
              </div>
              <div className="tile">
                <span>Paid</span>
                <strong className="settled">{rupees(data.paid)}</strong>
              </div>
              {data.on_ipd > 0 && (
                <div className="tile">
                  <span>On the IPD bill</span>
                  <strong>{rupees(data.on_ipd)}</strong>
                </div>
              )}
              <div className="tile">
                <span>Still due</span>
                <strong className={data.due > 0 ? 'due' : ''}>{rupees(data.due)}</strong>
              </div>
            </div>

            {data.sales.length > 1 && (
              <section className="day-bill">
                <header>
                  <div>
                    <strong>All medicines {stay ? 'of the stay' : 'of the day'}</strong>
                    <small className="muted block">Added up over the {data.sales.length} bills, less anything returned</small>
                  </div>
                </header>
                <table className="compact">
                  <tbody>
                    {data.medicines.map((m) => (
                      <tr key={m.name} className="static">
                        <td className="wrap">
                          {m.name}
                          {m.returned > 0 && <small className="muted"> · {m.returned} returned</small>}
                        </td>
                        <td className="num muted">{m.quantity}</td>
                        <td className="num">{rupees(m.amount)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </section>
            )}

            {data.sales.map((s) => (
              <section key={s.id} className="day-bill">
                <header>
                  <div>
                    <strong className="mono">{s.bill_no}</strong>
                    <small className="muted block">
                      {stay ? `${longDate(s.sold_at)} ` : ''}
                      {clock(s.sold_at)}
                      {s.seller ? ` · ${s.seller}` : ''}
                    </small>
                  </div>
                  <span className={`pill ${s.due > 0 ? 'owed' : 'ok'}`}>{settlement(s.due, s.ipd_transfer, s.total)}</span>
                  <span className="row">
                    <button type="button" className="link" onClick={() => onOpenSale(s.id)} title="Open this bill by itself: return medicines, add to it, or correct it">
                      Open
                    </button>
                    <a className="link" href={`/pharmacy/bills/${s.id}/print`} target="_blank" rel="noreferrer">
                      Print
                    </a>
                  </span>
                </header>
                <table className="compact">
                  <tbody>
                    {s.items.map((i) => (
                      <tr key={i.id} className="static">
                        <td className="wrap">
                          {i.name}
                          {i.returned > 0 && <small className="muted"> · {i.returned} returned</small>}
                        </td>
                        <td className="num muted">
                          {i.quantity} × {rupees(i.unit_price)}
                        </td>
                        <td className="num">{rupees(i.amount)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                <p className="day-bill-foot">
                  <span>
                    Total <b>{rupees(s.total)}</b>
                  </span>
                  {s.returned > 0 && <span>Returned {rupees(s.returned)}</span>}
                  <span>
                    Paid {rupees(s.paid)}
                    {s.modes.length > 0 ? ` (${s.modes.map(modeLabel).join(', ')})` : ''}
                  </span>
                  {s.ipd_transfer > 0 && <span>On IPD bill {rupees(s.ipd_transfer)}</span>}
                  {s.due > 0 && <span className="due">Due {rupees(s.due)}</span>}
                </p>
              </section>
            ))}
            {data.sales.length === 0 && <p className="muted">There is no pharmacy bill here; it may have been deleted.</p>}

            {notice && <p className="notice">{notice}</p>}
            {error && <div className="form-error">{error}</div>}

            {owing.length > 0 && canCollect && (
              <form onSubmit={collect} className="day-collect">
                <h3>
                  Collect {rupees(data.due)} for {owing.length === 1 ? 'the unpaid bill' : `all ${owing.length} unpaid bills`}
                </h3>
                <div className="grid three">
                  <TextField label="Amount (₹)" required type="number" value={amount} onChange={setTyped} />
                  <SelectField label="Mode" required value={mode} onChange={setMode} options={PAYMENT_MODES} />
                  <TextField label="Reference" value={reference} onChange={setReference} placeholder="optional" />
                </div>
                <div className="panel-foot">
                  <span className="muted small grow">A part payment is put against the bills in the order shown. Each bill keeps its own receipt.</span>
                  <button className="btn primary" disabled={busy || !(Number(amount) > 0)}>
                    {busy ? 'Saving…' : 'Collect payment'}
                  </button>
                </div>
              </form>
            )}
          </>
        )}
        {!data && error && <div className="form-error">{error}</div>}
      </div>
    </div>
  )
}
