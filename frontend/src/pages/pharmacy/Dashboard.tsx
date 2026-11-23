import { useEffect, useState } from 'react'
import { useRangeInAddress } from '../../rangeInAddress'
import { api } from '../../api'
import { DateRange } from '../../components/DateRange'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { clock, rupees } from '../../format'
import type { PharmacyDashboard as Data } from '../../types'
import { PatientLink } from '../../components/PatientLink'
import { MoneyByMode } from '../../components/MoneyByMode'

export function PharmacyDashboard() {
  const [from, to, setRange] = useRangeInAddress('today')
  const [data, setData] = useState<Data | null>(null)

  useEffect(() => {
    api.get<Data>('/pharmacy/dashboard', { params: { from, to } }).then((res) => setData(res.data))
  }, [from, to])

  const stats = data?.statistics

  return (
    <>
      <PharmacyTabs />
      <section className="card">
        <div className="toolbar">
          <h1>Pharmacy dashboard</h1>
          <DateRange from={from} to={to} onChange={setRange} />
        </div>
        {data && stats && (
          <>
            <div className="tiles">
              {[
                ['Total sales', String(data.sales)],
                ['Total billed', rupees(data.billed)],
                ['Total collected', rupees(data.collected)],
              ].map(([label, value]) => (
                <div className="tile" key={label}>
                  <span>{label}</span>
                  <strong>{value}</strong>
                </div>
              ))}
            </div>
            <MoneyByMode amounts={data.collected_by_mode} note="Money received at the pharmacy counter in this period, net of refunds." />

            <div className="three-up">
              <div>
                <h3>Statistics</h3>
                <dl className="totals">
                  <dt>Medicines</dt>
                  <dd>{stats.medicines}</dd>
                  <dt>Manufacturers</dt>
                  <dd>{stats.manufacturers}</dd>
                  <dt>Inventory at cost</dt>
                  <dd>{rupees(stats.inventory_cost)}</dd>
                  <dt>Inventory at MRP</dt>
                  <dd>{rupees(stats.inventory_mrp)}</dd>
                  <dt>Units in stock</dt>
                  <dd>{stats.units_in_stock.toLocaleString('en-IN')}</dd>
                  <dt>Medicines out of stock</dt>
                  <dd className={stats.out_of_stock > 0 ? 'due' : ''}>{stats.out_of_stock}</dd>
                </dl>
              </div>
              <div>
                <h3>Latest sales</h3>
                <table className="compact">
                  <tbody>
                    {data.latest_sales.map((s) => (
                      <tr key={s.id} className="static">
                        <td className="mono">{s.bill_no}</td>
                        <td>{clock(s.sold_at)}</td>
                        <td>
                          <PatientLink id={s.patient_id} bills>
                            {s.customer_name}
                          </PatientLink>
                        </td>
                        <td className="num">{rupees(s.total)}</td>
                      </tr>
                    ))}
                    {data.latest_sales.length === 0 && (
                      <tr className="empty">
                        <td>No sales in this period.</td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
              <div>
                <h3>Fast-moving medicines</h3>
                <table className="compact">
                  <tbody>
                    {data.fast_moving.map((m) => (
                      <tr key={m.medicine_name} className="static">
                        <td>{m.medicine_name}</td>
                        <td className="num">{m.sold} units</td>
                      </tr>
                    ))}
                    {data.fast_moving.length === 0 && (
                      <tr className="empty">
                        <td>Nothing sold in this period.</td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          </>
        )}
      </section>
    </>
  )
}
