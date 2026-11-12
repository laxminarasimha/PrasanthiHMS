import type { ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from './auth'
import { Layout } from './components/Layout'
import { BillPrint } from './pages/BillPrint'
import { DoctorQueue } from './pages/DoctorQueue'
import { FrontDesk } from './pages/FrontDesk'
import { Ipd } from './pages/ipd/Ipd'
import { IpdCase } from './pages/ipd/IpdCase'
import { IpdPrint } from './pages/ipd/IpdPrint'
import { IpdSetup } from './pages/ipd/IpdSetup'
import { Lab } from './pages/Lab'
import { LabReportPrint } from './pages/LabReportPrint'
import { LabTests } from './pages/LabTests'
import { Login } from './pages/Login'
import { Patients } from './pages/Patients'
import { PharmacyBilling } from './pages/pharmacy/Billing'
import { PharmacyBills } from './pages/pharmacy/Bills'
import { PharmacyMedicines } from './pages/pharmacy/Medicines'
import { PharmacyPurchases } from './pages/pharmacy/Purchases'
import { PharmacySalePrint } from './pages/pharmacy/SalePrint'
import { PharmacyStock } from './pages/pharmacy/Stock'
import { PrescriptionPrint } from './pages/PrescriptionPrint'
import { Reports } from './pages/Reports'
import { Services } from './pages/Services'
import { Staff } from './pages/Staff'
import { VisitPad } from './pages/VisitPad'

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

function StaffOnly({ children }: { children: ReactNode }) {
  const { user } = useAuth()
  return user?.can_manage_staff ? children : <Navigate to="/patients" replace />
}

export default function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/bills/:id/print" element={<PrintOnly><BillPrint /></PrintOnly>} />
          <Route path="/visits/:id/print" element={<PrintOnly><PrescriptionPrint /></PrintOnly>} />
          <Route path="/lab/orders/:id/print" element={<PrintOnly><LabReportPrint /></PrintOnly>} />
          <Route path="/ipd/:id/print" element={<PrintOnly><IpdPrint /></PrintOnly>} />
          <Route path="/pharmacy/bills/:id/print" element={<PrintOnly><PharmacySalePrint /></PrintOnly>} />
          <Route element={<Protected />}>
            <Route path="/front-desk" element={<FrontDesk />} />
            <Route path="/doctor" element={<DoctorQueue />} />
            <Route path="/visits/:id" element={<VisitPad />} />
            <Route path="/lab" element={<Lab />} />
            <Route path="/lab/tests" element={<LabTests />} />
            <Route path="/pharmacy" element={<PharmacyBilling />} />
            <Route path="/pharmacy/bills" element={<PharmacyBills />} />
            <Route path="/pharmacy/stock" element={<PharmacyStock />} />
            <Route path="/pharmacy/purchases" element={<PharmacyPurchases />} />
            <Route path="/pharmacy/medicines" element={<PharmacyMedicines />} />
            <Route path="/ipd" element={<Ipd />} />
            <Route path="/ipd/setup" element={<IpdSetup />} />
            <Route path="/ipd/:id" element={<IpdCase />} />
            <Route path="/patients" element={<Patients />} />
            <Route path="/services" element={<Services />} />
            <Route path="/staff" element={<StaffOnly><Staff /></StaffOnly>} />
            <Route path="/reports" element={<StaffOnly><Reports /></StaffOnly>} />
          </Route>
          <Route path="*" element={<Navigate to="/front-desk" replace />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}
