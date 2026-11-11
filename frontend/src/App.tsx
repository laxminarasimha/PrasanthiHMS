import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from './auth'
import { Layout } from './components/Layout'
import { Login } from './pages/Login'
import { Patients } from './pages/Patients'
import { Staff } from './pages/Staff'

function Protected() {
  const { user, loading } = useAuth()
  if (loading) return <p className="loading">Loading…</p>
  return user ? <Layout /> : <Navigate to="/login" replace />
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
          <Route element={<Protected />}>
            <Route path="/patients" element={<Patients />} />
            <Route path="/staff" element={<StaffOnly />} />
          </Route>
          <Route path="*" element={<Navigate to="/patients" replace />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}
