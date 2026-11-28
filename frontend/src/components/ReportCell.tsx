import type { ReactNode } from 'react'
import type { ReportLink } from '../types'
import { DoctorLink } from './DoctorLink'
import { PatientLink } from './PatientLink'
import { BillLink, IpdLink, LabOrderLink } from './RecordLinks'

/** A report cell that names a record opens it, for a reader whose work opens it; otherwise it is the words alone. */
export function ReportCell({ link, children }: { link?: ReportLink; children: ReactNode }) {
  if (!link) return <>{children}</>
  switch (link.kind) {
    case 'bill':
      return <BillLink id={link.id}>{children}</BillLink>
    case 'pharmacy_bill':
      return (
        <BillLink id={link.id} pharmacy>
          {children}
        </BillLink>
      )
    case 'lab_order':
      return <LabOrderLink id={link.id}>{children}</LabOrderLink>
    case 'ipd':
      return <IpdLink id={link.id}>{children}</IpdLink>
    case 'patient':
      return <PatientLink id={link.id}>{children}</PatientLink>
    case 'doctor':
      return <DoctorLink id={link.id}>{children}</DoctorLink>
  }
}
