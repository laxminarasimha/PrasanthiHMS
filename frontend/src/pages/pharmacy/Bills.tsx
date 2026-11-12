import { useCallback, useEffect, useState } from 'react'
import { api } from '../../api'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { clock, longDate, modeLabel, rupees, today } from '../../format'
import type { PharmacySale } from '../../types'

export function PharmacyBills() {
  const [date, setDate] = useState(today())
  const [query, setQuery] = useState('')
  const [sales, setSales] = useState<PharmacySale[] | null>(null)
  const searching = query.trim().length >= 2

  const load = useCallback(() => {
    api.get<PharmacySale[]>('/pharmacy/sales', { params: searching ? { q: query.trim() } : { date } }).then((res) => setSales(res.data))
  }, [date, query, searching])

  useEffect(() => {
    const timer = setTimeout(load, 250)
    return () => clearTimeout(timer)
  }, [load])

  const billed = sales?.reduce((sum, s) => sum + s.total, 0) ?? 0
  const collected = sales?.reduce((sum, s) => sum + s.paid, 0) ?? 0

  return (
    <>
      <PharmacyTabs />
      <section className="card">
        <div className="toolbar">
          <h1>Pharmacy bills</h1>
          <input type="date" className="date" value={date} disabled={searching} onChange={(e) => setDate(e.target.value || today())} aria-label="Day" />
          <input className="search" type="search" placeholder="Find by bill no., customer name or phone (all dates)" value={query} onChange={(e) => setQuery(e.target.value)} />
          <span className="muted">
            Billed <strong>{rupees(billed)}</strong> · Collected <strong>{rupees(collected)}</strong>
          </span>
        </div>
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Bill</th>
                <th>Customer</th>
                <th className="num">Items</th>
                <th className="num">Amount</th>
                <th className="num">Paid</th>
                <th>Mode</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {sales?.map((s) => (
                <tr key={s.id} className="static">
                  <td>
                    <strong className="mono">{s.bill_no}</strong>
                    <small className="muted block">
                      {searching ? `${longDate(s.sold_at)} ` : ''}
                      {clock(s.sold_at)}
                    </small>
                  </td>
                  <td>
                    <strong>{s.customer_name}</strong>
                    {s.customer_phone && <small className="muted block">{s.customer_phone}</small>}
                  </td>
                  <td className="num">{s.items_count}</td>
                  <td className="num">{rupees(s.total)}</td>
                  <td className="num">
                    {rupees(s.paid)}
                    {s.due > 0 && <small className="block due">{rupees(s.due)} due</small>}
                    {s.ipd_transfer > 0 && <small className="block muted">{rupees(s.ipd_transfer)} on IPD bill</small>}
                  </td>
                  <td>{modeLabel(s.payment_mode)}</td>
                  <td className="num">
                    <a className="btn ghost" href={`/pharmacy/bills/${s.id}/print`} target="_blank" rel="noreferrer">
                      Print
                    </a>
                  </td>
                </tr>
              ))}
              {sales && sales.length === 0 && (
                <tr className="empty">
                  <td colSpan={7}>{searching ? 'No bills match this search.' : 'No pharmacy bills for this day.'}</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
    </>
  )
}
