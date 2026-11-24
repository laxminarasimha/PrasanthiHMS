import { useAuth } from '../auth'
import { GroupedTabs, type TabGroup } from './GroupedTabs'

const GROUPS: TabGroup[] = [
  // The manager's own pages: what needs doing, checking the shelf against the records, and the figures.
  ['Manager', [['/pharmacy/manager', 'To do today'], ['/pharmacy/audit', 'Stock audit'], ['/pharmacy/reports', 'Reports']]],
  ['Billing', [['/pharmacy/dashboard', 'Dashboard'], ['/pharmacy', 'Billing'], ['/pharmacy/bills', 'All bills'], ['/pharmacy/orders', 'Prescriptions'], ['/pharmacy/subscriptions', 'Monthly medicines']]],
  ['Inventory', [['/pharmacy/inventory', 'Inventory'], ['/pharmacy/opening-stock', 'Opening stock'], ['/pharmacy/medicines', 'Medicines'], ['/pharmacy/national-list', 'National drug list'], ['/pharmacy/shelves', 'Racks and boxes']]],
  ['Purchases', [['/pharmacy/purchases', 'Invoices'], ['/pharmacy/stockists', 'Stockists'], ['/pharmacy/stock-returns', 'Stock returns'], ['/pharmacy/purchase-orders', 'Purchase orders']]],
]

/** Pages that only make sense for someone who works in the pharmacy: making a bill, entering opening stock. */
const WORK_ONLY = ['/pharmacy', '/pharmacy/opening-stock']

/** The pharmacy's own menu, on one line under the top bar. */
export function PharmacyTabs() {
  const { user } = useAuth()
  // Someone allowed only to look gets the same menu without the pages for doing the work, and is told so.
  const looking = Boolean(user && !user.can_run_pharmacy)
  const groups = looking ? GROUPS.map(([group, links]): TabGroup => [group, links.filter(([to]) => !WORK_ONLY.includes(to))]).filter(([, links]) => links.length > 0) : GROUPS
  return (
    <GroupedTabs
      label="Pharmacy pages"
      groups={groups}
      exact={['/pharmacy']}
      lead={
        looking && (
          <span className="look-only" title="You can open the pharmacy's screens and reports. Selling, receiving stock and editing are for the pharmacy's own staff.">
            Look only
          </span>
        )
      }
    />
  )
}
