import { Suspense, useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { PatientSearch } from './PatientSearch'
import { useAuth } from '../auth'
import { allowedTabs, menuTabs, onTab } from '../nav'
import { canLeave } from '../unsaved'

export function Layout() {
  const { user, signOut } = useAuth()
  const [open, setOpen] = useState(false)
  const { pathname } = useLocation()

  // The browser tab says which part of the app it is showing, so several open tabs can be told apart.
  useEffect(() => {
    const section = user ? (pathname.startsWith('/account') ? 'My account' : allowedTabs(user).find((t) => onTab(t, pathname))?.label) : undefined
    document.title = section ? `${section} · Prasanthi HMS` : 'Prasanthi HMS'
  }, [pathname, user])

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
        <nav
          // Asked on the way down to the link, before the link itself acts: notes typed but not saved.
          onClickCapture={(e) => {
            if ((e.target as HTMLElement).closest('a') && !canLeave()) {
              e.preventDefault()
              e.stopPropagation()
            }
          }}
          onClick={() => setOpen(false)}
        >
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
            <button className="btn ghost" onClick={() => canLeave() && signOut()}>
              Sign out
            </button>
          </div>
        </nav>
        <PatientSearch />
        <div className="whoami">
          <div>
            <NavLink className="who-link" to="/account" title="My account: password, appearance, menu" onClick={(e) => !canLeave() && e.preventDefault()}>
              <strong>{user.name}</strong>
            </NavLink>
            <small>
              {user.role_label}
              {user.branch ? ` · ${user.branch.name}` : ''}
            </small>
          </div>
          <button className="btn ghost" onClick={() => canLeave() && signOut()}>
            Sign out
          </button>
        </div>
      </header>
      <main className="content">
        <Suspense fallback={<p className="loading">Loading…</p>}>
          <Outlet />
        </Suspense>
      </main>
    </div>
  )
}
