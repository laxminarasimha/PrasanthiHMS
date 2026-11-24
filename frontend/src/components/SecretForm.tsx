import { useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { TextField } from './Field'

/** How many digits a PIN has. The server holds the rule; this only shapes the boxes. */
const PIN_DIGITS = 6

/**
 * Changing one's own password, or one's own PIN for a login that is opened with a PIN.
 * `current` is given when the person has just typed it to sign in, so they are not asked for it twice.
 */
export function SecretForm({ pin, current, action, onDone }: { pin: boolean; current?: string; action?: string; onDone: () => void }) {
  const [form, setForm] = useState({ current_password: current ?? '', password: '', password_confirmation: '' })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const word = pin ? 'PIN' : 'password'
  // A PIN is digits only, typed on the phone's number pad.
  const digits = (value: string) => (pin ? value.replace(/\D/g, '').slice(0, PIN_DIGITS) : value)
  const set = (key: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [key]: key === 'current_password' ? value : digits(value) }))
  const box = pin ? ({ inputMode: 'numeric', maxLength: PIN_DIGITS } as const) : {}

  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    try {
      await api.post('/me/password', form)
      setForm({ current_password: '', password: '', password_confirmation: '' })
      onDone()
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <form onSubmit={save} noValidate>
      <div className="grid">
        {current === undefined && (
          <div className="span-2">
            <TextField label={`Current ${word}`} required type="password" autoComplete="current-password" value={form.current_password} onChange={set('current_password')} error={errors.current_password} />
          </div>
        )}
        <TextField label={`New ${word}`} required type="password" autoComplete="new-password" placeholder={pin ? `${PIN_DIGITS} digits` : 'At least 8 letters and numbers'} value={form.password} onChange={set('password')} error={errors.password} {...box} />
        <TextField label={`New ${word} again`} required type="password" autoComplete="new-password" value={form.password_confirmation} onChange={set('password_confirmation')} error={errors.password_confirmation} {...box} />
      </div>
      {current !== undefined && errors.current_password && <p className="form-error">{errors.current_password}</p>}
      {errors._ && <p className="form-error">{errors._}</p>}
      <div className="panel-foot">
        <button className="btn primary" disabled={saving || !form.current_password || !form.password || !form.password_confirmation}>
          {saving ? 'Saving…' : (action ?? `Change ${word}`)}
        </button>
      </div>
    </form>
  )
}
