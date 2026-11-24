import { useEffect, useRef, useState, type ReactNode } from 'react'
import { NavLink, useLocation } from 'react-router-dom'

/** One entry on the strip: its name and the pages under it, each as [address, name]. */
export type TabGroup = [string, [string, string][]]

/**
 * A section's own menu, on one line under the top bar. A group with several pages opens as a short list; the group
 * you are in is marked and names the page you are on. A group with one page is a plain link.
 */
export function GroupedTabs({ label, groups, exact = [], lead }: { label: string; groups: TabGroup[]; /** Addresses that are a page of their own and also the start of others. */ exact?: string[]; lead?: ReactNode }) {
  const { pathname } = useLocation()
  const [open, setOpen] = useState<string | null>(null)
  const bar = useRef<HTMLElement>(null)
  // With a mouse the lists open as the pointer passes; on a touch screen they open on a tap.
  const hovers = useRef(typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia('(hover: hover)').matches)

  useEffect(() => {
    if (!open) return
    const close = (e: Event) => {
      if (e.type === 'keydown' ? (e as KeyboardEvent).key === 'Escape' : !bar.current?.contains(e.target as Node)) setOpen(null)
    }
    document.addEventListener('click', close)
    document.addEventListener('keydown', close)
    return () => {
      document.removeEventListener('click', close)
      document.removeEventListener('keydown', close)
    }
  }, [open])

  const on = (to: string) => (exact.includes(to) ? pathname === to : pathname === to || pathname.startsWith(to + '/'))

  return (
    <nav className="subnav grouped no-print" ref={bar} aria-label={label}>
      {lead}
      {groups.map(([group, links]) => {
        if (links.length === 1) {
          return (
            <NavLink key={group} to={links[0][0]} end={exact.includes(links[0][0])}>
              {links[0][1]}
            </NavLink>
          )
        }
        const current = links.find(([to]) => on(to))
        const isOpen = open === group
        return (
          <div key={group} className="subnav-menu" onMouseEnter={() => hovers.current && setOpen(group)} onMouseLeave={() => hovers.current && setOpen(null)}>
            <button type="button" className={current ? 'active' : ''} aria-haspopup="menu" aria-expanded={isOpen} onClick={() => setOpen(hovers.current || !isOpen ? group : null)}>
              {group}
              {current && current[1] !== group && <small> · {current[1]}</small>}
              <span className="caret" aria-hidden="true">
                ▾
              </span>
            </button>
            {isOpen && (
              <div className="subnav-drop" role="menu">
                {links.map(([to, name]) => (
                  <NavLink key={to} to={to} end={exact.includes(to)} role="menuitem" onClick={() => setOpen(null)}>
                    {name}
                  </NavLink>
                ))}
              </div>
            )}
          </div>
        )
      })}
    </nav>
  )
}
