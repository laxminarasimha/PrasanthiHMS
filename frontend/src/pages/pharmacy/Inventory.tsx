import { useEffect, useState } from 'react'
import { api } from '../../api'
import { PharmacyTabs } from '../../components/PharmacyTabs'
import { downloadCsv, expiryLabel, today } from '../../format'
import type { InventoryMedicine } from '../../types'

type Tab = 'all' | 'expiring' | 'low' | 'zero'
type Data = { all: InventoryMedicine[]; counts: Record<'expiring' | 'low' | 'zero', number> }

const FILTERS: Record<Tab, (m: InventoryMedicine) => boolean> = {
  all: () => true,
  expiring: (m) => m.expiring_units > 0,
  low: (m) => m.units_in_stock > 0 && m.units_in_stock <= m.reorder_level,
  zero: (m) => m.units_in_stock === 0,
}

/** Units left per medicine, with the lists a pharmacist checks before ordering. */
export function PharmacyInventory() {
  const [data, setData] = useState<Data | null>(null)
  const [tab, setTab] = useState<Tab>('all')
  const [query, setQuery] = useState('')

  useEffect(() => {
    api.get<Data>('/pharmacy/inventory').then((res) => setData(res.data))
  }, [])

  const rows = (data?.all ?? []).filter(FILTERS[tab]).filter((m) => m.name.toLowerCase().includes(query.trim().toLowerCase()))
  const tabs: [Tab, string][] = [
    ['all', `All medicines (${data?.all.length ?? 0})`],
    ['expiring', `Expiring in 90 days (${data?.counts.expiring ?? 0})`],
    ['low', `Low stock (${data?.counts.low ?? 0})`],
    ['zero', `Out of stock (${data?.counts.zero ?? 0})`],
  ]

  return (
    <>
      <PharmacyTabs />
      <section className="card">
        <div className="toolbar">
          <h1>Inventory</h1>
          <input className="search" type="search" placeholder="Filter by medicine" value={query} onChange={(e) => setQuery(e.target.value)} />
          <button
            className="btn ghost"
            onClick={() =>
              downloadCsv(`inventory-${tab}-${today()}.csv`, ['Medicine', 'Type', 'Manufacturer', 'Units in stock', 'Low-stock level', 'Units expiring in 90 days', 'Next expiry'],
                rows.map((m) => [m.name, m.type, m.manufacturer, m.units_in_stock, m.reorder_level, m.expiring_units, m.next_expiry]))
            }
          >
            Export
          </button>
        </div>
        <div className="tabs">
          {tabs.map(([key, label]) => (
            <button key={key} className={tab === key ? 'active' : ''} onClick={() => setTab(key)}>
              {label}
            </button>
          ))}
        </div>
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Medicine</th>
                <th>Manufacturer</th>
                <th className="num">Units in stock</th>
                <th className="num">Low-stock level</th>
                <th className="num">Expiring in 90 days</th>
                <th>Next expiry</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((m) => (
                <tr key={m.id} className="static">
                  <td>
                    <strong>{m.name}</strong>
                    {m.type && <small className="muted"> {m.type}</small>}
                  </td>
                  <td>{m.manufacturer ?? '—'}</td>
                  <td className="num">
                    <strong className={m.units_in_stock === 0 ? 'due' : ''}>{m.units_in_stock}</strong>{' '}
                    {m.units_in_stock > 0 && m.units_in_stock <= m.reorder_level && <span className="pill off">Low</span>}
                  </td>
                  <td className="num">{m.reorder_level}</td>
                  <td className="num">{m.expiring_units || '—'}</td>
                  <td>{m.next_expiry ? expiryLabel(m.next_expiry) : '—'}</td>
                </tr>
              ))}
              {data && rows.length === 0 && (
                <tr className="empty">
                  <td colSpan={6}>Nothing in this list.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
    </>
  )
}
