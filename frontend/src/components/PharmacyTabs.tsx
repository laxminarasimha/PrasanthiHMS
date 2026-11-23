import { useEffect, useRef, useState } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { useAuth } from '../auth'

const GROUPS: [string, [string, string][]][] = [
  ['Today', [['/pharmacy/manager', 'Manager']]],
  ['Billing', [['/pharmacy/dashboard', 'Dashboard'], ['/pharmacy', 'Billing'], ['/pharmacy/bills', 'All bills'], ['/pharmacy/orders', 'Prescriptions'], ['/pharmacy/subscriptions', 'Monthly medicines']]],
  ['Inventory', [['/pharmacy/inventory', 'Inventory'], ['/pharmacy/opening-stock', 'Opening stock'], ['/pharmacy/medicines', 'Medicines'], ['/pharmacy/national-list', 'National drug list'], ['/pharmacy/shelves', 'Racks and boxes']]],
  ['Stock audit', [['/pharmacy/audit', 'Stock audit']]],
  ['Purchases', [['/pharmacy/purchases', 'Invoices'], ['/pharmacy/stockists', 'Stockists'], ['/pharmacy/stock-returns', 'Stock returns'], ['/pharmacy/purchase-orders', 'Purchase orders']]],
  ['Reports', [['/pharmacy/reports', 'Reports']]],
]

/**
 * The pharmacy's own menu, on one line under the top bar. A group with several pages opens as a
 * short list; the group you are in is marked and names the page you are on.
 */
/** Pages that only make sense for someone who works in the pharmacy: making a bill, entering opening stock. */
const WORK_ONLY = ['/pharmacy', '/pharmacy/opening-stock']

export function PharmacyTabs() {
  const { pathname } = useLocation()
  const { user } = useAuth()
  // Someone allowed only to look gets the same menu without the pages for doing the work, and is told so.
  const looking = Boolean(user && !user.can_run_pharmacy)
  const groups = looking ? GROUPS.map(([group, links]) => [group, links.filter(([to]) => !WORK_ONLY.includes(to))] as (typeof GROUPS)[number]).filter(([, links]) => links.length > 0) : GROUPS
  const [open, setOpen] = useState<string | null>(null)
  const bar = useRef<HTMLElement>(null)
  // With a mouse the lists open as the pointer passes; on a touch screen they open on a tap.
  const hovers = useRef(typeof window !== 'undefined' && window.matchMedia('(hover: hover)').matches)

  useEffect(() => {
    if (!open) return
    const close = (e: Event) => {
      if (e.type === 'keydown' ? (e as KeyboardEvent).key === 'Escape' : !bar.current?.contains(e.target as Node)) setOpen(null)
    }
    document.addEventListener('click', close)
    document.addEventListener('keydown', close)
    return () => {
      document.removeEventListener('click', close)
      document.removeEventListener('keydown', close)
    }
  }, [open])

  const on = (to: string) => (to === '/pharmacy' ? pathname === '/pharmacy' : pathname === to || pathname.startsWith(to + '/'))

  return (
    <nav className="subnav grouped no-print" ref={bar} aria-label="Pharmacy pages">
      {looking && (
        <span className="look-only" title="You can open the pharmacy's screens and reports. Selling, receiving stock and editing are for the pharmacy's own staff.">
          Look only
        </span>
      )}
      {groups.map(([group, links]) => {
        if (links.length === 1) {
          return (
            <NavLink key={group} to={links[0][0]}>
              {links[0][1]}
            </NavLink>
          )
        }
        const current = links.find(([to]) => on(to))
        const isOpen = open === group
        return (
          <div key={group} className="subnav-menu" onMouseEnter={() => hovers.current && setOpen(group)} onMouseLeave={() => hovers.current && setOpen(null)}>
            <button type="button" className={current ? 'active' : ''} aria-haspopup="menu" aria-expanded={isOpen} onClick={() => setOpen(hovers.current || !isOpen ? group : null)}>
              {group}
              {current && current[1] !== group && <small> · {current[1]}</small>}
              <span className="caret" aria-hidden="true">
                ▾
              </span>
            </button>
            {isOpen && (
              <div className="subnav-drop" role="menu">
                {links.map(([to, label]) => (
                  <NavLink key={to} to={to} end={to === '/pharmacy'} role="menuitem" onClick={() => setOpen(null)}>
                    {label}
                  </NavLink>
                ))}
              </div>
            )}
          </div>
        )
      })}
    </nav>
  )
}
