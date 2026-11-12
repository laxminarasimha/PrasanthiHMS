import { PharmacyTabs } from '../../components/PharmacyTabs'
import { ReportScreen } from '../../components/ReportScreen'

/** Every pharmacy report on one screen. */
export function PharmacyReports() {
  return (
    <>
      <PharmacyTabs />
      <ReportScreen base="/pharmacy/reports" first="billing" firstLabel="Billing report" />
    </>
  )
}
