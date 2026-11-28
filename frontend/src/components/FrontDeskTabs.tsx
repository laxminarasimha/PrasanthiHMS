import { NavLink } from 'react-router-dom'
import { useAuth } from '../auth'
import { patientsSection } from '../nav'

/** The front desk's pages on one line under the top bar, each shown only to someone allowed to open it. */
export function FrontDeskTabs() {
  const { user } = useAuth()
  if (!user?.can_front_desk) return null
  return (
    <nav className="subnav no-print" aria-label="Front desk pages">
      <NavLink to="/front-desk" end>
        Day list
      </NavLink>
      <NavLink to="/front-desk/bills">All bills</NavLink>
      <NavLink to="/front-desk/calendar">Calendar</NavLink>
      {patientsSection(user) === 'front-desk' && <NavLink to="/patients">Patients</NavLink>}
      {user.can_record_expenses && <NavLink to="/expenses">Expenses</NavLink>}
      {user.can_file_past_records && <NavLink to="/past-records">Old papers</NavLink>}
      <NavLink to="/reps">Medical reps</NavLink>
    </nav>
  )
}
