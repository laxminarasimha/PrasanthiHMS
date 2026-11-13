import type { ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { homeFor } from './access'
import { AuthProvider, useAuth } from './auth'
import { Layout } from './components/Layout'
import { BillPrint } from './pages/BillPrint'
import { DoctorConsults } from './pages/DoctorConsults'
import { DoctorLibrary } from './pages/DoctorLibrary'
import { DoctorQueue } from './pages/DoctorQueue'
import { Calendar } from './pages/Calendar'
import { FrontDesk } from './pages/FrontDesk'
import { Admin } from './pages/Admin'
import { AllBills } from './pages/AllBills'
import { FormPrint } from './pages/FormPrint'
import { Ipd } from './pages/ipd/Ipd'
import { IpdCase } from './pages/ipd/IpdCase'
import { IpdPrint } from './pages/ipd/IpdPrint'
import { IpdReceiptPrint } from './pages/ipd/IpdReceiptPrint'
import { IpdReports } from './pages/ipd/IpdReports'
import { IpdSummaryPrint } from './pages/ipd/IpdSummaryPrint'
import { IpdSetup } from './pages/ipd/IpdSetup'
import { Lab } from './pages/Lab'
import { LabReportPrint } from './pages/LabReportPrint'
import { LabPackages } from './pages/LabPackages'
import { LabSettings } from './pages/LabSettings'
import { LabTests } from './pages/LabTests'
import { Login } from './pages/Login'
import { PatientDashboard } from './pages/PatientDashboard'
import { Patients } from './pages/Patients'
import { PharmacyBilling } from './pages/pharmacy/Billing'
import { PharmacyBills } from './pages/pharmacy/Bills'
import { PharmacyDashboard } from './pages/pharmacy/Dashboard'
import { PharmacyInventory } from './pages/pharmacy/Inventory'
import { PharmacyMedicines } from './pages/pharmacy/Medicines'
import { PharmacyOpeningStock } from './pages/pharmacy/OpeningStock'
import { PharmacyOrders } from './pages/pharmacy/Orders'
import { PharmacyPurchaseOrders } from './pages/pharmacy/PurchaseOrders'
import { PharmacyPurchases } from './pages/pharmacy/Purchases'
import { PharmacyReports } from './pages/pharmacy/Reports'
import { PharmacySalePrint } from './pages/pharmacy/SalePrint'
import { PharmacyStock } from './pages/pharmacy/Stock'
import { PharmacyStockReturns } from './pages/pharmacy/StockReturns'
import { PrescriptionPrint } from './pages/PrescriptionPrint'
import { QueueBoard } from './pages/QueueBoard'
import { Reports } from './pages/Reports'
import { Services } from './pages/Services'
import { Staff } from './pages/Staff'
import { VisitPad } from './pages/VisitPad'
import type { User } from './types'

function Protected() {
  const { user, loading } = useAuth()
  if (loading) return <p className="loading">Loading…</p>
  return user ? <Layout /> : <Navigate to="/login" replace />
}

function PrintOnly({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth()
  if (loading) return <p className="loading">Loading…</p>
  return user ? children : <Navigate to="/login" replace />
}

/** Shows a page only to roles with the given ability; everyone else goes to their own home screen. */
function Only({ when, children }: { when: keyof User; children: ReactNode }) {
  const { user } = useAuth()
  if (!user) return null
  return user[when] ? children : <Navigate to={homeFor(user)} replace />
}

function Home() {
  const { user } = useAuth()
  return <Navigate to={user ? homeFor(user) : '/login'} replace />
}

export default function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/queue" element={<PrintOnly><QueueBoard /></PrintOnly>} />
          <Route path="/bills/:id/print" element={<PrintOnly><BillPrint /></PrintOnly>} />
          <Route path="/visits/:id/print" element={<PrintOnly><PrescriptionPrint /></PrintOnly>} />
          <Route path="/lab/orders/:id/print" element={<PrintOnly><LabReportPrint /></PrintOnly>} />
          <Route path="/forms/:id/print" element={<PrintOnly><FormPrint /></PrintOnly>} />
          <Route path="/ipd/:id/print" element={<PrintOnly><IpdPrint /></PrintOnly>} />
          <Route path="/ipd/:id/receipt/:paymentId" element={<PrintOnly><IpdReceiptPrint /></PrintOnly>} />
          <Route path="/ipd/:id/summary/print" element={<PrintOnly><IpdSummaryPrint /></PrintOnly>} />
          <Route path="/pharmacy/bills/:id/print" element={<PrintOnly><PharmacySalePrint /></PrintOnly>} />
          <Route element={<Protected />}>
            <Route path="/front-desk" element={<Only when="can_front_desk"><FrontDesk /></Only>} />
            <Route path="/front-desk/bills" element={<Only when="can_front_desk"><AllBills /></Only>} />
            <Route path="/front-desk/calendar" element={<Only when="can_front_desk"><Calendar /></Only>} />
            <Route path="/doctor" element={<Only when="can_consult"><DoctorQueue /></Only>} />
            <Route path="/doctor/consults" element={<Only when="can_consult"><DoctorConsults /></Only>} />
            <Route path="/doctor/library" element={<Only when="can_consult"><DoctorLibrary /></Only>} />
            <Route path="/visits/:id" element={<Only when="can_consult"><VisitPad /></Only>} />
            <Route path="/lab" element={<Lab />} />
            <Route path="/lab/tests" element={<LabTests />} />
            <Route path="/lab/packages" element={<LabPackages />} />
            <Route path="/lab/settings" element={<LabSettings />} />
            <Route path="/pharmacy" element={<Only when="can_run_pharmacy"><PharmacyBilling /></Only>} />
            <Route path="/pharmacy/bills" element={<Only when="can_run_pharmacy"><PharmacyBills /></Only>} />
            <Route path="/pharmacy/dashboard" element={<Only when="can_run_pharmacy"><PharmacyDashboard /></Only>} />
            <Route path="/pharmacy/orders" element={<Only when="can_run_pharmacy"><PharmacyOrders /></Only>} />
            <Route path="/pharmacy/inventory" element={<Only when="can_run_pharmacy"><PharmacyInventory /></Only>} />
            <Route path="/pharmacy/opening-stock" element={<Only when="can_run_pharmacy"><PharmacyOpeningStock /></Only>} />
            <Route path="/pharmacy/stock-returns" element={<Only when="can_run_pharmacy"><PharmacyStockReturns /></Only>} />
            <Route path="/pharmacy/purchase-orders" element={<Only when="can_run_pharmacy"><PharmacyPurchaseOrders /></Only>} />
            <Route path="/pharmacy/reports" element={<Only when="can_run_pharmacy"><PharmacyReports /></Only>} />
            <Route path="/pharmacy/stock" element={<Only when="can_run_pharmacy"><PharmacyStock /></Only>} />
            <Route path="/pharmacy/purchases" element={<Only when="can_run_pharmacy"><PharmacyPurchases /></Only>} />
            <Route path="/pharmacy/medicines" element={<Only when="can_run_pharmacy"><PharmacyMedicines /></Only>} />
            <Route path="/ipd" element={<Only when="can_ipd"><Ipd /></Only>} />
            <Route path="/ipd/reports" element={<Only when="can_ipd"><IpdReports /></Only>} />
            <Route path="/ipd/setup" element={<Only when="can_manage_setup"><IpdSetup /></Only>} />
            <Route path="/ipd/:id" element={<Only when="can_ipd"><IpdCase /></Only>} />
            <Route path="/patients" element={<Patients />} />
            <Route path="/patients/:id" element={<PatientDashboard />} />
            <Route path="/admin" element={<Only when="can_manage_setup"><Admin /></Only>} />
            <Route path="/services" element={<Only when="can_manage_setup"><Services /></Only>} />
            <Route path="/staff" element={<Only when="can_manage_staff"><Staff /></Only>} />
            <Route path="/reports" element={<Only when="can_view_reports"><Reports /></Only>} />
          </Route>
          <Route path="*" element={<Protected />}>
            <Route path="*" element={<Home />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}
