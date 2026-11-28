import { Fragment, useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../../api'
import { HospitalGstin, PaperBar, PaperFooter, PaperHeader } from '../../components/PrintPaper'
import { useAutoPrint, usePrintPaper } from '../../printPaper'
import { ADMISSION_STATUS, clock, longDate, modeLabel, rupees, fullName } from '../../format'
import type { Admission } from '../../types'

/** Inpatient bill with the discharge summary; opens the print dialog once loaded. */
export function IpdPrint() {
  const { id, billId } = useParams()
  const [a, setA] = useState<Admission | null>(null)
  const paper = usePrintPaper()

  useEffect(() => {
    api.get<Admission>(`/ipd/admissions/${id}`).then((res) => setA(res.data))
  }, [id])
  useAutoPrint(Boolean(a) && paper.ready)

  if (!a) return <p className="loading">Loading bill…</p>

  // One numbered bill lists only its own charges; the whole-case bill lists everything.
  const bill = billId ? a.bills?.find((b) => String(b.id) === billId) : undefined
  if (billId && !bill) return <p className="form-error">This bill was cancelled or does not belong to this case.</p>
  const charges = bill ? a.charges?.filter((c) => c.admission_bill_id === bill.id) : a.charges

  return (
    <div className="receipt prescription">
      <PaperBar paper={paper} />
      <PaperHeader paper={paper}>
        <h2>{bill ? 'Inpatient bill' : `Inpatient ${a.status === 'admitted' ? 'interim bill' : 'final bill'}`}</h2>
      </PaperHeader>

      <div className="receipt-meta">
        <div>
          <strong>{fullName(a.patient)}</strong>
          <br />
          {a.patient.age_label} / {a.patient.gender.charAt(0).toUpperCase()} · Patient ID: {a.patient.uhid}
          <br />
          Mobile: {a.patient.mobile}
          <br />
          Doctor: {a.doctor.name}
          {a.gst_no && (
            <>
              <br />
              Patient's GSTIN: {a.gst_no}
            </>
          )}
          {a.payer && (
            <>
              <br />
              Bill to: {a.payer.name}
            </>
          )}
        </div>
        <div className="right">
          {bill && (
            <>
              Bill no: <strong>{bill.bill_no}</strong>
              <br />
              Bill date: {longDate(bill.billed_on)}
              <br />
            </>
          )}
          IPD no: <strong>{a.ipd_no}</strong>
          <br />
          Admitted: {longDate(a.admitted_at)} {clock(a.admitted_at)}
          <br />
          {a.discharged_at ? `${ADMISSION_STATUS[a.status]}: ${longDate(a.discharged_at)} ${clock(a.discharged_at)}` : 'Still admitted'}
          {a.bed && (
            <>
              <br />
              {a.bed.ward}, bed {a.bed.bed_no}
            </>
          )}
          <HospitalGstin paper={paper} label="Hospital GSTIN" />
        </div>
      </div>

      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Charge</th>
            <th className="num">Qty</th>
            <th className="num">Rate</th>
            <th className="num">Amount</th>
          </tr>
        </thead>
        <tbody>
          {charges?.map((c) => (
            <Fragment key={c.id}>
              <tr>
                <td>{longDate(c.charged_on)}</td>
                <td>
                  {c.description}
                  {c.tests && c.tests.length > 0 && <small className="block">{c.tests.join(', ')}</small>}
                </td>
                <td className="num">{c.quantity}</td>
                <td className="num">
                  {rupees(c.unit_price)}
                  {c.discount > 0 && <small className="block">less {rupees(c.discount)}</small>}
                </td>
                <td className="num">{rupees(c.amount)}</td>
              </tr>
              {/* What the pharmacy bill was for: each medicine, for the patient to see what was given. The amounts are already in the line above. */}
              {paper.ipdMedicines &&
                c.medicines?.map((m, i) => (
                  <tr key={i} className="bill-medicine">
                    <td />
                    <td>
                      {m.name}
                      {m.returned > 0 ? ` (${m.returned} returned)` : ''}
                    </td>
                    <td className="num">{m.quantity}</td>
                    <td className="num">{rupees(m.rate)}</td>
                    <td className="num">({rupees(m.amount)})</td>
                  </tr>
                ))}
            </Fragment>
          ))}
        </tbody>
      </table>

      {bill && (
        <>
          <dl className="totals">
            <dt className="strong">This bill</dt>
            <dd className="strong">{rupees(bill.total)}</dd>
          </dl>
          {bill.notes && <p className="receipt-payments">{bill.notes}</p>}
          <p className="receipt-payments">
            <strong>Whole stay so far</strong>
            {(a.bills?.length ?? 0) > 1 && `: bills ${a.bills!.map((b) => `${b.bill_no} ${rupees(b.total)}`).join(', ')}`}
          </p>
        </>
      )}

      <dl className="totals">
        <dt>Total charges</dt>
        <dd>{rupees(a.total)}</dd>
        {a.discount > 0 && (
          <>
            <dt>Discount</dt>
            <dd>−{rupees(a.discount)}</dd>
          </>
        )}
        <dt className="strong">Net bill</dt>
        <dd className="strong">{rupees(a.net)}</dd>
        <dt>Received</dt>
        <dd>{rupees(a.paid)}</dd>
        <dt className="strong">{a.due < 0 ? 'Advance to refund' : 'Balance due'}</dt>
        <dd className="strong">{rupees(Math.abs(a.due))}</dd>
      </dl>

      {(a.payments?.length ?? 0) > 0 && (
        <p className="receipt-payments">
          {a.payments!.map((p) => `${p.type === 'refund' ? 'Refund' : p.type === 'advance' ? 'Advance' : 'Paid'} ${rupees(p.amount)} by ${modeLabel(p.mode)}`).join(' · ')}
        </p>
      )}

      {a.bill_approved_at && (
        <p className="receipt-payments">
          Bill approved by {a.bill_approver?.name ?? 'the doctor'} on {longDate(a.bill_approved_at)} {clock(a.bill_approved_at)}
        </p>
      )}

      <div className="signature">
        <strong>{a.doctor.signature_text || a.doctor.name}</strong>
      </div>
      <PaperFooter paper={paper} />
    </div>
  )
}
