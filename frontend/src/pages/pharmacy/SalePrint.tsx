import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../../api'
import { HospitalGstin, PaperBar, PaperFooter, PaperHeader } from '../../components/PrintPaper'
import { paperClass, useAutoPrint, usePrintPaper } from '../../printPaper'
import { clock, expiryLabel, longDate, modeLabel, rupees } from '../../format'
import type { PharmacySale } from '../../types'

/** The pharmacy bill handed to the customer; opens the print dialog once loaded. */
export function PharmacySalePrint() {
  const { id } = useParams()
  const [sale, setSale] = useState<PharmacySale | null>(null)
  const paper = usePrintPaper(['a4', 'a5', 'thermal'])

  useEffect(() => {
    api.get<PharmacySale>(`/pharmacy/sales/${id}`).then((res) => setSale(res.data))
  }, [id])
  useAutoPrint(Boolean(sale) && paper.ready)

  if (!sale) return <p className="loading">Loading bill…</p>

  return (
    <div className={`receipt ${paperClass(paper)}`}>
      <PaperBar paper={paper} />
      <PaperHeader paper={paper} suffix="Pharmacy">
        <h2>Pharmacy bill{sale.voided_at ? ' (deleted)' : ''}</h2>
      </PaperHeader>

      <div className="receipt-meta">
        <div>
          <strong>{sale.customer_name}</strong>
          {sale.patient && (
            <>
              <br />
              Patient ID: {sale.patient.uhid}
            </>
          )}
          {sale.customer_phone && (
            <>
              <br />
              Phone: {sale.customer_phone}
            </>
          )}
          {sale.referred_doctor && (
            <>
              <br />
              Doctor: {sale.referred_doctor}
            </>
          )}
        </div>
        <div className="right">
          Bill no: <strong>{sale.bill_no}</strong>
          <br />
          {longDate(sale.sold_at)} {clock(sale.sold_at)}
          <HospitalGstin paper={paper} />
        </div>
      </div>

      <table>
        <thead>
          <tr>
            <th className="col-extra">#</th>
            <th className="col-name">Medicine</th>
            <th>Batch</th>
            <th>Expiry</th>
            <th className="num">Qty</th>
            <th className="num">Price</th>
            <th className="num col-extra">Disc %</th>
            <th className="num col-extra">GST %</th>
            <th className="num">Amount</th>
          </tr>
        </thead>
        <tbody>
          {sale.items?.map((item, i) => (
            <tr key={item.id}>
              <td className="col-extra">{i + 1}</td>
              <td className="col-name">{item.medicine_name}</td>
              <td>{item.batch_no}</td>
              <td>{expiryLabel(item.expiry)}</td>
              <td className="num">
                {item.quantity}
                {item.returned_quantity > 0 && <small className="block">{item.returned_quantity} returned</small>}
              </td>
              <td className="num">{rupees(item.unit_price)}</td>
              <td className="num col-extra">{item.discount_percent || ''}</td>
              <td className="num col-extra">{item.gst_percent}</td>
              <td className="num">{rupees(item.amount)}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <dl className="totals">
        <dt>Amount</dt>
        <dd>{rupees(sale.subtotal)}</dd>
        {sale.discount > 0 && (
          <>
            <dt>Discount</dt>
            <dd>−{rupees(sale.discount)}</dd>
          </>
        )}
        {sale.round_off !== 0 && (
          <>
            <dt>Round off</dt>
            <dd>{sale.round_off > 0 ? '+' : '−'}{rupees(Math.abs(sale.round_off))}</dd>
          </>
        )}
        <dt className="strong">Bill amount</dt>
        <dd className="strong">{rupees(sale.total)}</dd>
        {sale.returned > 0 && (
          <>
            <dt>Returned</dt>
            <dd>−{rupees(sale.returned)}</dd>
          </>
        )}
        <dt>Paid ({modeLabel(sale.payment_mode)})</dt>
        <dd>{rupees(sale.paid)}</dd>
        {sale.due > 0 && (
          <>
            <dt className="strong">Balance due</dt>
            <dd className="strong">{rupees(sale.due)}</dd>
          </>
        )}
      </dl>
      <p className="receipt-payments">
        Includes GST of {rupees(sale.tax)} (CGST {rupees(Math.round(sale.tax * 50) / 100)} + SGST {rupees(Math.round(sale.tax * 50) / 100)}).
      </p>

      <PaperFooter paper={paper} />
    </div>
  )
}
