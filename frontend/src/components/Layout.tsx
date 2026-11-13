import { useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { PatientSearch } from './PatientSearch'
import { useAuth } from '../auth'
import { menuTabs, onTab } from '../nav'

export function Layout() {
  const { user, signOut } = useAuth()
  const [open, setOpen] = useState(false)
  const { pathname } = useLocation()

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
          {menuTabs(user).map((t) => (
            <NavLink key={t.key} to={t.to} className={onTab(t, pathname) ? 'active' : ''}>
              {t.label}
            </NavLink>
          ))}
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
