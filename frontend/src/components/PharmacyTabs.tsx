import { NavLink } from 'react-router-dom'

/** Section links shown at the top of every pharmacy page. */
export function PharmacyTabs() {
  return (
    <nav className="subnav">
      <NavLink to="/pharmacy" end>
        Billing
      </NavLink>
      <NavLink to="/pharmacy/bills">All bills</NavLink>
      <NavLink to="/pharmacy/stock">Stock</NavLink>
      <NavLink to="/pharmacy/purchases">Purchase invoices</NavLink>
      <NavLink to="/pharmacy/medicines">Medicines &amp; stockists</NavLink>
    </nav>
  )
}
