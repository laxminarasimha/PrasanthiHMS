import { useEffect, useRef, type FormEvent, type ReactNode } from 'react'

type Props = {
  title: ReactNode
  /** A line under the title: who or what this is about. */
  sub?: ReactNode
  onClose: () => void
  /** Given, the window is a form and Enter sends it. */
  onSubmit?: (e: FormEvent) => void
  /** "slim" for a short form (a reason, a date), "wide" for a long one. */
  size?: 'slim' | 'wide'
  children: ReactNode
  /** The buttons along the foot. */
  foot?: ReactNode
}

/**
 * A small window over the page, for a short step that needs the person's whole attention: a reason, a date, a
 * payment. It opens where the eye already is, the page behind stays as it was, and Escape or a click outside closes
 * it. A longer piece of work that is done beside a list stays in a side panel.
 */
export function Popup({ title, sub, onClose, onSubmit, size, children, foot }: Props) {
  const box = useRef<HTMLDivElement & HTMLFormElement>(null)
  // The latest close, without re-running the opening below each time the page draws (that would pull the cursor back).
  const close = useRef(onClose)
  useEffect(() => {
    close.current = onClose
  })
  useEffect(() => {
    // Once, on opening: into the first box to type in, or the window itself, so the keyboard is where the work is.
    const first = box.current?.querySelector<HTMLElement>('input:not([type=hidden]):not([disabled]), select, textarea')
    ;(first ?? box.current)?.focus()
    const key = (e: KeyboardEvent) => e.key === 'Escape' && close.current()
    window.addEventListener('keydown', key)
    return () => window.removeEventListener('keydown', key)
  }, [])

  const inner = (
    <>
      <div className="panel-head">
        <div>
          <h2>{title}</h2>
          {sub && <span className="muted">{sub}</span>}
        </div>
        <button type="button" className="btn ghost" onClick={onClose} aria-label="Close">
          Close
        </button>
      </div>
      {children}
      {foot && <div className="panel-foot row end wrap">{foot}</div>}
    </>
  )
  const cls = `modal popup ${size ?? ''}`
  return (
    <div className="modal-back" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      {onSubmit ? (
        <form className={cls} role="dialog" aria-modal="true" tabIndex={-1} ref={box} onSubmit={onSubmit} noValidate>
          {inner}
        </form>
      ) : (
        <div className={cls} role="dialog" aria-modal="true" tabIndex={-1} ref={box}>
          {inner}
        </div>
      )}
    </div>
  )
}
