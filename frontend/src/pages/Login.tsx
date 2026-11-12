import { useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { fieldErrors } from '../api'
import { homeFor } from '../access'
import { useAuth } from '../auth'
import { TextField } from '../components/Field'

export function Login() {
  const { user, signIn } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  if (user) return <Navigate to={homeFor(user)} replace />

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError('')
    try {
      await signIn(email, password)
    } catch (err) {
      const errors = fieldErrors(err)
      setError(errors.email ?? errors.password ?? errors._)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="login">
      <form className="card login-card" onSubmit={submit}>
        <div className="brand large">
          Prasanthi <span>HMS</span>
        </div>
        <p className="muted">Sign in with your staff account.</p>
        <TextField label="Email" type="email" value={email} onChange={setEmail} autoComplete="username" required />
        <TextField
          label="Password"
          type="password"
          value={password}
          onChange={setPassword}
          autoComplete="current-password"
          required
        />
        {error && <p className="form-error">{error}</p>}
        <button className="btn primary block" disabled={busy}>
          {busy ? 'Signing in…' : 'Sign in'}
        </button>
      </form>
    </div>
  )
}
