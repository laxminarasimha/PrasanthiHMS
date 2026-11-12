import { NavLink } from 'react-router-dom'

export function DoctorTabs() {
  return (
    <nav className="subnav no-print">
      <NavLink to="/doctor" end>
        Queue
      </NavLink>
      <NavLink to="/doctor/consults">Consults</NavLink>
      <NavLink to="/doctor/library">My library</NavLink>
    </nav>
  )
}
