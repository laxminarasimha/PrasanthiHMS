import type { ReactNode } from 'react'
import { SIZE_LABEL, type Paper, type PaperSize } from '../printPaper'
import { ClinicFooter, ClinicHeader, useClinic } from './ClinicHeader'

/** The strip above a bill on screen (never printed): which paper it is going on, and a button to print again. */
export function PaperBar({ paper }: { paper: Paper }) {
  const thermal = paper.size === 'thermal'
  return (
    <div className="paper-bar no-print">
      {paper.sizes.length > 1 && (
        <label className="paper-size">
          <span className="muted">Paper</span>
          <select value={paper.size} onChange={(e) => paper.chooseSize(e.target.value as PaperSize)} aria-label="Paper size">
            {paper.sizes.map((size) => (
              <option key={size} value={size}>
                {SIZE_LABEL[size]}
              </option>
            ))}
          </select>
        </label>
      )}
      {!thermal && (
        <>
          <span className="muted">Printing on</span>
          <div className="tabs flush">
            <button type="button" className={paper.letterhead ? '' : 'active'} onClick={() => paper.choose(false)}>
              Plain paper
            </button>
            <button type="button" className={paper.letterhead ? 'active' : ''} onClick={() => paper.choose(true)}>
              Letterhead
            </button>
          </div>
        </>
      )}
      <span className="muted small grow">
        {thermal
          ? 'A narrow receipt for an 80 mm roll. Choose the thermal printer in the print window.'
          : paper.letterhead
            ? 'Hospital details and logo are left out; the letterhead has them.'
            : 'Hospital details and logo are printed at the top.'}
      </span>
      <button type="button" className="btn primary" onClick={() => window.print()}>
        Print
      </button>
    </div>
  )
}

/** The page the printer is told to use, for a size other than a full sheet. */
function PageSize({ paper }: { paper: Paper }) {
  if (paper.size === 'thermal') return <style>{`@media print { @page { size: 80mm auto; margin: 0; } }`}</style>
  if (paper.size === 'a5') return <style>{`@media print { @page { size: A5; ${paper.letterhead ? '' : 'margin: 9mm;'} } }`}</style>
  return null
}

/** The top of a bill: the hospital's heading on plain paper, or room for the printed letterhead and just the title. */
export function PaperHeader({ paper, suffix, children }: { paper: Paper; suffix?: string; children: ReactNode }) {
  if (paper.size === 'thermal') {
    // A roll is too narrow for a logo beside the name or for a letterhead picture: everything is centred and small.
    return (
      <>
        <PageSize paper={paper} />
        <ClinicHeader suffix={suffix} layout="centre" size="small" logoSize="small">
          {children}
        </ClinicHeader>
      </>
    )
  }
  if (!paper.letterhead) {
    return (
      <>
        <PageSize paper={paper} />
        <ClinicHeader suffix={suffix} layout={paper.heading} size={paper.headingSize} logoSize={paper.logoSize}>
          {children}
        </ClinicHeader>
      </>
    )
  }
  return (
    <>
      <PageSize paper={paper} />
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
  return paper.letterhead ? null : <ClinicFooter plain={paper.size === 'thermal'} />
}

/**
 * The hospital's GST number, as a line under the bill number. It is printed on letterhead paper too, since not
 * every letterhead carries it; the hospital can switch it off under Setup → Billing preferences.
 */
export function HospitalGstin({ paper, label = 'GSTIN' }: { paper: Paper; label?: string }) {
  const clinic = useClinic()
  if (!paper.gstin || !clinic?.gstin) return null
  return (
    <>
      <br />
      {label}: {clinic.gstin}
    </>
  )
}
