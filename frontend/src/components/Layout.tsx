import { Suspense, useEffect, useState } from 'react'
import { Navigate, NavLink, Outlet, useLocation } from 'react-router-dom'
import { BottomBar } from './BottomBar'
import { BrandMark } from './Brand'
import { ErrorBoundary } from './ErrorBoundary'
import { InstallHint } from './InstallHint'
import { PushHint } from './PushControls'
import { useTodoCount } from '../todoCount'
import { syncPush } from '../push'
import { getsNotices, useOpenFromNotice } from '../notices'
import { PatientSearch } from './PatientSearch'
import { onLoadTrouble } from '../api'
import { useAuth } from '../auth'
import { keepPrintPrefsFresh } from '../printPaper'
import { homeFor, ownRecordOnly } from '../access'
import { allowedTabs, menuTabs, onTab } from '../nav'
import { NAV_ICONS } from '../navIcons'
import { AttendanceCard } from '../workforce/AttendanceCard'
import { CheckInPrompt } from '../workforce/CheckInPrompt'
import { checkInDue, useCheckInPutAway, useMyToday } from '../workforce/myToday'
import { canLeave } from '../unsaved'
import { today } from '../format'
import { loadEnded, loadStarted, useFirstLoad } from '../loading'

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
  const todoCount = useTodoCount(user)
  const [open, setOpen] = useState(false)
  const { pathname } = useLocation()
  // Whether the last thing any screen tried to load failed to arrive.
  const [trouble, setTrouble] = useState(false)
  // Until a screen has everything it first asked for, its figures are greyed and "nothing here" messages held back.
  const loading = useFirstLoad(pathname)
  useEffect(() => onLoadTrouble(setTrouble), [])
  // A notice tapped while the app is open goes to its page, asking first if something typed is not saved.
  useOpenFromNotice()
  // Today's attendance, read once for the card and the call-out: while the call-out asks, nothing else is asked.
  const mine = useMyToday()
  const checkInPutAway = useCheckInPutAway(user?.id)
  // The call-out is asking this person to check in, or is about to: until their day is known, it counts as asking.
  // Once they are in, their day is done, or they put it away for today, it is not.
  const checkInAsks = Boolean(user?.abilities?.['wf:self:view']) && (mine === null || (checkInDue(mine) && !checkInPutAway))
  // A device is noted for whoever is signed in, if they switched notifications on here; nobody is asked anything here.
  const userId = user?.id
  useEffect(() => {
    if (user) void syncPush(user)
    // Only when a different person signs in, not each time their details are read again.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [userId])
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
  const ownOnly = user ? ownRecordOnly(user) : true
  useEffect(() => {
    void keepPrintPrefsFresh()
  }, [])

  // The browser tab says which part of the app it is showing, so several open tabs can be told apart.
  useEffect(() => {
    const section = user ? (pathname.startsWith('/account') ? 'My account' : pathname.startsWith('/patients') ? 'Patients' : allowedTabs(user).find((t) => onTab(t, pathname))?.label) : undefined
    document.title = section ? `${section} · Prasanthi HMS` : 'Prasanthi HMS'
  }, [pathname, user])

  if (!user) return null
  // An own-record login has two places: its own record and its own account. Any other address goes home.
  if (ownOnly && !pathname.startsWith('/workforce/me') && !pathname.startsWith('/account')) return <Navigate to={homeFor(user)} replace />

  return (
    <div className="app">
      <header className={open ? 'topbar open' : 'topbar'}>
        <div className="brand">
          <BrandMark />
          <span className="brand-name">
            Prasanthi <span>HMS</span>
          </span>
        </div>
        {/* On phones the menu is opened by "More" on the bar at the foot and covers the screen, so close it once a page is chosen. */}
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
              {NAV_ICONS[t.key] && (
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <path d={NAV_ICONS[t.key]} />
                </svg>
              )}
              {t.label}
              {t.key === 'todo' && todoCount > 0 && (
                <span className="nav-count" title={`${todoCount} of your tasks are due today or late`}>
                  {todoCount}
                </span>
              )}
            </NavLink>
          ))}
          {/* On a phone the picture at the top is not shown, and the bar at the foot may not have Profile on it:
              the full menu always does, so a person can never lose the way to their own page. */}
          <NavLink to="/account" className={pathname === '/account' ? 'nav-profile active' : 'nav-profile'}>
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d={NAV_ICONS.profile} />
            </svg>
            My profile
          </NavLink>
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
        {!ownOnly && <PatientSearch />}
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
        <InstallHint />
        {/* Each person the hospital can notify is offered it on this device, on their first screen: one ask at a time,
            so not while the call-out is asking them to check in. */}
        {pathname === homeFor(user) && getsNotices(user) && !checkInAsks && <PushHint />}
        {/* A person's first screen opens with their own attendance and the button to check in or out. */}
        {pathname === homeFor(user) && user.abilities?.['wf:self:view'] && <AttendanceCard />}
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
      <BottomBar menuOpen={open} onMenu={setOpen} />
      {/* On opening the app, someone who has not checked in today is asked to, before anything else. */}
      {user.abilities?.['wf:self:view'] && <CheckInPrompt />}
    </div>
  )
}
