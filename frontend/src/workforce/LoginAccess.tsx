import { Fragment, useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors, type FieldErrors } from '../api'
import { useAuth } from '../auth'
import { SelectField, TextField } from '../components/Field'
import { useSignature } from '../components/useSignature'
import { clock, longDate } from '../format'
import type { Ability, Availability, RoleOption, Staff as Login } from '../types'
import type { Employee } from './shared'

const GENDERS = [
  { value: 'male', label: 'Male' },
  { value: 'female', label: 'Female' },
  { value: 'other', label: 'Other' },
]
const DAYS = [
  ['mon', 'Monday'], ['tue', 'Tuesday'], ['wed', 'Wednesday'], ['thu', 'Thursday'], ['fri', 'Friday'], ['sat', 'Saturday'], ['sun', 'Sunday'],
] as const
type Day = (typeof DAYS)[number][0]
/** Two sessions a day (morning and evening), each with a from and a to; blank means not set. */
type Hours = Record<Day, { from: string; to: string }[]>
const toHours = (saved: Availability | null | undefined): Hours =>
  Object.fromEntries(DAYS.map(([day]) => [day, [0, 1].map((i) => saved?.[day]?.[i] ?? { from: '', to: '' })])) as Hours
/** The counters a doctor can have a share of: bills of their visits, lab bills from their visits, bills of inpatients under them. */
const SHARES = [['front_desk', 'Front desk bills of their visits'], ['lab', 'Lab bills from their visits'], ['ipd', 'Bills of inpatients under them']] as const
const OWN_RECORD = 'staff'
type Part = 'sign-in' | 'permissions' | 'doctor'
type Catalogue = { roles: RoleOption[]; abilities: Ability[] }

/** Every change made to one person's role and permissions: who made it, when, what changed and why. */
function PermissionHistory({ loginId }: { loginId: number }) {
  const [rows, setRows] = useState<{ id: number; by: string | null; at: string | null; what: string | null }[] | null>(null)
  useEffect(() => {
    let current = true
    api
      .get<{ id: number; by: string | null; at: string | null; what: string | null }[]>(`/staff/${loginId}/permission-history`)
      .then((res) => current && setRows(res.data))
      .catch(() => current && setRows([]))
    return () => {
      current = false
    }
  }, [loginId])
  if (!rows || rows.length === 0) return null
  return (
    <div className="permission-history">
      <h3>Changes to this person’s permissions</h3>
      <ul>
        {rows.map((r) => (
          <li key={r.id}>
            <strong>{r.at ? `${longDate(r.at)} ${clock(r.at)}` : ''}</strong> · {r.by ?? 'Unknown'}
            <small className="muted block">{(r.what ?? '').split(' · ').slice(1).join(' · ')}</small>
          </li>
        ))}
      </ul>
    </div>
  )
}

/** A starting PIN just made: shown this once, for whoever is at the screen to hand to the person. */
export function PinSlip({ name, mobile, pin, onDone }: { name: string; mobile: string | null; pin: string; onDone: () => void }) {
  return (
    <div className="pin-slip" role="alert">
      <span>Starting PIN for {name}</span>
      <strong className="mono">{pin}</strong>
      <p>
        Tell them now: it is shown only this once. They sign in with their mobile number{mobile ? ` ${mobile}` : ''} and this PIN, and then choose a PIN of their own.
      </p>
      <button type="button" className="btn" onClick={onDone}>
        Done, I have told them
      </button>
    </div>
  )
}

/**
 * The "Login and access" part of a member of staff: whether they sign in, with what, their role and what they may do.
 * A person and their login are one record, so the name, mobile and gender are the staff record's and are not asked
 * for again here. `loginId` alone is for a login that is not a member of staff: a screen on the wall, or one not yet
 * joined to its person.
 */
export function LoginAccess({ person, loginId, onChanged }: { person?: Employee; loginId?: number; onChanged: (message?: string) => void }) {
  const { user } = useAuth()
  const manages = Boolean(user?.can_manage_staff)
  const isAdmin = user?.role === 'admin'
  const id = person?.login?.id ?? loginId ?? null
  const [catalogue, setCatalogue] = useState<Catalogue | null>(null)
  const [login, setLogin] = useState<Login | null>(null)
  const [failed, setFailed] = useState('')
  const [pin, setPin] = useState('')

  useEffect(() => {
    if (manages) api.get<Catalogue>('/staff/catalogue').then((res) => setCatalogue(res.data))
  }, [manages])
  const load = useCallback(() => {
    if (!manages || !id) return
    api
      .get<Login>(`/staff/${id}`)
      .then((res) => setLogin(res.data))
      .catch(() => setFailed('Could not load this login. Check the connection and try again.'))
  }, [manages, id])
  useEffect(load, [load])

  const newPin = async () => {
    if (!id || !window.confirm(`Give ${person?.name ?? 'this person'} a new starting PIN? Their present PIN stops working and their phone is signed out.`)) return
    setFailed('')
    try {
      setPin((await api.post<{ starting_pin: string }>(`/staff/${id}/starting-pin`)).data.starting_pin)
    } catch (err) {
      setFailed(Object.values(fieldErrors(err))[0] ?? 'That did not work. Try again.')
    }
  }

  if (pin) {
    return (
      <PinSlip
        name={person?.name ?? login?.name ?? 'this person'}
        mobile={person?.phone ?? login?.phone ?? null}
        pin={pin}
        onDone={() => {
          setPin('')
          onChanged()
          load()
        }}
      />
    )
  }
  if (failed && !login) return <div className="form-error">{failed}</div>

  // No login yet: the form that gives one.
  if (!id) return person ? <GiveLogin person={person} catalogue={catalogue} manages={manages} onGiven={(given) => (given ? setPin(given) : onChanged(`${person.name} can now sign in.`))} /> : null

  // Someone who keeps the staff records but does not manage logins sees what the login is, and may replace a forgotten PIN.
  if (!manages) {
    const brief = person?.login
    if (!brief) return null
    return (
      <div className="login-brief">
        {brief.signs_in === false && (
          <p>
            <strong>{person.name}</strong> is on the hospital's list of doctors and does not sign in. A login is given by those who manage logins.
          </p>
        )}
        <p hidden={brief.signs_in === false}>
          <strong>{person.name}</strong> signs in as <strong>{brief.role_label}</strong>, with {brief.sign_in_with === 'pin' ? `their mobile number${person.phone ? ` ${person.phone}` : ''} and a PIN` : (brief.email ?? 'their mobile number') + ' and a password'}.
        </p>
        {!brief.is_active && brief.signs_in !== false && <p className="due">This login is switched off.</p>}
        {brief.waiting_for_own_pin && <p className="muted">They have not yet signed in and chosen their own PIN.</p>}
        {failed && <div className="form-error">{failed}</div>}
        {brief.sign_in_with === 'pin' && brief.is_active && (
          <button type="button" className="btn" onClick={() => void newPin()}>
            Forgotten PIN: give a new starting PIN
          </button>
        )}
        <p className="muted small">Their role and what they may do are changed by those who manage logins.</p>
      </div>
    )
  }

  if (!login || !catalogue) return <p className="loading">Loading…</p>
  return (
    <>
      {failed && <div className="form-error">{failed}</div>}
      <LoginForm key={`${login.id}-${login.role}-${login.is_active}-${login.sign_in_with}`} login={login} person={person} catalogue={catalogue} isAdmin={isAdmin} seesPay={Boolean(user?.can_pay_consultants)} isSelf={login.id === user?.id} mine={user?.abilities ?? {}} onNewPin={() => void newPin()} onSaved={(saved, message) => {
        setLogin(saved)
        onChanged(message)
      }} />
    </>
  )
}

/** Giving a member of staff their login, from what their staff record already says. */
function GiveLogin({ person, catalogue, manages, onGiven }: { person: Employee; catalogue: Catalogue | null; manages: boolean; onGiven: (startingPin?: string) => void }) {
  const { user } = useAuth()
  const [role, setRole] = useState(OWN_RECORD)
  const [email, setEmail] = useState(person.email ?? '')
  const [password, setPassword] = useState('')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  // Only an admin makes another admin.
  const roles = (catalogue?.roles ?? [{ value: OWN_RECORD, label: 'Staff (own record only)' }]).filter((r) => r.value !== 'admin' || user?.role === 'admin')

  const give = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    try {
      const res = await api.post<{ starting_pin?: string }>(`/staff/for/${person.id}`, role === OWN_RECORD ? { role } : { role, email: email || null, password })
      onGiven(res.data.starting_pin)
    } catch (err) {
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  if (person.status === 'inactive') return <p className="muted">{person.name} has left the staff, so there is no login to give.</p>
  return (
    <form onSubmit={give} noValidate>
      <p className="muted">{person.name} does not sign in yet. Giving a login uses the name and mobile number already on this record; nothing is typed twice.</p>
      <div className="grid">
        <div className="span-2">
          {manages ? <SelectField label="Role" required value={role} onChange={setRole} options={roles} error={errors.role} /> : <p className="muted small flush">The login will open only their own attendance, leave and payslips.</p>}
        </div>
        {role !== OWN_RECORD && (
          <>
            <TextField label="Login email (optional)" type="email" placeholder="Without one, they sign in with the mobile number" value={email} onChange={setEmail} error={errors.email} autoComplete="off" />
            <TextField label="Password" required type="password" placeholder="At least 8 letters and numbers" value={password} onChange={setPassword} error={errors.password} autoComplete="new-password" />
          </>
        )}
      </div>
      <p className="muted small">
        {role === OWN_RECORD
          ? `They will sign in with their mobile number${person.phone ? ` ${person.phone}` : ''} and a 6-digit PIN. A starting PIN is made now and shown here once; they choose their own the first time they sign in.`
          : `They will sign in with their mobile number${person.phone ? ` ${person.phone}` : ''}${email ? ' or their email' : ''}, and this password. What the role allows can be changed for this person afterwards.`}
      </p>
      {Object.entries(errors).filter(([key]) => !['role', 'email', 'password'].includes(key)).map(([key, message]) => (
        <p key={key} className="form-error">
          {message}
        </p>
      ))}
      <div className="panel-foot">
        <button className="btn primary" disabled={saving || (role !== OWN_RECORD && !password)}>
          {saving ? 'Saving…' : role === OWN_RECORD ? 'Give a login and show the starting PIN' : 'Give a login'}
        </button>
      </div>
    </form>
  )
}

/** A login that exists: how it is opened, the role, what the person may do, and a doctor's own settings. */
function LoginForm({ login, person, catalogue, isAdmin, seesPay, isSelf, mine, onNewPin, onSaved }: { login: Login; person?: Employee; catalogue: Catalogue; isAdmin: boolean; /** May see and set a doctor's share: those who look after consultants' fees. */ seesPay: boolean; isSelf: boolean; mine: Record<string, boolean>; onNewPin: () => void; onSaved: (saved: Login, message: string) => void }) {
  const { roles, abilities } = catalogue
  const [part, setPart] = useState<Part>('sign-in')
  const [form, setForm] = useState({ name: login.name, phone: login.phone ?? '', gender: login.gender ?? '', email: login.email ?? '', password: '', role: login.role, signature_text: login.signature_text ?? '', is_active: login.is_active })
  const [perms, setPerms] = useState<Record<string, boolean>>(login.abilities)
  const [hours, setHours] = useState<Hours>(toHours(login.availability))
  const [slot, setSlot] = useState(login.slot_minutes ? String(login.slot_minutes) : '')
  const [bookOnline, setBookOnline] = useState(login.book_online ?? true)
  // The doctor's own OPD consultation fee. A price, so only those who set prices change it.
  const canPrice = useAuth().user?.can_set_prices ?? false
  const [fee, setFee] = useState(login.opd_fee == null ? '' : String(login.opd_fee))
  const share = (value?: number | null) => (value == null ? '' : String(value))
  const [shares, setShares] = useState({ front_desk: share(login.share_front_desk), lab: share(login.share_lab), ipd: share(login.share_ipd) })
  const [reason, setReason] = useState('')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [hasSignature, setHasSignature] = useState(login.has_signature)
  const [signatureVersion, setSignatureVersion] = useState(0)
  const signature = useSignature(hasSignature ? login.id : null, signatureVersion)

  const own = form.role === OWN_RECORD
  const wasOwn = login.role === OWN_RECORD
  // A doctor who is on the hospital's lists and has no way to sign in. A password typed here gives them one.
  const none = login.sign_in_with === 'none'
  const set = (key: keyof typeof form) => (value: string) => {
    setForm((f) => ({ ...f, [key]: value }))
    // A new role starts from that role's usual permissions.
    if (key === 'role') setPerms(Object.fromEntries(abilities.map((a) => [a.key, a.roles.includes(value)])))
  }
  const setHour = (day: Day, index: number, key: 'from' | 'to', value: string) => setHours((h) => ({ ...h, [day]: h[day].map((range, i) => (i === index ? { ...range, [key]: value } : range)) }))
  /**
   * Why whoever is signed in cannot give this permission to the person, or '' if they can. Nobody gives more than
   * they hold, and a sensitive one on top of the role is the admin's to give. What a person already has stays.
   */
  const barred = (a: Ability) => {
    if (isAdmin || login.abilities[a.key]) return ''
    if (!mine[a.key]) return 'You cannot give this: you are not allowed to do it yourself.'
    if (a.sensitive && !a.roles.includes(form.role)) return 'Only an admin can give this on top of the role.'
    return ''
  }
  const allowedOf = (a: Ability) => (form.role === 'admin' ? true : barred(a) ? false : (perms[a.key] ?? a.roles.includes(form.role)))
  // A change of role or of any tick is kept with who made it, when and why, so a reason is asked for.
  const changing = !isSelf && (form.role !== login.role || abilities.some((a) => allowedOf(a) !== Boolean(login.abilities[a.key])))
  const availabilityError = Object.entries(errors).find(([key]) => key.startsWith('availability'))?.[1]

  const save = async (e: FormEvent | null, stopSigningIn = false) => {
    e?.preventDefault()
    setSaving(true)
    setErrors({})
    const body = {
      ...(stopSigningIn ? { signs_in: false } : {}),
      ...(form.role === 'doctor' ? { book_online: bookOnline } : {}),
      ...(form.role === 'doctor' && canPrice ? { opd_fee: fee === '' ? null : Number(fee) } : {}),
      // The staff record's own name, mobile and gender: typed there, carried here.
      name: person?.name ?? form.name,
      phone: person?.phone ?? form.phone,
      gender: (person ? person.gender : form.gender) || null,
      department: person ? (person.department?.name ?? null) : (login.department ?? null),
      email: form.email || null,
      password: form.password || undefined,
      role: form.role,
      signature_text: form.signature_text || null,
      is_active: form.is_active,
      permissions: own ? undefined : Object.fromEntries(abilities.map((a) => [a.key, allowedOf(a)])),
      reason: changing ? reason.trim() : undefined,
      availability: form.role === 'doctor' ? Object.fromEntries(DAYS.map(([day]) => [day, hours[day].filter((r) => r.from && r.to)])) : null,
      slot_minutes: form.role === 'doctor' && slot ? Number(slot) : null,
      ...(seesPay ? Object.fromEntries(SHARES.map(([key]) => [`share_${key}`, form.role === 'doctor' && shares[key] !== '' ? Number(shares[key]) : null])) : {}),
    }
    try {
      const res = await api.put<Login>(`/staff/${login.id}`, body)
      setReason('')
      onSaved(res.data, `${res.data.name}'s login was saved.`)
    } catch (err) {
      const found = fieldErrors(err)
      setErrors(found)
      // Show the part that holds the problem.
      const first = Object.keys(found)[0] ?? ''
      setPart(first.startsWith('availability') || first.startsWith('share_') || first === 'slot_minutes' || first === 'opd_fee' ? 'doctor' : first.startsWith('permissions') ? 'permissions' : 'sign-in')
    } finally {
      setSaving(false)
    }
  }

  const saveSignature = async (file?: File) => {
    setErrors({})
    try {
      if (file) {
        const body = new FormData()
        body.append('file', file)
        await api.post(`/staff/${login.id}/signature`, body)
      } else {
        await api.delete(`/staff/${login.id}/signature`)
      }
      setHasSignature(Boolean(file))
      setSignatureVersion((v) => v + 1)
    } catch (err) {
      setErrors({ _: Object.values(fieldErrors(err))[0] })
    }
  }

  return (
    <form onSubmit={save} noValidate>
      {(!own || form.role === 'doctor') && (
        <div className="tabs flush gap-bottom minor">
          <button type="button" className={part === 'sign-in' ? 'active' : ''} onClick={() => setPart('sign-in')}>
            Sign-in and role
          </button>
          {!own && (
            <button type="button" className={part === 'permissions' ? 'active' : ''} onClick={() => setPart('permissions')}>
              Permissions
            </button>
          )}
          {form.role === 'doctor' && (
            <button type="button" className={part === 'doctor' ? 'active' : ''} onClick={() => setPart('doctor')}>
              Doctor settings
            </button>
          )}
        </div>
      )}

      {part === 'sign-in' && (
        <>
          <p className="muted small">
            {none ? (
              <>Does not sign in. They are on the hospital's list of doctors, so they can be chosen for appointments, admissions and consultants' visits. To let them sign in, type a password below and save.</>
            ) : (
              <>
                Signs in with {[person?.phone ?? login.phone ? `mobile number ${person?.phone ?? login.phone}` : '', login.email ? `email ${login.email}` : ''].filter(Boolean).join(' or ') || 'nothing yet: add a mobile number'}
                , and {wasOwn ? 'a PIN' : 'a password'}.
              </>
            )}
          </p>
          <div className="grid">
            {!person && (
              <>
                <TextField label="Name" required value={form.name} onChange={set('name')} error={errors.name} />
                <SelectField label="Gender" value={form.gender} onChange={set('gender')} options={GENDERS} placeholder="Not set" error={errors.gender} />
                <TextField label="Phone" required inputMode="tel" value={form.phone} onChange={set('phone')} error={errors.phone} />
              </>
            )}
            {isSelf ? <TextField label="Role" value={roles.find((r) => r.value === form.role)?.label ?? form.role} onChange={() => undefined} disabled title="You cannot change your own role" /> : <SelectField label="Role" required value={form.role} onChange={set('role')} options={roles.filter((r) => r.value !== 'admin' || isAdmin || login.role === 'admin')} error={errors.role} />}
            {!own && <TextField label="Login email (optional)" type="email" placeholder="Without one, they sign in with the mobile number" value={form.email} onChange={set('email')} error={errors.email} autoComplete="off" />}
            {own === wasOwn && own ? null : (
              <TextField
                label={own ? 'Starting PIN' : none ? 'Password, to let them sign in' : wasOwn ? 'Password' : 'New password'}
                required={own !== wasOwn}
                type="password"
                inputMode={own ? 'numeric' : undefined}
                placeholder={own ? '6 digits; they choose their own at first sign-in' : none ? 'Leave blank: they stay without a login' : wasOwn ? 'At least 8 letters and numbers' : 'Leave blank to keep the present one'}
                value={form.password}
                onChange={set('password')}
                error={errors.password}
                autoComplete="new-password"
              />
            )}
          </div>
          {person && errors.phone && <p className="form-error">{errors.phone}</p>}
          {own !== wasOwn && <p className="muted small">{own ? 'An own-record login is opened with a PIN, so a starting PIN is needed. Their password stops working.' : 'This role is opened with a password, so one is needed. Their PIN stops working.'}</p>}
          {own && wasOwn && !isSelf && (
            <p>
              <button type="button" className="btn" onClick={onNewPin}>
                Forgotten PIN: give a new starting PIN
              </button>
            </p>
          )}
          {!isSelf && (
            <label className="check">
              <input type="checkbox" checked={form.is_active} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))} />
              {form.role === 'doctor' ? `On the doctors' list${none ? '' : ', and able to sign in'} (untick when they no longer work here)` : 'The login is open (untick to stop this person signing in)'}
            </label>
          )}
          {!isSelf && !none && login.role === 'doctor' && form.role === 'doctor' && form.is_active && (
            <p>
              <button type="button" className="link" disabled={saving} onClick={() => window.confirm(`Stop ${person?.name ?? login.name} signing in? They are signed out everywhere and stay on the doctors' list.`) && void save(null, true)}>
                Stop them signing in, and keep them on the doctors' list
              </button>
            </p>
          )}

          {!own && (
            <>
              <h3>Signature</h3>
              <TextField label="Signature text" value={form.signature_text} onChange={set('signature_text')} error={errors.signature_text} />
              <div className="signature-box">
                {signature ? <img src={signature} alt={`Signature of ${login.name}`} /> : <span className="muted small">No signature picture. The signature text is printed on its own.</span>}
                <div className="row">
                  <label className="btn ghost small-btn">
                    {hasSignature ? 'Replace' : 'Upload'}
                    <input
                      type="file"
                      hidden
                      accept="image/png,image/jpeg"
                      onChange={(e) => {
                        void saveSignature(e.target.files?.[0])
                        e.target.value = ''
                      }}
                    />
                  </label>
                  {hasSignature && (
                    <button type="button" className="link danger" onClick={() => void saveSignature()}>
                      Remove
                    </button>
                  )}
                  <span className="muted small">PNG or JPG, up to 300 KB. Printed on prescriptions and lab reports.</span>
                </div>
              </div>
            </>
          )}
        </>
      )}

      {part === 'permissions' && !own && (
        <div>
          {form.role === 'admin' ? (
            <p className="muted">An admin can always do everything, so the hospital cannot be locked out.</p>
          ) : isSelf ? (
            <p className="muted">You cannot change your own permissions. Ask another admin or doctor.</p>
          ) : (
            <p className="muted small">
              Ticked by default according to the role. Change any of them for this person only: an unticked box always wins over the role.
              {!isAdmin && ' You can give only what you are allowed to do yourself; the ones marked “admin only” are given by an admin.'}
            </p>
          )}
          {abilities.map((a, index) => {
            const usual = a.roles.includes(form.role)
            const allowed = allowedOf(a)
            const why = form.role === 'admin' || isSelf ? '' : barred(a)
            // The list is long: each part of the system gets its own heading.
            const heading = a.group && a.group !== abilities[index - 1]?.group ? a.group : ''
            return (
              <Fragment key={a.key}>
                {heading && <h3 className="permission-group">{heading}</h3>}
                <label className="check top">
                  <input type="checkbox" checked={allowed} disabled={form.role === 'admin' || isSelf || Boolean(why)} onChange={(e) => setPerms((p) => ({ ...p, [a.key]: e.target.checked }))} />
                  <span>
                    {a.label}
                    {a.sensitive && <span className="pill off"> Admin only to add</span>}
                    {allowed !== usual && form.role !== 'admin' && <span className="pill status-arrived"> {allowed ? 'Added' : 'Removed'} for this person</span>}
                    <small className="muted block">{a.hint}</small>
                    {why && <small className="due block">{why}</small>}
                  </span>
                </label>
              </Fragment>
            )
          })}
          {errors.permissions && <p className="form-error">{errors.permissions}</p>}
          <PermissionHistory loginId={login.id} />
        </div>
      )}

      {part === 'doctor' && form.role === 'doctor' && (
        <div>
          <div className="doctor-fee">
            <TextField label="OPD consultation fee (₹)" type="number" min={0} step="1" placeholder="None of their own" value={fee} onChange={setFee} error={errors.opd_fee} disabled={!canPrice} title={canPrice ? undefined : 'Only someone allowed to set prices can change this'} />
            <p className="muted small">Put on the bill by itself when this doctor is chosen for a visit. Each doctor has their own. Left empty, the desk chooses from the hospital's list of services.</p>
          </div>
          <p className="muted small">The doctor's usual hours, up to two sessions a day. The appointment calendar greys the times outside them, and booking a later visit outside them asks the desk to confirm. Leave every day empty to follow the hospital's hours.</p>
          <label className="check">
            <input type="checkbox" checked={bookOnline} onChange={(e) => setBookOnline(e.target.checked)} />
            Patients can book this doctor from the phone app
          </label>
          <label className="field slot-length">
            <span className="field-label">Length of one appointment slot</span>
            <select value={slot} onChange={(e) => setSlot(e.target.value)}>
              <option value="">Same as the hospital</option>
              {[5, 10, 15, 20, 30, 60].map((m) => (
                <option key={m} value={m}>
                  {m === 60 ? '1 hour' : `${m} minutes`}
                </option>
              ))}
            </select>
          </label>
          <div className="hours">
            <span />
            <span className="field-label">Morning</span>
            <span className="field-label">Evening</span>
            {DAYS.map(([day, label]) => (
              <div className="hours-row" key={day}>
                <strong>{label}</strong>
                {[0, 1].map((i) => (
                  <span className="hours-range" key={i}>
                    <input type="time" value={hours[day][i].from} onChange={(e) => setHour(day, i, 'from', e.target.value)} aria-label={`${label} ${i ? 'evening' : 'morning'} from`} />
                    <span>–</span>
                    <input type="time" value={hours[day][i].to} onChange={(e) => setHour(day, i, 'to', e.target.value)} aria-label={`${label} ${i ? 'evening' : 'morning'} to`} />
                  </span>
                ))}
              </div>
            ))}
          </div>
          <div className="row gap-top">
            <button type="button" className="link" onClick={() => setHours((h) => Object.fromEntries(DAYS.map(([day]) => [day, day === 'sun' ? h.sun : h.mon.map((r) => ({ ...r }))])) as Hours)}>
              Copy Monday to Tuesday–Saturday
            </button>
            <button type="button" className="link danger" onClick={() => setHours(toHours(null))}>
              Clear all
            </button>
          </div>
          {availabilityError && <p className="form-error">Each session needs a start time and a later end time.</p>}

          {seesPay && (
            <>
              <h3>Doctor's share of collections</h3>
              <p className="muted small">
                Optional. The percentage of the money collected from this doctor's own patients that is the doctor's, for each counter. The report "Doctor: share of collections" works it out for any dates; paying it is entered under Expenses. Only those who look after consultants' fees see these figures. What it comes to each month, and what was paid, is under Workforce → Pay → Consultants.
              </p>
              <div className="doctor-shares">
                {SHARES.map(([key, label]) => (
                  <TextField key={key} label={`${label} (%)`} type="number" min={0} max={100} step="0.5" placeholder="None" value={shares[key]} onChange={(v) => setShares((s) => ({ ...s, [key]: v }))} error={errors[`share_${key}`]} />
                ))}
              </div>
            </>
          )}
        </div>
      )}

      {changing && (
        <div className="change-reason">
          <TextField label="Reason for changing this person’s role or permissions" required value={reason} onChange={setReason} error={errors.reason} placeholder="Kept with the change: who made it, when and why" />
        </div>
      )}
      {errors._ && <p className="form-error">{errors._}</p>}
      <div className="panel-foot">
        <button className="btn primary" disabled={saving || (changing && !reason.trim())} title={changing && !reason.trim() ? 'Give the reason for the change first' : undefined}>
          {saving ? 'Saving…' : 'Save the login'}
        </button>
      </div>
    </form>
  )
}
