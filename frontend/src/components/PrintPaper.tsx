import type { ReactNode } from 'react'
import type { Paper } from '../printPaper'
import { ClinicFooter, ClinicHeader } from './ClinicHeader'

/** The strip above a bill on screen (never printed): which paper it is going on, and a button to print again. */
export function PaperBar({ paper }: { paper: Paper }) {
  return (
    <div className="print-bar no-print">
      <span className="muted">Printing on</span>
      <div className="tabs flush">
        <button type="button" className={paper.letterhead ? '' : 'active'} onClick={() => paper.choose(false)}>
          Plain paper
        </button>
        <button type="button" className={paper.letterhead ? 'active' : ''} onClick={() => paper.choose(true)}>
          Letterhead
        </button>
      </div>
      <span className="muted small grow">{paper.letterhead ? 'Hospital details and logo are left out; the letterhead has them.' : 'Hospital details and logo are printed at the top.'}</span>
      <button type="button" className="btn primary" onClick={() => window.print()}>
        Print
      </button>
    </div>
  )
}

/** The top of a bill: the hospital's heading on plain paper, or room for the printed letterhead and just the title. */
export function PaperHeader({ paper, suffix, children }: { paper: Paper; suffix?: string; children: ReactNode }) {
  if (!paper.letterhead) return <ClinicHeader suffix={suffix}>{children}</ClinicHeader>
  return (
    <>
      <style>{`@media print { @page { margin: ${paper.top}mm 12mm ${paper.bottom}mm; } }`}</style>
      <div className="letterhead-gap no-print" style={{ height: `${paper.top}mm` }}>
        Space left for the letterhead ({paper.top} mm)
      </div>
      <header className="on-letterhead">{children}</header>
    </>
  )
}

/** The hospital's own line at the foot, on plain paper only: a letterhead carries its own. */
export function PaperFooter({ paper }: { paper: Paper }) {
  return paper.letterhead ? null : <ClinicFooter />
}
