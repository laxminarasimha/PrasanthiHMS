import { NavLink, Outlet } from 'react-router-dom'
import { useAuth } from '../auth'

export function Layout() {
  const { user, signOut } = useAuth()
  if (!user) return null

  return (
    <div className="app">
      <header className="topbar">
        <div className="brand">
          Prasanthi <span>HMS</span>
        </div>
        <nav>
          {user.can_front_desk && <NavLink to="/front-desk">Front Desk</NavLink>}
          {user.can_consult && <NavLink to="/doctor">Doctor</NavLink>}
          {(user.can_run_lab || user.can_front_desk) && <NavLink to="/lab">Lab</NavLink>}
          {user.can_run_pharmacy && <NavLink to="/pharmacy">Pharmacy</NavLink>}
          {user.can_ipd && <NavLink to="/ipd">IPD</NavLink>}
          <NavLink to="/patients">Patients</NavLink>
          {user.can_manage_setup && <NavLink to="/services">Services</NavLink>}
          {user.can_manage_staff && <NavLink to="/reports">Reports</NavLink>}
          {user.can_manage_staff && <NavLink to="/staff">Staff</NavLink>}
        </nav>
        <div className="whoami">
          <div>
            <strong>{user.name}</strong>
            <small>
              {user.role_label}
              {user.branch ? ` · ${user.branch.name}` : ''}
            </small>
          </div>
          <button className="btn ghost" onClick={signOut}>
            Sign out
          </button>
        </div>
      </header>
      <main className="content">
        <Outlet />
      </main>
    </div>
  )
}
