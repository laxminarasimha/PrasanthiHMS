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
          <NavLink to="/front-desk">Front Desk</NavLink>
          <NavLink to="/doctor">Doctor</NavLink>
          <NavLink to="/patients">Patients</NavLink>
          <NavLink to="/services">Services</NavLink>
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
