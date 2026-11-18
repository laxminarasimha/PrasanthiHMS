import { useEffect, useState } from 'react'
import { api, fieldErrors } from '../../api'
import { clock, longDate } from '../../format'

type Charging = { auto: boolean; from: string | null }

/** Whether the app adds each day's bed charge to an inpatient's bill by itself, with the rule it follows spelled out. */
export function IpdBedCharging() {
  const [saved, setSaved] = useState<Charging | null>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    api.get<Charging>('/ipd/bed-charging').then((res) => setSaved(res.data))
  }, [])

  if (!saved) return null

  const change = async (auto: boolean) => {
    const question = auto
      ? 'Switch automatic bed charges on?\n\nFrom now on each inpatient gets one bed charge for every day of stay. Days that have already begun are left to you, as before.'
      : 'Switch automatic bed charges off?\n\nNo more bed charges will be added. Lines already on bills stay as they are.'
    if (!window.confirm(question)) return
    setSaving(true)
    setError('')
    setNotice('')
    try {
      const res = await api.put<Charging>('/ipd/bed-charging', { auto })
      setSaved(res.data)
      setNotice(auto ? 'Switched on. Bed charges are added from the next day of each stay.' : 'Switched off.')
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'Could not save.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <section className="card">
      <div className="toolbar">
        <h2 className="grow">Bed charges</h2>
        <span className={`pill ${saved.auto ? 'ok' : 'off'}`}>{saved.auto ? 'Added automatically' : 'Added by hand'}</span>
      </div>
      {notice && <p className="notice">{notice}</p>}
      {error && <p className="form-error">{error}</p>}
      <p>
        {saved.auto ? (
          <>
            The app adds one bed charge to each inpatient's bill for every day of stay
            {saved.from ? ` (since ${longDate(saved.from)} ${clock(saved.from)})` : ''}.
          </>
        ) : (
          'Bed charges are added by the staff, with the “Fill in bed charges” link on the case.'
        )}
      </p>
      <ul className="plain-rules muted small">
        <li>A day of stay is 24 hours from the time of admission. A day that has begun is charged in full.</li>
        <li>Each day is charged for the bed the patient is in when that day begins, at that bed's daily rate. A bed with no daily rate adds nothing.</li>
        <li>The line can be corrected or removed on the case like any other charge, and it can be switched off for one patient (a package, for instance).</li>
        <li>Nothing is added to a bill that has been approved, or after the patient has left.</li>
      </ul>
      <div className="panel-foot">
        <button type="button" className={saved.auto ? 'btn ghost' : 'btn primary'} disabled={saving} onClick={() => change(!saved.auto)}>
          {saving ? 'Saving…' : saved.auto ? 'Switch off' : 'Switch on automatic bed charges'}
        </button>
      </div>
    </section>
  )
}
