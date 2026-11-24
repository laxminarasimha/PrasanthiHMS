import { Link, useLocation } from 'react-router-dom'
import { useAuth } from '../auth'
import { activeBottom, bottomTabs } from '../nav'
import { NAV_ICONS } from '../navIcons'
import { canLeave } from '../unsaved'

function Icon({ name }: { name: string }) {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path d={NAV_ICONS[name] ?? NAV_ICONS.more} />
    </svg>
  )
}

/**
 * The bar at the foot of a phone's screen, as in a phone app: the few places this person goes most, and "More" for
 * the full menu. It is not shown on a computer, where the menu along the top does the same job.
 */
export function BottomBar({ menuOpen, onMenu }: { menuOpen: boolean; onMenu: (open: boolean) => void }) {
  const { user } = useAuth()
  const { pathname } = useLocation()
  if (!user) return null
  const bar = bottomTabs(user)
  // With the menu open, or on a page that is only in the menu, "More" is the one lit.
  const active = menuOpen ? 'more' : (activeBottom(bar, pathname) ?? 'more')

  return (
    <nav className="bottom-bar" aria-label="Main sections">
      {bar.map((t) => (
        <Link
          key={t.key}
          to={t.to}
          className={active === t.key ? 'active' : undefined}
          aria-current={active === t.key ? 'page' : undefined}
          onClick={(e) => {
            // Notes typed but not saved are asked about first, as with the menu.
            if (!canLeave()) return e.preventDefault()
            onMenu(false)
          }}
        >
          <Icon name={t.key} />
          <span>{t.label}</span>
        </Link>
      ))}
      <button type="button" className={active === 'more' ? 'active' : undefined} aria-expanded={menuOpen} onClick={() => onMenu(!menuOpen)}>
        <Icon name="more" />
        <span>More</span>
      </button>
    </nav>
  )
}
