import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from './auth'
import { Layout } from './components/Layout'
import { BillPrint } from './pages/BillPrint'
import { FrontDesk } from './pages/FrontDesk'
import { Login } from './pages/Login'
import { Patients } from './pages/Patients'
import { Services } from './pages/Services'
import { Staff } from './pages/Staff'

function Protected() {
  const { user, loading } = useAuth()
  if (loading) return <p className="loading">Loading…</p>
  return user ? <Layout /> : <Navigate to="/login" replace />
}

function PrintOnly() {
  const { user, loading } = useAuth()
  if (loading) return <p className="loading">Loading…</p>
  return user ? <BillPrint /> : <Navigate to="/login" replace />
}

function StaffOnly() {
  const { user } = useAuth()
  return user?.can_manage_staff ? <Staff /> : <Navigate to="/patients" replace />
}

export default function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/bills/:id/print" element={<PrintOnly />} />
          <Route element={<Protected />}>
            <Route path="/front-desk" element={<FrontDesk />} />
            <Route path="/patients" element={<Patients />} />
            <Route path="/services" element={<Services />} />
            <Route path="/staff" element={<StaffOnly />} />
          </Route>
          <Route path="*" element={<Navigate to="/front-desk" replace />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}
