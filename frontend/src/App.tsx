import { lazy, Suspense, useEffect } from 'react'
import type { ComponentType, ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { homeFor } from './access'
import { AuthProvider, useAuth } from './auth'
import { Layout } from './components/Layout'
import { Login } from './pages/Login'
import type { User } from './types'

/** Pages still to be fetched, in the order they are listed below. */
const waiting: Array<() => Promise<unknown>> = []

function LoadFailed() {
  return (
    <p className="loading">
      This page could not be opened. Check the internet connection, then{' '}
      <button type="button" className="link" onClick={() => window.location.reload()}>try again</button>.
    </p>
  )
}

/**
 * A page that is fetched the first time it is opened instead of with the sign-in screen,
 * so the first screen does not wait for every other screen in the hospital.
 */
function page<M extends Record<K, ComponentType>, K extends string>(load: () => Promise<M>, name: K) {
  waiting.push(load)
  return lazy(() =>
    load().then(
      (m) => ({ default: m[name] as ComponentType }),
      () => {
        // After a new release the old file names are gone: loading the page afresh picks up the new ones.
        const last = Number(sessionStorage.getItem('hms_reloaded') ?? 0)
        if (navigator.onLine && Date.now() - last > 60_000) {
          sessionStorage.setItem('hms_reloaded', String(Date.now()))
          window.location.reload()
          return new Promise<never>(() => {})
        }
        return { default: LoadFailed }
      },
    ),
  )
}

const BillPrint = page(() => import('./pages/BillPrint'), 'BillPrint')
const DoctorConsults = page(() => import('./pages/DoctorConsults'), 'DoctorConsults')
const DoctorLibrary = page(() => import('./pages/DoctorLibrary'), 'DoctorLibrary')
const DoctorQueue = page(() => import('./pages/DoctorQueue'), 'DoctorQueue')
const Calendar = page(() => import('./pages/Calendar'), 'Calendar')
const FrontDesk = page(() => import('./pages/FrontDesk'), 'FrontDesk')
const Account = page(() => import('./pages/Account'), 'Account')
const Admin = page(() => import('./pages/Admin'), 'Admin')
const AllBills = page(() => import('./pages/AllBills'), 'AllBills')
const FormPrint = page(() => import('./pages/FormPrint'), 'FormPrint')
const Ipd = page(() => import('./pages/ipd/Ipd'), 'Ipd')
const IpdCase = page(() => import('./pages/ipd/IpdCase'), 'IpdCase')
const IpdChartPrint = page(() => import('./pages/ipd/IpdChartPrint'), 'IpdChartPrint')
const IpdPrint = page(() => import('./pages/ipd/IpdPrint'), 'IpdPrint')
const IpdReceiptPrint = page(() => import('./pages/ipd/IpdReceiptPrint'), 'IpdReceiptPrint')
const IpdReports = page(() => import('./pages/ipd/IpdReports'), 'IpdReports')
const IpdSummaryPrint = page(() => import('./pages/ipd/IpdSummaryPrint'), 'IpdSummaryPrint')
const IpdSetup = page(() => import('./pages/ipd/IpdSetup'), 'IpdSetup')
const Lab = page(() => import('./pages/Lab'), 'Lab')
const LabReportPrint = page(() => import('./pages/LabReportPrint'), 'LabReportPrint')
const LabPackages = page(() => import('./pages/LabPackages'), 'LabPackages')
const LabSettings = page(() => import('./pages/LabSettings'), 'LabSettings')
const LabTests = page(() => import('./pages/LabTests'), 'LabTests')
const CaseHistory = page(() => import('./pages/CaseHistory'), 'CaseHistory')
const DocumentPrint = page(() => import('./pages/DocumentPrint'), 'DocumentPrint')
const PatientDashboard = page(() => import('./pages/PatientDashboard'), 'PatientDashboard')
const Patients = page(() => import('./pages/Patients'), 'Patients')
const PharmacyBilling = page(() => import('./pages/pharmacy/Billing'), 'PharmacyBilling')
const PharmacyBills = page(() => import('./pages/pharmacy/Bills'), 'PharmacyBills')
const PharmacyDashboard = page(() => import('./pages/pharmacy/Dashboard'), 'PharmacyDashboard')
const PharmacyInventory = page(() => import('./pages/pharmacy/Inventory'), 'PharmacyInventory')
const PharmacyMedicines = page(() => import('./pages/pharmacy/Medicines'), 'PharmacyMedicines')
const PharmacyOpeningStock = page(() => import('./pages/pharmacy/OpeningStock'), 'PharmacyOpeningStock')
const PharmacyOrders = page(() => import('./pages/pharmacy/Orders'), 'PharmacyOrders')
const PharmacySubscriptions = page(() => import('./pages/pharmacy/Subscriptions'), 'PharmacySubscriptions')
const PharmacyPurchaseOrders = page(() => import('./pages/pharmacy/PurchaseOrders'), 'PharmacyPurchaseOrders')
const PharmacyPurchases = page(() => import('./pages/pharmacy/Purchases'), 'PharmacyPurchases')
const PharmacyReports = page(() => import('./pages/pharmacy/Reports'), 'PharmacyReports')
const PharmacySalePrint = page(() => import('./pages/pharmacy/SalePrint'), 'PharmacySalePrint')
const PharmacyStock = page(() => import('./pages/pharmacy/Stock'), 'PharmacyStock')
const PharmacyStockReturns = page(() => import('./pages/pharmacy/StockReturns'), 'PharmacyStockReturns')
const PrescriptionPrint = page(() => import('./pages/PrescriptionPrint'), 'PrescriptionPrint')
const QueueBoard = page(() => import('./pages/QueueBoard'), 'QueueBoard')
const Reports = page(() => import('./pages/Reports'), 'Reports')
const Services = page(() => import('./pages/Services'), 'Services')
const Staff = page(() => import('./pages/Staff'), 'Staff')
const VisitPad = page(() => import('./pages/VisitPad'), 'VisitPad')

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

const loading = <p className="loading">Loading…</p>

export default function App() {
  // Once the first screen is up, quietly fetch the rest one by one, so moving between screens does not wait.
  useEffect(() => {
    const timer = window.setTimeout(() => {
      void waiting.reduce((before, load) => before.then(load).then(() => {}, () => {}), Promise.resolve())
    }, 2500)
    return () => window.clearTimeout(timer)
  }, [])

  return (
    <AuthProvider>
      <BrowserRouter>
        <Suspense fallback={loading}>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/queue" element={<PrintOnly><QueueBoard /></PrintOnly>} />
          <Route path="/bills/:id/print" element={<PrintOnly><BillPrint /></PrintOnly>} />
          <Route path="/visits/:id/print" element={<PrintOnly><PrescriptionPrint /></PrintOnly>} />
          <Route path="/lab/orders/:id/print" element={<PrintOnly><LabReportPrint /></PrintOnly>} />
          <Route path="/forms/:id/print" element={<PrintOnly><FormPrint /></PrintOnly>} />
          <Route path="/documents/:id/print" element={<PrintOnly><DocumentPrint /></PrintOnly>} />
          <Route path="/ipd/:id/print" element={<PrintOnly><IpdPrint /></PrintOnly>} />
          <Route path="/ipd/:id/bills/:billId/print" element={<PrintOnly><IpdPrint /></PrintOnly>} />
          <Route path="/ipd/:id/receipt/:paymentId" element={<PrintOnly><IpdReceiptPrint /></PrintOnly>} />
          <Route path="/ipd/:id/summary/print" element={<PrintOnly><IpdSummaryPrint /></PrintOnly>} />
          <Route path="/ipd/:id/chart/print" element={<PrintOnly><IpdChartPrint /></PrintOnly>} />
          <Route path="/pharmacy/bills/:id/print" element={<PrintOnly><PharmacySalePrint /></PrintOnly>} />
          <Route element={<Protected />}>
            <Route path="/account" element={<Account />} />
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
            <Route path="/pharmacy/subscriptions" element={<Only when="can_run_pharmacy"><PharmacySubscriptions /></Only>} />
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
            <Route path="/patients/:id/history" element={<CaseHistory />} />
            <Route path="/admin" element={<Only when="can_manage_setup"><Admin /></Only>} />
            <Route path="/services" element={<Only when="can_manage_setup"><Services /></Only>} />
            <Route path="/staff" element={<Only when="can_manage_staff"><Staff /></Only>} />
            <Route path="/reports" element={<Only when="can_view_reports"><Reports /></Only>} />
          </Route>
          <Route path="*" element={<Protected />}>
            <Route path="*" element={<Home />} />
          </Route>
        </Routes>
        </Suspense>
      </BrowserRouter>
    </AuthProvider>
  )
}
