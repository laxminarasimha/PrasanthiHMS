import { useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { useAuth } from '../auth'
import { TextField } from '../components/Field'
import { THEMES, applyAppearance, type Appearance } from '../appearance'
import { allowedTabs } from '../nav'
import type { User } from '../types'

/** A person's own account: who they are signed in as, and changing their password. */
export function Account() {
  const { user, refresh } = useAuth()
  const [hidden, setHidden] = useState<string[]>(user?.hidden_tabs ?? [])
  const [tabNotice, setTabNotice] = useState('')
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

  const look: Appearance = user?.appearance ?? { theme: 'prasanthi', text: 'normal' }
  const saveLook = async (next: Appearance) => {
    applyAppearance(next) // at once, so the choice is seen as it is made
    try {
      refresh((await api.put<User>('/me/appearance', next)).data)
    } catch {
      applyAppearance(look)
    }
  }

  const tabs = user ? allowedTabs(user) : []
  const saveTabs = async (next: string[]) => {
    setHidden(next)
    setTabNotice('')
    try {
      refresh((await api.put<User>('/me/tabs', { hidden: next })).data)
      setTabNotice('Menu updated.')
    } catch {
      setHidden(user?.hidden_tabs ?? [])
      setTabNotice('Could not save the menu. Try again.')
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

      <h3>Appearance</h3>
      <p className="muted small">Pick the colours you like working in. This is yours alone and follows you to any computer you sign in on. Printed bills, prescriptions and reports always use the standard hospital colours.</p>
      <div className="theme-list" role="radiogroup" aria-label="Theme">
        {THEMES.map((t) => (
          <button key={t.key} type="button" role="radio" aria-checked={look.theme === t.key} className={`theme-card ${look.theme === t.key ? 'on' : ''}`} onClick={() => saveLook({ ...look, theme: t.key })}>
            <span className="theme-swatch" style={{ background: t.bg }}>
              <i style={{ background: t.bar, borderBottom: `2px solid ${t.accent}` }} />
              <span className="sw-body" style={{ background: t.surface, margin: '6px 8px', borderRadius: 4 }}>
                <b style={{ background: t.brand }} />
                <u style={{ background: t.key === 'night' ? '#a9a1b5' : '#655d6c' }} />
              </span>
            </span>
            <strong>{t.name}</strong>
            <small>{t.note}</small>
          </button>
        ))}
      </div>
      <div className="row wrap">
        <span className="muted small">Text size:</span>
        <div className="tabs flush">
          {([['small', 'Small'], ['normal', 'Normal'], ['large', 'Large']] as const).map(([key, label]) => (
            <button key={key} type="button" className={look.text === key ? 'active' : ''} onClick={() => saveLook({ ...look, text: key })}>
              {label}
            </button>
          ))}
        </div>
      </div>

      <h3>My menu</h3>
      <p className="muted small">Untick the tabs you never use to keep your menu short. This only tidies the menu; it does not change what you are allowed to do, and you can tick them back any time.</p>
      <div className="tick-list">
        {tabs.map((t) => (
          <label key={t.key} className="tick">
            <input
              type="checkbox"
              checked={!hidden.includes(t.key)}
              disabled={!hidden.includes(t.key) && tabs.length - hidden.filter((h) => tabs.some((x) => x.key === h)).length <= 1}
              onChange={(e) => saveTabs(e.target.checked ? hidden.filter((h) => h !== t.key) : [...hidden, t.key])}
            />
            {t.label}
          </label>
        ))}
      </div>
      {tabNotice && <p className="muted small">{tabNotice}</p>}

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
