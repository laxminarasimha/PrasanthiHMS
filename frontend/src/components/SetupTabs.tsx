import { NavLink } from 'react-router-dom'
import { useAuth } from '../auth'
import { setupPages } from '../nav'

/** Links between the setup pages, shown at the top of each of them. */
export function SetupTabs() {
  const { user } = useAuth()
  const pages = user ? setupPages(user) : []
  if (pages.length < 2) return null
  return (
    <nav className="subnav no-print">
      {pages.map((p) => (
        <NavLink key={p.to} to={p.to}>
          {p.label}
        </NavLink>
      ))}
    </nav>
  )
}
