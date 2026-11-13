import { useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { useAuth } from '../auth'
import { TextField } from '../components/Field'

/** A person's own account: who they are signed in as, and changing their password. */
export function Account() {
  const { user } = useAuth()
  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)
  const set = (key: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [key]: value }))

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    setNotice('')
    try {
      await api.post('/me/password', form)
      setForm({ current_password: '', password: '', password_confirmation: '' })
      setNotice('Password changed. Any other phone or computer signed in with your account has been signed out.')
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <section className="card narrow">
      <div className="toolbar">
        <h1>My account</h1>
      </div>
      <p>
        <strong>{user?.name}</strong>
        <br />
        <span className="muted">
          {user?.email} · {user?.role_label}
          {user?.branch ? ` · ${user.branch.name}` : ''}
        </span>
      </p>

      <h3>Change password</h3>
      {notice && <p className="notice">{notice}</p>}
      <form onSubmit={save} noValidate>
        <div className="grid">
          <div className="span-2">
            <TextField label="Current password" required type="password" autoComplete="current-password" value={form.current_password} onChange={set('current_password')} error={errors.current_password} />
          </div>
          <TextField label="New password" required type="password" autoComplete="new-password" placeholder="At least 8 letters and numbers" value={form.password} onChange={set('password')} error={errors.password} />
          <TextField label="New password again" required type="password" autoComplete="new-password" value={form.password_confirmation} onChange={set('password_confirmation')} error={errors.password_confirmation} />
        </div>
        {errors._ && <p className="form-error">{errors._}</p>}
        <div className="panel-foot">
          <button className="btn primary" disabled={saving || !form.current_password || !form.password || !form.password_confirmation}>
            {saving ? 'Saving…' : 'Change password'}
          </button>
        </div>
      </form>
      <p className="muted small">If you have forgotten your password, ask an admin or a doctor to set a new one for you under Staff.</p>
    </section>
  )
}
