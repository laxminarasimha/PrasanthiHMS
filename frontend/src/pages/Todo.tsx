import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors } from '../api'
import { useAuth } from '../auth'
import { longDate } from '../format'
import { todoChanged } from '../todoCount'

type Person = { id: number; name: string }
type Task = {
  id: number
  title: string
  notes: string | null
  assigned_to: number
  created_by: number | null
  due_on: string | null
  important: boolean
  repeats: 'none' | 'daily' | 'weekly' | 'monthly'
  done_at: string | null
  done_note: string | null
  assignee?: Person | null
  giver?: Person | null
  finisher?: Person | null
}
type Row = { text: string; detail: string | null; to: string | null }
type Item = { key: string; label: string; help: string; to: string; urgent: boolean; count: number; rows: Row[] }
type Section = { key: string; label: string; items: Item[] }
type Screen = { today: string; can_give: boolean; waiting: Section[]; tasks: Task[]; done: Task[] }

const REPEATS = [
  { value: 'none', label: 'Once' },
  { value: 'daily', label: 'Every day' },
  { value: 'weekly', label: 'Every week' },
  { value: 'monthly', label: 'Every month' },
] as const
const REPEAT_WORD = { none: '', daily: 'every day', weekly: 'every week', monthly: 'every month' } as const
const blank = { title: '', notes: '', due_on: '', important: false, repeats: 'none' as Task['repeats'] }

/** Whole days from one day to another, both as YYYY-MM-DD. */
const daysBetween = (from: string, to: string) => Math.round((Date.parse(to + 'T00:00:00') - Date.parse(from + 'T00:00:00')) / 86400000)

/** When a task is due, in the words a person would use, and whether that is already past. */
function due(task: Task, today: string): { text: string; late: boolean } | null {
  if (!task.due_on) return null
  const days = daysBetween(today, task.due_on)
  if (days < 0) return { text: days === -1 ? 'Late since yesterday' : `Late by ${-days} days`, late: true }
  if (days === 0) return { text: 'Today', late: false }
  if (days === 1) return { text: 'Tomorrow', late: false }
  return { text: longDate(task.due_on), late: false }
}

const stamp = (iso: string) => new Date(iso).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: 'numeric', minute: '2-digit' })

/**
 * A person's own To do screen. One side is the work the system already knows is waiting for them: it is read from
 * the records, nobody writes it, and each line goes away by itself when the work is done. The other side is tasks:
 * what the person wrote for themself and what they were asked to do, ticked off as they go.
 */
export function Todo() {
  const { user } = useAuth()
  const [data, setData] = useState<Screen | null>(null)
  const [failed, setFailed] = useState('')
  const [view, setView] = useState<'mine' | 'given'>('mine')
  const [given, setGiven] = useState<Task[] | null>(null)
  const [people, setPeople] = useState<(Person & { role: string })[] | null>(null)
  const [draft, setDraft] = useState(blank)
  const [editing, setEditing] = useState<number | null>(null)
  const [forOthers, setForOthers] = useState(false)
  const [chosen, setChosen] = useState<number[]>([])
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [open, setOpen] = useState<string | null>(null)

  const load = useCallback(
    () =>
      api
        .get<Screen>('/todo')
        .then((res) => {
          setData(res.data)
          setFailed('')
        })
        .catch(() => setFailed('The list could not be loaded. Check the internet connection.')),
    [],
  )
  const loadGiven = useCallback(() => api.get<Task[]>('/tasks/given').then((res) => setGiven(res.data)).catch(() => undefined), [])

  // The waiting work changes as others work, so the screen looks again every two minutes while it is open.
  useEffect(() => {
    void load()
    const timer = window.setInterval(load, 2 * 60 * 1000)
    return () => window.clearInterval(timer)
  }, [load])
  useEffect(() => {
    if (view === 'given') void loadGiven()
  }, [view, loadGiven])
  useEffect(() => {
    if (forOthers && people === null) api.get<(Person & { role: string })[]>('/tasks/people').then((res) => setPeople(res.data)).catch(() => setPeople([]))
  }, [forOthers, people])

  const after = async () => {
    await load()
    if (view === 'given' || given) await loadGiven()
    todoChanged()
  }
  const run = async (request: Promise<unknown>) => {
    setBusy(true)
    setError('')
    try {
      await request
      await after()
      return true
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0] ?? 'That was not saved. Please try again.')
      return false
    } finally {
      setBusy(false)
    }
  }

  const save = async (e: FormEvent) => {
    e.preventDefault()
    if (forOthers && editing === null && chosen.length === 0) {
      setError('Choose who the task is for.')
      return
    }
    const body = { title: draft.title, notes: draft.notes || null, due_on: draft.due_on || null, important: draft.important, repeats: draft.repeats }
    const ok = await run(editing !== null ? api.put(`/tasks/${editing}`, body) : api.post('/tasks', { ...body, ...(forOthers ? { for: chosen } : {}) }))
    if (ok) {
      setDraft(blank)
      setEditing(null)
      setChosen([])
    }
  }
  const edit = (t: Task) => {
    setEditing(t.id)
    setForOthers(false)
    setDraft({ title: t.title, notes: t.notes ?? '', due_on: t.due_on ?? '', important: t.important, repeats: t.repeats })
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  if (!data) return <p className={failed ? 'form-error' : 'loading'}>{failed || 'Loading your list…'}</p>

  const mayChange = (t: Task) => t.created_by === user?.id || user?.role === 'admin'
  const late = data.tasks.filter((t) => t.due_on && t.due_on < data.today)
  const todays = data.tasks.filter((t) => t.due_on === data.today)
  const coming = data.tasks.filter((t) => t.due_on && t.due_on > data.today)
  const someday = data.tasks.filter((t) => !t.due_on)
  const waiting = data.waiting.reduce((sum, s) => sum + s.items.length, 0)
  const urgent = data.waiting.reduce((sum, s) => sum + s.items.filter((i) => i.urgent).length, 0)

  const taskLine = (t: Task) => {
    const when = due(t, data.today)
    return (
      <li key={t.id} className={t.important ? 'important' : ''}>
        <button type="button" className="todo-tick" disabled={busy} onClick={() => void run(api.post(`/tasks/${t.id}/done`))} aria-label={`Mark "${t.title}" as done`} title="Mark as done" />
        <span className="todo-what">
          <strong>{t.title}</strong>
          {t.important && <span className="pill owed">Important</span>}
          {t.notes && <small className="todo-notes">{t.notes}</small>}
          <small className="muted">
            {when && <span className={when.late ? 'late' : ''}>{when.text}</span>}
            {t.repeats !== 'none' && <span>Repeats {REPEAT_WORD[t.repeats]}</span>}
            {t.giver && t.giver.id !== t.assigned_to && <span>From {t.giver.name}</span>}
          </small>
        </span>
        {mayChange(t) && (
          <span className="todo-acts">
            <button type="button" className="link" onClick={() => edit(t)}>
              Change
            </button>
            <button type="button" className="link danger" disabled={busy} onClick={() => window.confirm(`Remove "${t.title}" from the list?${t.repeats !== 'none' ? ' It will stop repeating.' : ''}`) && void run(api.delete(`/tasks/${t.id}`))}>
              Remove
            </button>
          </span>
        )}
      </li>
    )
  }
  const group = (label: string, tasks: Task[], tone = '') =>
    tasks.length > 0 && (
      <>
        <h3 className={tone}>
          {label} <span className="todo-n">{tasks.length}</span>
        </h3>
        <ul className="todo-list">{tasks.map(taskLine)}</ul>
      </>
    )

  return (
    <div className="todo">
      <div className="todo-head">
        <div>
          <h1>To do</h1>
          <span className="muted">
            {longDate(data.today)} · {user?.name}
          </span>
        </div>
        <div className="todo-figures">
          <div className={late.length + todays.length > 0 ? 'tile hot' : 'tile'}>
            <span>Tasks due today or late</span>
            <strong>{late.length + todays.length}</strong>
          </div>
          <div className={urgent > 0 ? 'tile hot' : 'tile'}>
            <span>Kinds of work waiting</span>
            <strong>{waiting}</strong>
          </div>
          <div className="tile">
            <span>Tasks done this week</span>
            <strong>{data.done.length}</strong>
          </div>
        </div>
      </div>
      {failed && <p className="form-error">{failed}</p>}

      <div className="todo-columns">
        <section className="card">
          <div className="panel-head">
            <h2>Waiting for you</h2>
            <button type="button" className="btn ghost small-btn" onClick={() => void load()}>
              Refresh
            </button>
          </div>
          <p className="muted small">Read from the hospital’s records. Each line goes away by itself once the work is done.</p>
          {data.waiting.length === 0 && <p className="todo-clear">Nothing is waiting for you right now.</p>}
          {data.waiting.map((section) => (
            <div key={section.key} className="todo-section">
              <h3>{section.label}</h3>
              <ul className="todo-waiting">
                {section.items.map((item) => {
                  const id = `${section.key}.${item.key}`
                  return (
                    <li key={item.key} className={item.urgent ? 'urgent' : ''}>
                      <button type="button" className="todo-item" onClick={() => setOpen(open === id ? null : id)} aria-expanded={open === id}>
                        <span className="todo-count">{item.count}</span>
                        <span className="todo-what">
                          <strong>{item.label}</strong>
                          <small className="muted">{item.help}</small>
                        </span>
                        <span className="todo-chevron" aria-hidden="true">
                          {open === id ? '▾' : '▸'}
                        </span>
                      </button>
                      {open === id && (
                        <div className="todo-rows">
                          <ul className="history">
                            {item.rows.map((row, i) => (
                              <li key={i}>
                                <span>
                                  {row.to ? <Link to={row.to}>{row.text}</Link> : row.text}
                                  {row.detail && <small className="muted block">{row.detail}</small>}
                                </span>
                              </li>
                            ))}
                          </ul>
                          <p className="todo-more">
                            {item.count > item.rows.length && item.rows.length > 0 && <span className="muted">and {item.count - item.rows.length} more. </span>}
                            <Link to={item.to}>Open the screen to do this</Link>
                          </p>
                        </div>
                      )}
                    </li>
                  )
                })}
              </ul>
            </div>
          ))}
        </section>

        <section className="card">
          <div className="panel-head">
            <h2>Tasks</h2>
            {data.can_give && (
              <div className="tabs">
                <button type="button" className={view === 'mine' ? 'active' : ''} onClick={() => setView('mine')}>
                  Mine <span>{data.tasks.length}</span>
                </button>
                <button type="button" className={view === 'given' ? 'active' : ''} onClick={() => setView('given')}>
                  Given to others
                </button>
              </div>
            )}
          </div>

          <form className="todo-add" onSubmit={save}>
            <input value={draft.title} onChange={(e) => setDraft({ ...draft, title: e.target.value })} placeholder={editing !== null ? 'The task' : 'Write a task: ring the oxygen supplier, check the crash cart…'} aria-label="Task" maxLength={255} required />
            <div className="todo-add-row">
              <label className="field">
                <span className="field-label">Due</span>
                <input type="date" value={draft.due_on} onChange={(e) => setDraft({ ...draft, due_on: e.target.value })} />
              </label>
              <label className="field">
                <span className="field-label">How often</span>
                <select value={draft.repeats} onChange={(e) => setDraft({ ...draft, repeats: e.target.value as Task['repeats'] })}>
                  {REPEATS.map((r) => (
                    <option key={r.value} value={r.value}>
                      {r.label}
                    </option>
                  ))}
                </select>
              </label>
              <label className="check flush">
                <input type="checkbox" checked={draft.important} onChange={(e) => setDraft({ ...draft, important: e.target.checked })} />
                Important
              </label>
            </div>
            <input value={draft.notes} onChange={(e) => setDraft({ ...draft, notes: e.target.value })} placeholder="A note, if it needs one (optional)" aria-label="Note" maxLength={2000} />
            {data.can_give && editing === null && (
              <div className="todo-for">
                <div className="tabs">
                  <button type="button" className={forOthers ? '' : 'active'} onClick={() => setForOthers(false)}>
                    For me
                  </button>
                  <button type="button" className={forOthers ? 'active' : ''} onClick={() => setForOthers(true)}>
                    For others
                  </button>
                </div>
                {forOthers && (
                  <div className="todo-people">
                    {people === null && <span className="muted">Loading the staff list…</span>}
                    {people?.map((p) => (
                      <label key={p.id} className={chosen.includes(p.id) ? 'on' : ''}>
                        <input type="checkbox" checked={chosen.includes(p.id)} onChange={(e) => setChosen(e.target.checked ? [...chosen, p.id] : chosen.filter((id) => id !== p.id))} />
                        {p.name} <small className="muted">{p.role}</small>
                      </label>
                    ))}
                  </div>
                )}
                {forOthers && <small className="muted">Each person chosen gets the task on their own list, and ticks off their own.</small>}
              </div>
            )}
            {error && <p className="form-error">{error}</p>}
            <div className="panel-foot">
              {editing !== null && (
                <button
                  type="button"
                  className="btn ghost"
                  onClick={() => {
                    setEditing(null)
                    setDraft(blank)
                  }}
                >
                  Cancel
                </button>
              )}
              <button className="btn primary" disabled={busy || draft.title.trim().length < 2}>
                {editing !== null ? 'Save the change' : forOthers ? 'Give the task' : 'Add to my list'}
              </button>
            </div>
          </form>

          {view === 'mine' && (
            <>
              {data.tasks.length === 0 && <p className="todo-clear">No tasks on your list. Write one above, or wait to be given one.</p>}
              {group('Late', late, 'late')}
              {group('Today', todays)}
              {group('Coming up', coming)}
              {group('Any time', someday)}
              {data.done.length > 0 && (
                <details className="todo-done">
                  <summary>Done in the last 7 days ({data.done.length})</summary>
                  <ul className="todo-list done">
                    {data.done.map((t) => (
                      <li key={t.id}>
                        <span className="todo-tick on" aria-hidden="true" />
                        <span className="todo-what">
                          <strong>{t.title}</strong>
                          <small className="muted">
                            <span>Done {t.done_at ? stamp(t.done_at) : ''}</span>
                            {t.done_note && <span>{t.done_note}</span>}
                          </small>
                        </span>
                        <span className="todo-acts">
                          <button type="button" className="link" disabled={busy} onClick={() => void run(api.post(`/tasks/${t.id}/reopen`))}>
                            Not done
                          </button>
                        </span>
                      </li>
                    ))}
                  </ul>
                </details>
              )}
            </>
          )}

          {view === 'given' && (
            <>
              {given === null && <p className="loading">Loading…</p>}
              {given?.length === 0 && <p className="todo-clear">You have not given anyone a task yet. Choose “For others” above.</p>}
              {given && given.length > 0 && (
                <ul className="todo-list given">
                  {given.map((t) => {
                    const when = due(t, data.today)
                    return (
                      <li key={t.id} className={t.done_at ? 'finished' : ''}>
                        <span className={t.done_at ? 'todo-tick on' : 'todo-tick idle'} aria-hidden="true" />
                        <span className="todo-what">
                          <strong>{t.title}</strong>
                          {t.important && !t.done_at && <span className="pill owed">Important</span>}
                          <small className="muted">
                            <span>For {t.assignee?.name ?? 'someone'}</span>
                            {t.done_at ? <span className="ok">Done {stamp(t.done_at)}{t.finisher && t.finisher.id !== t.assigned_to ? ` by ${t.finisher.name}` : ''}</span> : when && <span className={when.late ? 'late' : ''}>{when.text}</span>}
                            {!t.done_at && !when && <span>No day set</span>}
                            {t.repeats !== 'none' && <span>Repeats {REPEAT_WORD[t.repeats]}</span>}
                            {t.done_note && <span>{t.done_note}</span>}
                          </small>
                        </span>
                        {!t.done_at && (
                          <span className="todo-acts">
                            <button type="button" className="link" onClick={() => edit(t)}>
                              Change
                            </button>
                            <button type="button" className="link danger" disabled={busy} onClick={() => window.confirm(`Withdraw "${t.title}" from ${t.assignee?.name ?? 'this person'}?`) && void run(api.delete(`/tasks/${t.id}`))}>
                              Withdraw
                            </button>
                          </span>
                        )}
                      </li>
                    )
                  })}
                </ul>
              )}
            </>
          )}
        </section>
      </div>
    </div>
  )
}
