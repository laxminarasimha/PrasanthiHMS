import { NavLink } from 'react-router-dom'
import { useWorkforce } from './shared'

/** The workforce pages a person may open, on one line under the top bar. */
export function WorkforceTabs() {
  const { can } = useWorkforce()
  return (
    <nav className="subnav no-print" aria-label="Workforce pages">
      {can('wf:reports:view') && (
        <NavLink to="/workforce" end>
          Today
        </NavLink>
      )}
      {can('wf:staff:view') && <NavLink to="/workforce/staff">Staff</NavLink>}
      {can('wf:attendance:view') && <NavLink to="/workforce/attendance">Attendance</NavLink>}
      {can('wf:leave:view') && <NavLink to="/workforce/leave">Leave</NavLink>}
      {can('wf:settings:admin') && <NavLink to="/workforce/setup">Setup</NavLink>}
      {can('wf:self:view') && <NavLink to="/workforce/me">My record</NavLink>}
    </nav>
  )
}
