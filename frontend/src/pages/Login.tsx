import { useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { fieldErrors } from '../api'
import { homeFor } from '../access'
import { useAuth } from '../auth'
import { BrandMark } from '../components/Brand'
import { TextField } from '../components/Field'
import { GoogleButton } from '../components/GoogleButton'
import { useBrand } from '../brand'

export function Login() {
  const { user, signIn, signInWithGoogle } = useAuth()
  const brand = useBrand()
  const [login, setLogin] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  if (user) return <Navigate to={homeFor(user)} replace />

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError('')
    try {
      await signIn(login.trim(), password)
    } catch (err) {
      const errors = fieldErrors(err)
      setError(errors.login ?? errors.email ?? errors.password ?? errors._)
    } finally {
      setBusy(false)
    }
  }

  const google = async (credential: string) => {
    setBusy(true)
    setError('')
    try {
      await signInWithGoogle(credential)
    } catch (err) {
      const errors = fieldErrors(err)
      setError(errors.credential ?? errors._)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="login">
      <form className="card login-card" onSubmit={submit}>
        <BrandMark large />
        <span className="eyebrow">Prasanthi Hospitals · Vijayawada</span>
        <div className="brand large">
          Prasanthi <span>HMS</span>
        </div>
        <p className="muted">Multi Speciality Centre. Sign in with your staff account.</p>
        <TextField label="Mobile number or email" type="text" inputMode="email" autoCapitalize="none" autoCorrect="off" spellCheck={false} value={login} onChange={setLogin} autoComplete="username" required />
        <TextField
          label="Password or PIN"
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
        {brand?.google_client_id && (
          <>
            <div className="or-line">
              <span>or</span>
            </div>
            <GoogleButton clientId={brand.google_client_id} onCredential={google} />
          </>
        )}
      </form>
    </div>
  )
}
