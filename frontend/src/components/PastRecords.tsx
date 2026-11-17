import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, fieldErrors } from '../api'
import { longDate, today } from '../format'

/** What can read a scan right now: the hospital's own reading computer, and the outside AI service. */
export type Reading = { marker: { set_up: boolean; online: boolean; seen_at: string | null }; ai: { set_up: boolean } }

export type PaperPage = { id: number; original_name: string; mime: string; size?: number }

/** One old paper a patient brought, as the lists show it. */
export type Paper = {
  id: number
  patient_id: number
  kind: string
  kind_label: string
  record_date: string | null
  doctor_name: string | null
  hospital_name: string | null
  status: 'to_check' | 'filed'
  read_state: 'none' | 'queued' | 'reading' | 'done' | 'failed'
  read_engine: 'marker' | 'ai' | null
  read_error: string | null
  read_at: string | null
  ai_sent_at: string | null
  checked_at: string | null
  created_at: string
  creator: { id: number; name: string } | null
  checker: { id: number; name: string } | null
  pages: PaperPage[]
  patient: { id: number; uhid: string; title: string | null; name: string; gender: string; age_label: string | null } | null
}

export const PAPER_KINDS: [string, string][] = [
  ['visit', 'Prescription or consultation'],
  ['lab', 'Lab report'],
  ['imaging', 'Scan or X-ray report'],
  ['discharge', 'Discharge summary'],
  ['other', 'Other paper'],
]

/** Where a paper stands, in a few words, and how to colour it. */
export function paperStage(paper: Paper, reading: Reading | null): { words: string; pill: string } {
  if (paper.status === 'filed') return { words: 'Filed', pill: 'ok' }
  switch (paper.read_state) {
    case 'queued':
      return reading && !reading.marker.online ? { words: 'Waiting: the reading computer is off', pill: 'warn' } : { words: 'Waiting to be read', pill: 'status-booked' }
    case 'reading':
      return { words: 'Being read…', pill: 'status-ongoing' }
    case 'done':
      return { words: 'Read: ready to check', pill: 'status-arrived' }
    case 'failed':
      return { words: 'Could not be read', pill: 'off' }
    default:
      return { words: 'Ready to check', pill: 'status-arrived' }
  }
}

/** The longest side a scanned photograph is kept at. A page of writing stays easy to read; the file becomes a fraction of the size. */
const LONG_EDGE = 2200

/**
 * A phone photograph made small enough to keep thousands of. PDFs and small pictures are left as they are.
 * If the browser cannot redraw the picture, the original goes up unchanged.
 */
export async function shrinkPhoto(file: File): Promise<File> {
  if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return file
  try {
    const picture = await createImageBitmap(file, { imageOrientation: 'from-image' })
    const scale = Math.min(1, LONG_EDGE / Math.max(picture.width, picture.height))
    if (scale === 1 && file.size <= 1_500_000) return file
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(picture.width * scale)
    canvas.height = Math.round(picture.height * scale)
    const ink = canvas.getContext('2d')
    if (!ink) return file
    ink.fillStyle = '#ffffff'
    ink.fillRect(0, 0, canvas.width, canvas.height)
    ink.drawImage(picture, 0, 0, canvas.width, canvas.height)
    const blob = await new Promise<Blob | null>((done) => canvas.toBlob(done, 'image/jpeg', 0.85))
    if (!blob || blob.size >= file.size) return file
    return new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' })
  } catch {
    return file
  }
}

/** A line saying what will read the scans, so staff know what to expect after scanning. */
export function ReadingNote({ reading }: { reading: Reading }) {
  const marker = reading.marker
  return (
    <p className="muted small">
      {marker.set_up
        ? marker.online
          ? 'The reading computer is on: printed papers are read by themselves within a few minutes.'
          : 'The reading computer is off just now: papers wait and are read when it is switched on.'
        : 'No reading computer is set up, so papers are typed in by hand on the checking screen.'}{' '}
      {reading.ai.set_up ? 'A handwritten page can be sent to AI reading from the checking screen.' : ''}
    </p>
  )
}

/**
 * The old papers of one patient: scan new ones in, and see which are waiting to be checked and which are filed.
 * Front desk and nursing staff use this on the patient's page.
 */
export function PastRecordsPanel({ patient }: { patient: { id: number; name: string } }) {
  const [papers, setPapers] = useState<Paper[] | null>(null)
  const [reading, setReading] = useState<Reading | null>(null)
  const [files, setFiles] = useState<File[]>([])
  const [kind, setKind] = useState('')
  const [date, setDate] = useState('')
  const [onePaper, setOnePaper] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  const load = useCallback(() => {
    api.get<{ records: Paper[]; reading: Reading }>(`/patients/${patient.id}/past-records`).then((res) => {
      setPapers(res.data.records)
      setReading(res.data.reading)
    })
  }, [patient.id])
  useEffect(load, [load])

  // While something is waiting to be read, look again now and then so the list keeps up by itself.
  const reading_now = papers?.some((p) => p.status === 'to_check' && (p.read_state === 'queued' || p.read_state === 'reading')) ?? false
  useEffect(() => {
    if (!reading_now) return
    const timer = window.setInterval(load, 15000)
    return () => window.clearInterval(timer)
  }, [reading_now, load])

  const upload = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    if (!files.length) return
    const form = e.currentTarget
    setBusy(true)
    setError('')
    try {
      const body = new FormData()
      for (const file of files) body.append('files[]', await shrinkPhoto(file))
      if (kind) body.append('kind', kind)
      if (date) body.append('record_date', date)
      if (onePaper && files.length > 1) body.append('one_paper', '1')
      const res = await api.post<{ records: Paper[]; reading: Reading }>(`/patients/${patient.id}/past-records`, body)
      setPapers(res.data.records)
      setReading(res.data.reading)
      setFiles([])
      setOnePaper(false)
      form.reset()
    } catch (err) {
      setError(Object.values(fieldErrors(err))[0])
    } finally {
      setBusy(false)
    }
  }

  const waiting = papers?.filter((p) => p.status === 'to_check').length ?? 0

  return (
    <>
      {waiting > 0 && (
        <p className="notice warn">
          {waiting} {waiting === 1 ? 'paper is' : 'papers are'} waiting to be checked. Nothing from {waiting === 1 ? 'it' : 'them'} is in the record until someone checks {waiting === 1 ? 'it' : 'them'} against the scan.
        </p>
      )}
      <ul className="history">
        {papers?.map((p) => {
          const stage = paperStage(p, reading)
          return (
            <li key={p.id}>
              <span>
                <Link className="link" to={`/past-records/${p.id}`}>
                  {p.kind_label}
                </Link>
                <small className="muted">
                  {' '}
                  {p.record_date ? longDate(p.record_date) : 'date not yet known'}
                  {p.hospital_name ? ` · ${p.hospital_name}` : ''} · {p.pages.length} {p.pages.length === 1 ? 'file' : 'files'}
                  {p.status === 'filed' && p.checker ? ` · checked by ${p.checker.name}` : p.creator ? ` · scanned by ${p.creator.name}` : ''}
                </small>
              </span>
              <span className="paper-stage">
                <span className={`pill ${stage.pill}`}>{stage.words}</span>
                <Link className="link" to={`/past-records/${p.id}`}>
                  {p.status === 'filed' ? 'Open' : 'Check'}
                </Link>
              </span>
            </li>
          )
        })}
        {papers?.length === 0 && <li className="muted">No old papers scanned yet.</li>}
      </ul>

      <form onSubmit={upload} className="gap-top">
        <div className="grid">
          <label className="field span-2">
            <span className="field-label">Scanned files or photographs (PDF or picture, up to 12 at a time)</span>
            <input type="file" multiple accept=".pdf,image/png,image/jpeg,image/webp" onChange={(e) => setFiles(Array.from(e.target.files ?? []))} />
          </label>
          <label className="field">
            <span className="field-label">What are they? (can be chosen later)</span>
            <select value={kind} onChange={(e) => setKind(e.target.value)}>
              <option value="">Not sure yet</option>
              {PAPER_KINDS.map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </label>
          <label className="field">
            <span className="field-label">Date on the paper, if known</span>
            <input type="date" value={date} max={today()} onChange={(e) => setDate(e.target.value)} />
          </label>
          {files.length > 1 && (
            <label className="check span-2">
              <input type="checkbox" checked={onePaper} onChange={(e) => setOnePaper(e.target.checked)} />
              <span>
                These {files.length} files are the pages of <b>one</b> paper. Leave unticked if each file is a separate paper.
              </span>
            </label>
          )}
        </div>
        {reading && <ReadingNote reading={reading} />}
        {error && <p className="form-error">{error}</p>}
        <div className="panel-foot">
          <button className="btn primary" disabled={busy || !files.length}>
            {busy ? 'Sending…' : files.length > 1 ? `Scan in ${files.length} files` : 'Scan in'}
          </button>
        </div>
      </form>
      <p className="muted small">Scanning for {patient.name}. Check the name on each paper before scanning it in.</p>
    </>
  )
}
