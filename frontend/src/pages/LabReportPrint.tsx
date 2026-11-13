import { Fragment, useEffect, useState } from 'react'
import { useParams, useSearchParams } from 'react-router-dom'
import { api } from '../api'
import { ClinicFooter, ClinicHeader } from '../components/ClinicHeader'
import { useSignature } from '../components/useSignature'
import { clock, longDate, fullName } from '../format'
import type { LabOrder, LabSettings } from '../types'

const stamp = (at: string) => `${longDate(at)} ${clock(at)}`

/**
 * The lab report as given to the patient. `?letterhead=1` leaves the top and bottom of each page
 * blank for pre-printed paper; `?items=1,2` prints only those tests; `?tabular=1` puts every test in one table.
 */
export function LabReportPrint() {
  const { id } = useParams()
  const [params] = useSearchParams()
  const [order, setOrder] = useState<LabOrder | null>(null)
  const [settings, setSettings] = useState<LabSettings | null>(null)

  useEffect(() => {
    Promise.all([api.get<LabOrder>(`/lab/orders/${id}`), api.get<LabSettings>('/lab/settings')]).then(([o, s]) => {
      setOrder(o.data)
      setSettings(s.data)
    })
  }, [id])

  const letterhead = params.get('letterhead') === '1'
  const tabular = params.get('tabular') === '1'
  const only = params.get('items')?.split(',').map(Number)
  const ready = (order?.items ?? []).filter((item) => (settings?.approval_required ? item.approved_at : item.reported_at))
  const printed = ready.filter((item) => !only || only.includes(item.id))
  const unpaid = Boolean(order && settings && !settings.allow_print_without_payment && order.bill && order.bill.due > 0)
  const canPrint = printed.length > 0 && !unpaid
  const lastPrinted = printed[printed.length - 1]
  const signerSignature = useSignature((lastPrinted?.approver ?? lastPrinted?.reporter)?.id)

  useEffect(() => {
    if (!canPrint) return
    const timer = setTimeout(() => window.print(), 800)
    return () => clearTimeout(timer)
  }, [canPrint])

  if (!order || !settings) return <p className="loading">Loading report…</p>
  if (unpaid) return <p className="loading">The bill for this order has to be paid before the report can be printed.</p>
  if (printed.length === 0) {
    return <p className="loading">{settings.approval_required ? 'No test in this order has been approved yet.' : 'No test in this order has been marked as reported yet.'}</p>
  }

  const last = printed[printed.length - 1]
  const signer = last.approver ?? last.reporter
  const pending = order.items.length - ready.length

  const patientBlock = (
    <div className="receipt-meta">
      <div>
        <strong>{fullName(order.patient)}</strong>
        <br />
        {order.patient.age_label} / {order.patient.gender.charAt(0).toUpperCase()} · Patient ID: {order.patient.uhid}
        {order.referred_by && (
          <>
            <br />
            Referred by: {order.referred_by}
          </>
        )}
      </div>
      <div className="right">
        Order no: <strong>{order.order_no}</strong>
        <br />
        Booked: {stamp(order.ordered_at)}
      </div>
    </div>
  )

  return (
    <div className="receipt prescription lab-report">
      {letterhead && <style>{`@media print { @page { margin: ${settings.letterhead_top_mm}mm 12mm ${settings.letterhead_bottom_mm}mm; } }`}</style>}
      {letterhead ? (
        <div className="letterhead-gap no-print" style={{ height: `${settings.letterhead_top_mm}mm` }}>
          Space left for the letterhead ({settings.letterhead_top_mm} mm)
        </div>
      ) : (
        <ClinicHeader name={settings.lab_name} address={settings.lab_address}>
          <h2>Laboratory report</h2>
        </ClinicHeader>
      )}

      {(tabular || !settings.each_test_on_new_page) && patientBlock}

      {tabular && (
        <table className="lab-tabular">
          <thead>
            <tr>
              <th>Test / parameter</th>
              <th>Result</th>
              <th>Unit</th>
              <th>Reference range</th>
            </tr>
          </thead>
          <tbody>
            {printed.map((item) => (
              <Fragment key={item.id}>
                <tr className="lab-test-row">
                  <td colSpan={4}>
                    {item.test_name}
                    {item.sample_type ? ` (${item.sample_type})` : ''}
                    <small>
                      {settings.print_sample_date && item.collected_at && <> Sample: {stamp(item.collected_at)} ·</>}
                      {item.reported_at && <> Reported: {stamp(item.reported_at)}</>}
                      {settings.print_approved_date && item.approved_at && <> · Approved: {stamp(item.approved_at)}</>}
                    </small>
                  </td>
                </tr>
                {item.kind === 'text' ? (
                  <tr>
                    <td colSpan={4} className="report-text">
                      {item.report_text}
                    </td>
                  </tr>
                ) : (
                  (item.results ?? []).map((r) =>
                    r.is_heading ? (
                      <tr key={r.id} className="lab-heading">
                        <td colSpan={4}>{r.parameter}</td>
                      </tr>
                    ) : (
                      <tr key={r.id}>
                        <td>{r.parameter}</td>
                        <td>{r.flag ? <strong>{r.value} {r.flag === 'high' ? '(H)' : '(L)'}</strong> : r.value}</td>
                        <td>{r.unit}</td>
                        <td>{r.reference_range}</td>
                      </tr>
                    ),
                  )
                )}
                {item.remarks && (
                  <tr>
                    <td colSpan={4} className="report-text">
                      Comment: {item.remarks}
                    </td>
                  </tr>
                )}
              </Fragment>
            ))}
          </tbody>
        </table>
      )}

      {!tabular && printed.map((item, index) => (
        <section key={item.id} className={settings.each_test_on_new_page && index > 0 ? 'new-page' : ''}>
          {settings.each_test_on_new_page && patientBlock}
          <h3>
            {item.test_name}
            {item.sample_type ? ` (${item.sample_type})` : ''}
          </h3>
          <p className="lab-dates">
            {settings.print_sample_date && item.collected_at && <span>Sample collected: {stamp(item.collected_at)}</span>}
            {item.reported_at && <span>Reported: {stamp(item.reported_at)}</span>}
            {settings.print_approved_date && item.approved_at && <span>Approved: {stamp(item.approved_at)}</span>}
          </p>
          {item.kind === 'text' ? (
            <p className="report-text">{item.report_text}</p>
          ) : (
            <table>
              <thead>
                <tr>
                  <th>Parameter</th>
                  <th>Result</th>
                  <th>Unit</th>
                  <th>Reference range</th>
                </tr>
              </thead>
              <tbody>
                {(item.results ?? []).map((r) =>
                  r.is_heading ? (
                    <tr key={r.id} className="lab-heading">
                      <td colSpan={4}>{r.parameter}</td>
                    </tr>
                  ) : (
                    <tr key={r.id}>
                      <td>
                        {r.parameter}
                        {r.method && <small className="block">{r.method}</small>}
                      </td>
                      <td>{r.flag ? <strong>{r.value} {r.flag === 'high' ? '(H)' : '(L)'}</strong> : r.value}</td>
                      <td>{r.unit}</td>
                      <td>{r.reference_range}</td>
                    </tr>
                  ),
                )}
              </tbody>
            </table>
          )}
          {item.remarks && <p className="report-text">Comment: {item.remarks}</p>}
        </section>
      ))}

      {pending > 0 && <p className="rx-vitals gap-top">{pending} test(s) in this order are still pending.</p>}

      <div className="lab-signatures">
        {([1, 2] as const).filter((slot) => settings[`technician_${slot}`]).map((slot) => (
          <div key={slot}>
            {settings[`technician_${slot}_signature`] && <img src={settings[`technician_${slot}_signature`]!} alt="" />}
            <strong>{settings[`technician_${slot}`]}</strong>
            <br />
            <small>Lab technician</small>
          </div>
        ))}
        <div className="right">
          {signerSignature && <img src={signerSignature} alt="" />}
          <strong>{signer?.signature_text || signer?.name}</strong>
          <br />
          <small>{last.approver ? 'Approved by' : 'Reported by'}</small>
        </div>
      </div>
      <ClinicFooter />
    </div>
  )
}
