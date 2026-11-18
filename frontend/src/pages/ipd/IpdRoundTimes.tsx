import { useEffect, useState, type FormEvent } from 'react'
import { api, fieldErrors } from '../../api'
import { ROUND_LABEL, roundClock, type Rounds } from './doseTiming'

const KEYS = ['od', 'bd', 'tds', 'qid'] as const

/** When the wards give once, twice, three and four times a day medicines. The bedside chart counts the next dose from these. */
export function IpdRoundTimes() {
  const [saved, setSaved] = useState<Rounds | null>(null)
  const [times, setTimes] = useState<Rounds | null>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    api.get<Rounds>('/ipd/round-times').then((res) => {
      setSaved(res.data)
      setTimes(res.data)
    })
  }, [])

  if (!times || !saved) return null

  const changed = JSON.stringify(times) !== JSON.stringify(saved)
  const save = async (e: FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setError('')
    setNotice('')
    try {
      const res = await api.put<Rounds>('/ipd/round-times', times)
      setSaved(res.data)
      setTimes(res.data)
      setNotice('Round times saved. Bedside charts use them from now on.')
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'Could not save.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <form className="card" onSubmit={save}>
      <div className="toolbar">
        <div className="grow">
          <h1>Medicine round times</h1>
          <span className="muted">When the wards give OD, BD, TDS and QID medicines. The bedside chart shows the next dose of such a medicine at the next of these rounds.</span>
        </div>
      </div>
      {notice && <p className="notice">{notice}</p>}
      {error && <p className="form-error">{error}</p>}
      <div className="round-times">
        {KEYS.map((key) => (
          <div key={key} className="round-row">
            <strong>{ROUND_LABEL[key]}</strong>
            <div className="row">
              {times[key].map((t, i) => (
                <input
                  key={i}
                  type="time"
                  required
                  value={t}
                  onChange={(e) => setTimes({ ...times, [key]: times[key].map((old, j) => (j === i ? e.target.value : old)) })}
                  aria-label={`${ROUND_LABEL[key]}, round ${i + 1}`}
                />
              ))}
            </div>
            <small className="muted">{times[key].filter(Boolean).map(roundClock).join(', ')}</small>
          </div>
        ))}
      </div>
      <div className="panel-foot row">
        {changed && (
          <button type="button" className="btn ghost" onClick={() => setTimes(saved)}>
            Undo changes
          </button>
        )}
        <button className="btn primary" disabled={saving || !changed}>
          {saving ? 'Saving…' : 'Save round times'}
        </button>
      </div>
    </form>
  )
}
