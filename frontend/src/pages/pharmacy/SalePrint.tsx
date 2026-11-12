import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../../api'
import { useAuth } from '../../auth'
import { clock, expiryLabel, longDate, modeLabel, rupees } from '../../format'
import type { PharmacySale } from '../../types'

/** The pharmacy bill handed to the customer; opens the print dialog once loaded. */
export function PharmacySalePrint() {
  const { id } = useParams()
  const { user } = useAuth()
  const [sale, setSale] = useState<PharmacySale | null>(null)

  useEffect(() => {
    api.get<PharmacySale>(`/pharmacy/sales/${id}`).then((res) => {
      setSale(res.data)
      setTimeout(() => window.print(), 300)
    })
  }, [id])

  if (!sale) return <p className="loading">Loading bill…</p>

  return (
    <div className="receipt">
      <header>
        <h1>{user?.branch?.name ?? 'Prasanthi Hospitals'} Pharmacy</h1>
        {user?.branch?.city && <p>{user.branch.city}</p>}
        <h2>Pharmacy bill</h2>
      </header>

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
        </div>
      </div>

      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Medicine</th>
            <th>Batch</th>
            <th>Expiry</th>
            <th className="num">Qty</th>
            <th className="num">Price</th>
            <th className="num">Disc %</th>
            <th className="num">GST %</th>
            <th className="num">Amount</th>
          </tr>
        </thead>
        <tbody>
          {sale.items?.map((item, i) => (
            <tr key={item.id}>
              <td>{i + 1}</td>
              <td>{item.medicine_name}</td>
              <td>{item.batch_no}</td>
              <td>{expiryLabel(item.expiry)}</td>
              <td className="num">{item.quantity}</td>
              <td className="num">{rupees(item.unit_price)}</td>
              <td className="num">{item.discount_percent || ''}</td>
              <td className="num">{item.gst_percent}</td>
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

      <footer>Billed by {sale.seller?.name}. Medicines once sold are taken back only as per pharmacy policy.</footer>
    </div>
  )
}
