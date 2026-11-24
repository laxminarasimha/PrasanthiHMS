import { useState, type ReactNode } from 'react'
import { useAuth } from '../auth'
import { PersonCard } from './PersonCard'

/**
 * A member of staff's name, wherever it is shown: one click opens everything about them in a window over the page,
 * and closing it leaves the page as it was. For someone who may not see staff records the name is plain text.
 */
export function PersonLink({ id, children, onChanged }: { id: number | null | undefined; children: ReactNode; /** After a check-in or check-out made in the window. */ onChanged?: () => void }) {
  const { user } = useAuth()
  const [open, setOpen] = useState(false)
  if (!id || !user?.abilities?.['wf:staff:view']) return <>{children}</>
  return (
    <>
      <button
        type="button"
        className="person-link"
        title="Open this person: contact, today, this month and leave"
        // A row that opens something itself must not also fire when the name is the thing clicked.
        onClick={(e) => {
          e.stopPropagation()
          setOpen(true)
        }}
      >
        {children}
      </button>
      {open && <PersonCard id={id} onClose={() => setOpen(false)} onChanged={onChanged} />}
    </>
  )
}
