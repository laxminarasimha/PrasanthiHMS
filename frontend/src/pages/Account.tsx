import { useState } from 'react'
import { api, fieldErrors } from '../api'
import { useAuth } from '../auth'
import { SecretForm } from '../components/SecretForm'
import { GoogleButton } from '../components/GoogleButton'
import { PushSettings } from '../components/PushControls'
import { getsNotices } from '../push'
import { useBrand } from '../brand'
import { THEMES, applyAppearance, type Appearance } from '../appearance'
import { allowedTabs } from '../nav'
import type { User } from '../types'

/** A person's own account: who they are signed in as, and changing their password. */
export function Account() {
  const { user, refresh } = useAuth()
  const brand = useBrand()
  const [googleNote, setGoogleNote] = useState('')
  const [hidden, setHidden] = useState<string[]>(user?.hidden_tabs ?? [])
  const [tabNotice, setTabNotice] = useState('')
  const pin = user?.sign_in_with === 'pin'
  const word = pin ? 'PIN' : 'password'
  const [notice, setNotice] = useState('')

  const joinGoogle = async (credential: string) => {
    setGoogleNote('')
    try {
      refresh((await api.post<User>('/account/google', { credential })).data)
      setGoogleNote('Joined. Next time, press "Sign in with Google" on the sign-in page.')
    } catch (err) {
      const errors = fieldErrors(err)
      setGoogleNote(errors.credential ?? errors._ ?? 'Could not join the Google account.')
    }
  }
  const removeGoogle = async () => {
    try {
      refresh((await api.delete<User>('/account/google')).data)
      setGoogleNote('Google sign-in is switched off for your account. Sign in with your email and password.')
    } catch {
      setGoogleNote('Could not change it. Please try again.')
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

      {getsNotices(user) && <PushSettings />}

      {brand?.google_client_id && (
        <>
          <h3>Sign in with Google</h3>
          {user?.google_email ? (
            <p>
              You can sign in with your Google account <strong>{user.google_email}</strong>.{' '}
              <button type="button" className="link danger" onClick={removeGoogle}>
                Stop using Google
              </button>
            </p>
          ) : (
            <>
              <p className="muted small">Join your Google account, then sign in with one tap instead of typing your password. Your password keeps working too.</p>
              <div className="narrow-box">
                <GoogleButton clientId={brand.google_client_id} text="continue_with" onCredential={joinGoogle} />
              </div>
            </>
          )}
          {googleNote && <p className="muted small">{googleNote}</p>}
        </>
      )}

      <h3>Change {word}</h3>
      {notice && <p className="notice">{notice}</p>}
      <SecretForm pin={pin} onDone={() => setNotice(`Your ${word} is changed. Any other phone or computer signed in with your account has been signed out.`)} />
      <p className="muted small">If you have forgotten your {word}, ask the hospital office to give you a new one.</p>
    </section>
  )
}
