import { Suspense, useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { BrandMark } from './Brand'
import { ErrorBoundary } from './ErrorBoundary'
import { PatientSearch } from './PatientSearch'
import { onLoadTrouble } from '../api'
import { useAuth } from '../auth'
import { keepPrintPrefsFresh } from '../printPaper'
import { allowedTabs, menuTabs, onTab } from '../nav'
import { canLeave } from '../unsaved'
import { today } from '../format'
import { loadEnded, loadStarted, useFirstLoad } from '../loading'

/** A small drawing beside each menu name, so a section is found by shape as well as by word. */
const ICONS: Record<string, string> = {
  'front-desk': 'M4 5h16v4H4zM6 9v10M18 9v10M9 13h6',
  doctor: 'M6 3v6a4 4 0 0 0 8 0V3M10 13v3a5 5 0 0 0 10 0v-2M20 11a1.6 1.6 0 1 0 0 .01',
  lab: 'M9 3h6M10 3v6l-5 9a2 2 0 0 0 1.8 3h10.4a2 2 0 0 0 1.8-3l-5-9V3M8 15h8',
  pharmacy: 'M4.5 12a5.3 5.3 0 0 1 7.5-7.5l7.5 7.5a5.3 5.3 0 0 1-7.5 7.5zM8.5 8.5l7 7',
  ipd: 'M3 18V6M3 14h18v4M21 14v-2a3 3 0 0 0-3-3h-7v5M7 11.5a1.5 1.5 0 1 0 0 .01',
  patients: 'M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM3 20a6 6 0 0 1 12 0M16.5 4.5a3.5 3.5 0 0 1 0 6.5M18 14.5a6 6 0 0 1 3 5.5',
  reports: 'M4 20V4M4 20h16M8 16v-4M12 16V8M16 16v-6',
  workforce: 'M16 11a3 3 0 1 0 0-6M8 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM2 20a6 6 0 0 1 12 0M16 14a6 6 0 0 1 6 6',
  setup: 'M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM19 12a7 7 0 0 0-.1-1.2l2-1.5-2-3.4-2.3.9a7 7 0 0 0-2.1-1.2L14 3h-4l-.5 2.6a7 7 0 0 0-2.1 1.2l-2.3-.9-2 3.4 2 1.5a7 7 0 0 0 0 2.4l-2 1.5 2 3.4 2.3-.9a7 7 0 0 0 2.1 1.2L10 21h4l.5-2.6a7 7 0 0 0 2.1-1.2l2.3.9 2-3.4-2-1.5c.07-.4.1-.8.1-1.2z',
}

/** The first letters of a person's name, for the round badge beside it. */
const initials = (name: string) =>
  name.replace(/^(dr|mr|mrs|ms)\.?\s+/i, '').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('')

/** Shown while a screen's own code is being fetched; that counts as the screen still loading. */
function ScreenComing() {
  useEffect(() => {
    const ask = loadStarted()
    return () => loadEnded(ask)
  }, [])
  return <p className="loading">Loading…</p>
}

export function Layout() {
  const { user, signOut } = useAuth()
  const [open, setOpen] = useState(false)
  const { pathname } = useLocation()
  // Whether the last thing any screen tried to load failed to arrive.
  const [trouble, setTrouble] = useState(false)
  // Until a screen has everything it first asked for, its figures are greyed and "nothing here" messages held back.
  const loading = useFirstLoad(pathname)
  useEffect(() => onLoadTrouble(setTrouble), [])
  // "Later" on the backup reminder: the same message stays away on this computer until tomorrow. A new message shows at once.
  const [laterFor, setLaterFor] = useState(() => {
    try {
      return localStorage.getItem('hms_backup_later') ?? ''
    } catch {
      return ''
    }
  })
  const remindLater = () => {
    const mark = `${today()} ${user?.backup_alert ?? ''}`
    setLaterFor(mark)
    try {
      localStorage.setItem('hms_backup_later', mark)
    } catch {
      // Without storage the reminder is only hidden until the page is loaded again.
    }
  }
  // Print pages open in their own tab and print at once, so the way prints are laid out is fetched here, ahead of time.
  useEffect(() => {
    void keepPrintPrefsFresh()
  }, [])

  // The browser tab says which part of the app it is showing, so several open tabs can be told apart.
  useEffect(() => {
    const section = user ? (pathname.startsWith('/account') ? 'My account' : pathname.startsWith('/patients') ? 'Patients' : allowedTabs(user).find((t) => onTab(t, pathname))?.label) : undefined
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
          <BrandMark />
          <span className="brand-name">
            Prasanthi <span>HMS</span>
          </span>
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
              {ICONS[t.key] && (
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <path d={ICONS[t.key]} />
                </svg>
              )}
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
          <NavLink className="avatar" to="/account" title={`${user.name} · ${user.role_label}: my account`} aria-label="My account" onClick={(e) => !canLeave() && e.preventDefault()}>
            {initials(user.name)}
          </NavLink>
          <div className="who-text">
            <NavLink className="who-link" to="/account" title="My account: password, appearance, menu" onClick={(e) => !canLeave() && e.preventDefault()}>
              <strong>{user.name}</strong>
            </NavLink>
            <small>
              {user.role_label}
              {user.branch ? ` · ${user.branch.name}` : ''}
            </small>
          </div>
          <button className="btn ghost signout" title="Sign out" onClick={() => canLeave() && signOut()}>
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4M15 8l4 4-4 4M19 12H9" />
            </svg>
            <span>Sign out</span>
          </button>
        </div>
      </header>
      <main className={`content${loading ? ' first-load' : ''}`} aria-busy={loading}>
        {loading && <div className="load-bar" aria-hidden="true" />}
        {user.backup_alert && laterFor !== `${today()} ${user.backup_alert}` && (
          <div className="backup-alert">
            <NavLink to="/admin?tab=backups">
              <strong>Backups:</strong> {user.backup_alert}
            </NavLink>
            <button type="button" className="link" onClick={remindLater} title="Hide this reminder on this computer until tomorrow">
              Later
            </button>
          </div>
        )}
        {/* One broken screen shows a message in place; the menu stays, and going elsewhere starts afresh. */}
        <ErrorBoundary key={pathname}>
          <Suspense fallback={<ScreenComing />}>
            <Outlet />
          </Suspense>
        </ErrorBoundary>
        {trouble && (
          <div className="form-error floating-alert quiet top" role="alert">
            <strong>Could not load from the server.</strong> Check the internet connection. What is on the screen may be incomplete or out of date.{' '}
            <button type="button" className="link" onClick={() => window.location.reload()}>
              Reload
            </button>
          </div>
        )}
      </main>
    </div>
  )
}
