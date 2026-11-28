import { NavLink } from 'react-router-dom'

/** The doctor's pages on one line under the top bar. The patient list is one of them. */
export function DoctorTabs() {
  return (
    <nav className="subnav no-print">
      <NavLink to="/doctor" end>
        Queue
      </NavLink>
      <NavLink to="/doctor/consults">Consults</NavLink>
      <NavLink to="/doctor/results">Results</NavLink>
      <NavLink to="/patients">Patients</NavLink>
      <NavLink to="/doctor/library">My library</NavLink>
      <NavLink to="/reps">Medical reps</NavLink>
    </nav>
  )
}
