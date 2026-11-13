import { useState } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { PatientSearch } from './PatientSearch'
import { useAuth } from '../auth'

export function Layout() {
  const { user, signOut } = useAuth()
  const [open, setOpen] = useState(false)

  if (!user) return null

  return (
    <div className="app">
      <header className={open ? 'topbar open' : 'topbar'}>
        <button className="nav-toggle" aria-label="Menu" aria-expanded={open} onClick={() => setOpen(!open)}>
          <span />
          <span />
          <span />
        </button>
        <div className="brand">
          Prasanthi <span>HMS</span>
        </div>
        {/* On phones the menu covers the screen, so close it once a page is chosen. */}
        <nav onClick={() => setOpen(false)}>
          {user.can_front_desk && <NavLink to="/front-desk">Front Desk</NavLink>}
          {user.can_consult && <NavLink to="/doctor">Doctor</NavLink>}
          {(user.can_run_lab || user.can_front_desk) && <NavLink to="/lab">Lab</NavLink>}
          {user.can_run_pharmacy && <NavLink to="/pharmacy">Pharmacy</NavLink>}
          {user.can_ipd && <NavLink to="/ipd">IPD</NavLink>}
          <NavLink to="/patients">Patients</NavLink>
          {user.can_manage_setup && <NavLink to="/services">Services</NavLink>}
          {user.can_view_reports && <NavLink to="/reports">Reports</NavLink>}
          {user.can_manage_staff && <NavLink to="/staff">Staff</NavLink>}
          {user.can_manage_setup && <NavLink to="/admin">Admin</NavLink>}
          <div className="nav-foot">
            <div>
              <NavLink to="/account">
                <strong>{user.name}</strong>
              </NavLink>
              <small>
                {user.role_label}
                {user.branch ? ` · ${user.branch.name}` : ''}
              </small>
            </div>
            <button className="btn ghost" onClick={signOut}>
              Sign out
            </button>
          </div>
        </nav>
        <PatientSearch />
        <div className="whoami">
          <div>
            <NavLink className="who-link" to="/account" title="My account: change password">
              <strong>{user.name}</strong>
            </NavLink>
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
