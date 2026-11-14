import { NavLink } from 'react-router-dom'

const GROUPS: [string, [string, string][]][] = [
  ['Today', [['/pharmacy/manager', 'Manager']]],
  ['Billing', [['/pharmacy/dashboard', 'Dashboard'], ['/pharmacy', 'Billing'], ['/pharmacy/bills', 'All bills'], ['/pharmacy/orders', 'Prescriptions'], ['/pharmacy/subscriptions', 'Monthly medicines']]],
  ['Inventory', [['/pharmacy/stock', 'Stock by batch'], ['/pharmacy/inventory', 'Inventory'], ['/pharmacy/opening-stock', 'Opening stock'], ['/pharmacy/medicines', 'Medicines']]],
  ['Purchases', [['/pharmacy/purchases', 'Invoices'], ['/pharmacy/stock-returns', 'Stock returns'], ['/pharmacy/purchase-orders', 'Purchase orders']]],
  ['Reports', [['/pharmacy/reports', 'Reports']]],
]

/** Section links shown at the top of every pharmacy page, grouped the way the work is done. */
export function PharmacyTabs() {
  return (
    <nav className="subnav grouped no-print">
      {GROUPS.map(([group, links]) => (
        <div key={group} className="subnav-group">
          <span>{group}</span>
          {links.map(([to, label]) => (
            <NavLink key={to} to={to} end={to === '/pharmacy'}>
              {label}
            </NavLink>
          ))}
        </div>
      ))}
    </nav>
  )
}
