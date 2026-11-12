import { Link } from 'react-router-dom'
import { ReportScreen } from '../../components/ReportScreen'

/** The IPD reports: cases, bed usage and payments. */
export function IpdReports() {
  return (
    <ReportScreen
      base="/ipd/reports"
      first="cases"
      firstLabel="Case report"
      lead={
        <Link className="btn ghost" to="/ipd">
          ← Cases
        </Link>
      }
    />
  )
}
