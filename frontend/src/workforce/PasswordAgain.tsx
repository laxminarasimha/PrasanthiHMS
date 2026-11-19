import { useState, type FormEvent } from 'react'
import type { PasswordAsk } from './sensitiveStep'

/** The small window that asks for the password again before a sensitive step. Shown by `usePasswordAgain`. */
export function PasswordAgain({ ask }: { ask: PasswordAsk }) {
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  if (!ask.open) return null

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    const problem = await ask.confirm(password)
    setBusy(false)
    setError(problem)
    if (!problem) setPassword('')
  }
  const cancel = () => {
    setPassword('')
    setError('')
    ask.cancel()
  }

  return (
    <div className="modal-back" onMouseDown={(e) => e.target === e.currentTarget && cancel()}>
      <form className="modal slim" role="dialog" aria-modal="true" aria-label="Type your password again" onSubmit={submit}>
        <div className="panel-head">
          <h2>Type your password again</h2>
        </div>
        <p className="muted">This step is kept behind your password, so it cannot be done from a screen someone left signed in. It then stays open for ten minutes.</p>
        <label className="field">
          <span className="field-label">Your password</span>
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoFocus autoComplete="current-password" />
          {error && <span className="field-error">{error}</span>}
        </label>
        <div className="panel-foot row">
          <button type="button" className="btn ghost" onClick={cancel}>
            Cancel
          </button>
          <button className="btn primary" disabled={busy || !password}>
            {busy ? 'Checking…' : 'Continue'}
          </button>
        </div>
      </form>
    </div>
  )
}
