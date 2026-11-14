import { useEffect, useState } from 'react'
import { api } from '../api'
import { clock, longDate } from '../format'

type Account = { id: number; name: string; mobile: string; is_active: boolean; verified_at: string | null; last_login_at: string | null; registered_at: string }
type Reply = { account: Account | null; temporary_password?: string }

/** The front desk's handle on a patient's mobile app account: verify it, reset its password, switch it off. */
export function AppAccountBox({ patientId, canManage }: { patientId: number; canManage: boolean }) {
  const [account, setAccount] = useState<Account | null | undefined>(undefined)
  const [temporary, setTemporary] = useState('')
  const [error, setError] = useState('')
  const url = `/patients/${patientId}/app-account`

  useEffect(() => {
    api.get<Reply>(url).then((res) => setAccount(res.data.account)).catch(() => setAccount(null))
  }, [url])

  // Nothing to show for a patient who has not signed up in the app.
  if (!account) return null

  const run = async (path: string, body: object, confirmText?: string) => {
    if (confirmText && !window.confirm(confirmText)) return
    setError('')
    try {
      const res = await api.post<Reply>(`${url}/${path}`, body)
      setAccount(res.data.account)
      setTemporary(res.data.temporary_password ?? '')
    } catch {
      setError('Could not save. Try again.')
    }
  }

  return (
    <div className="app-account">
      <div className="row wrap">
        <strong>Mobile app account</strong>
        <span className="muted">
          {account.mobile} · registered {longDate(account.registered_at)}
          {account.last_login_at ? ` · last used ${longDate(account.last_login_at)} ${clock(account.last_login_at)}` : ''}
        </span>
        {!account.is_active && <span className="pill off">Switched off</span>}
        {account.verified_at ? <span className="pill ok">Verified</span> : <span className="pill status-arrived">Not verified</span>}
      </div>
      <p className="muted small">
        {account.verified_at
          ? 'This person can see their prescriptions and lab reports in the app.'
          : 'Until you verify, the app shows this person only their own bookings and medicine lists. Verify once you have seen them and checked the mobile number is theirs.'}
      </p>
      {canManage && (
        <div className="row wrap">
          {account.verified_at ? (
            <button className="btn ghost small-btn" onClick={() => run('verify', { verified: false }, 'Take back the verification? The app will stop showing prescriptions and lab reports.')}>
              Take back verification
            </button>
          ) : (
            <button className="btn small-btn" onClick={() => run('verify', { verified: true }, `Have you seen this person and checked that ${account.mobile} is their own number? After this they can see their prescriptions and lab reports in the app.`)}>
              Verify this account
            </button>
          )}
          <button className="btn ghost small-btn" onClick={() => run('password', {}, 'Give this patient a new one-time password? Every phone signed in to the account will be signed out.')}>
            Reset app password
          </button>
          <button className="btn ghost small-btn" onClick={() => run('active', { active: !account.is_active }, account.is_active ? 'Switch this app account off? The patient will be signed out and cannot sign in.' : undefined)}>
            {account.is_active ? 'Switch off' : 'Switch on'}
          </button>
        </div>
      )}
      {temporary && (
        <p className="notice">
          New password: <strong className="mono">{temporary}</strong>
          <br />
          Tell it to the patient now; it is not shown again. Ask them to change it in the app under Account.
        </p>
      )}
      {error && <p className="form-error">{error}</p>}
    </div>
  )
}
