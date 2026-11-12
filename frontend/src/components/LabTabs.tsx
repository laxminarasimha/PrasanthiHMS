import { NavLink } from 'react-router-dom'

/** Links between the lab's day list and its setup pages. */
export function LabTabs() {
  return (
    <nav className="subnav no-print">
      <NavLink to="/lab" end>
        Lab orders
      </NavLink>
      <NavLink to="/lab/tests">Report templates</NavLink>
      <NavLink to="/lab/packages">Packages</NavLink>
      <NavLink to="/lab/settings">Preferences</NavLink>
    </nav>
  )
}
