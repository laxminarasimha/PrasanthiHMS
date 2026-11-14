import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../api'
import { ClinicFooter, ClinicHeader } from '../components/ClinicHeader'
import { useSignature } from '../components/useSignature'
import { longDate, fullName, stampDate } from '../format'
import type { Visit } from '../types'

/** The prescription as it is handed to the patient; opens the print dialog once loaded. */
export function PrescriptionPrint() {
  const { id } = useParams()
  const [visit, setVisit] = useState<Visit | null>(null)

  useEffect(() => {
    api.get<Visit>(`/appointments/${id}/consultation`).then((res) => {
      setVisit(res.data)
      // A moment for the signature picture to arrive before the print dialog opens.
      setTimeout(() => window.print(), 800)
    })
  }, [id])
  const signature = useSignature(visit?.doctor.id)
  const seal = useSignature(visit?.doctor.id, 0, 'seal')

  if (!visit) return <p className="loading">Loading prescription…</p>

  // Shows a word in the doctor's chosen language when it has a translation.
  const rx = visit.rx_print
  const known = new Map((rx?.translations ?? []).map((t) => [t.phrase.trim().toLowerCase(), t.translation]))
  const tr = (text: string | null | undefined) => {
    const translation = text ? known.get(text.trim().toLowerCase()) : undefined
    if (!text || !translation || !rx || rx.print_language === 'en') return text ?? ''
    return rx.print_language === 'local' ? translation : `${text} (${translation})`
  }
  // The doctor's page layout: what prints, how large, and how much room to leave.
  const layout = rx?.print
  const shows = (section: string) => layout?.sections?.[section] !== false
  const column = (name: string) => layout?.columns?.[name] !== false
  const patientName = rx?.capitalize_name ? fullName(visit.patient).toUpperCase() : fullName(visit.patient)
  const c = visit.consultation
  if (!c) return <p className="loading">Nothing has been recorded for this visit yet.</p>

  const vitals = [
    c.weight_kg && `Weight ${c.weight_kg} kg`,
    c.height_cm && `Height ${c.height_cm} cm`,
    c.bp_systolic && `BP ${c.bp_systolic}/${c.bp_diastolic} mmHg`,
    c.pulse && `Pulse ${c.pulse}/min`,
    c.temperature_f && `Temp ${c.temperature_f} °F`,
    c.spo2 && `SpO₂ ${c.spo2}%`,
    c.resp_rate && `RR ${c.resp_rate}/min`,
  ].filter(Boolean)

  const signs = Object.entries(c.general_exam ?? {})
  const present = signs.filter(([, value]) => value === 'present').map(([sign]) => sign)
  const absent = signs.filter(([, value]) => value === 'absent').map(([sign]) => sign)
  const generalExam = [present.length ? `${present.join(', ')} present` : '', absent.length ? `no ${absent.join(', ')}` : '', c.general_exam_notes ?? '']
    .filter(Boolean)
    .join('; ')

  return (
    <div className="receipt prescription" style={layout ? { fontSize: `${layout.font_size_pt}pt` } : undefined}>
      {layout && <style>{`@media print { @page { margin: ${layout.margin_top_mm}mm ${layout.margin_side_mm}mm ${layout.margin_bottom_mm}mm; } .receipt.prescription { padding: 0; } }`}</style>}
      {layout?.letterhead ? (
        <div className="letterhead-gap no-print" style={{ height: `${layout.margin_top_mm}mm` }}>
          Space left for the letterhead ({layout.margin_top_mm} mm)
        </div>
      ) : (
        <ClinicHeader />
      )}

      <div className="receipt-meta">
        <div>
          <strong>{patientName}</strong>
          <br />
          {visit.patient.age_label} / {visit.patient.gender.charAt(0).toUpperCase()}
          {layout?.show_patient_id !== false && <> · Patient ID: {visit.patient.uhid}</>}
          {layout?.show_mobile !== false && (
            <>
              <br />
              Mobile: {visit.patient.mobile}
            </>
          )}
        </div>
        <div className="right">
          <strong>{visit.doctor.name}</strong>
          {visit.doctor.department && (
            <>
              <br />
              {visit.doctor.department}
            </>
          )}
          <br />
          Date: {longDate(c.completed_at ?? visit.appointment.scheduled_at)}
        </div>
      </div>

      {visit.patient.allergies && <p className="rx-allergy">Allergies: {visit.patient.allergies}</p>}
      {shows('vitals') && vitals.length > 0 && <p className="rx-vitals">{vitals.join(' · ')}</p>}
      {shows('complaints') && c.complaints && <section><h3>Complaints</h3><p>{c.complaints}</p></section>}
      {shows('history') && c.history && <section><h3>History</h3><p>{c.history}</p></section>}
      {shows('past_medication') && c.past_medication && <section><h3>Past medication</h3><p>{c.past_medication}</p></section>}
      {shows('examination') && generalExam && <section><h3>General examination</h3><p className="capitalise">{generalExam}</p></section>}
      {shows('examination') && c.findings && <section><h3>Systemic examination</h3><p>{c.findings}</p></section>}
      {shows('results') && c.investigations?.length ? (
        <section>
          <h3>Test results</h3>
          <p>
            {c.investigations.map((r, i) => (
              <span key={i}>
                {i > 0 && '; '}
                {r.name}
                {r.result ? `: ${r.result}` : ''}
                {r.date ? ` (${longDate(r.date)})` : ''}
              </span>
            ))}
          </p>
        </section>
      ) : null}
      {shows('diagnosis') && (c.diagnosis || c.diagnosis_codes?.length) && (
        <section>
          <h3>Diagnosis</h3>
          {c.diagnosis && <p>{c.diagnosis}</p>}
          {c.diagnosis_codes?.length ? <p className="icd-line">ICD-10: {c.diagnosis_codes.map((d) => `${d.code} ${d.name}`).join('; ')}</p> : null}
        </section>
      )}
      {shows('plan') && c.assessment_plan && <section><h3>Assessment / plan</h3><p>{c.assessment_plan}</p></section>}

      {c.medicines.length > 0 && (
        <section>
          <h3>℞ Medicines</h3>
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Medicine</th>
                {column('dose') && <th>Dose</th>}
                {column('frequency') && <th>Frequency</th>}
                {column('timing') && <th>When</th>}
                {column('duration') && <th>Duration</th>}
              </tr>
            </thead>
            <tbody>
              {c.medicines.map((m, i) => (
                <tr key={m.id}>
                  <td>{i + 1}</td>
                  <td>
                    <strong>{m.medicine}</strong>
                    {column('instructions') && m.instructions && <small className="block">{tr(m.instructions)}</small>}
                  </td>
                  {column('dose') && <td>{tr(m.dose)}</td>}
                  {column('frequency') && <td>{tr(m.frequency)}</td>}
                  {column('timing') && <td>{tr(m.timing)}</td>}
                  {column('duration') && <td>{tr(m.duration)}</td>}
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}

      {shows('tests') && c.tests_advised && <section><h3>Tests advised</h3><p>{c.tests_advised}</p></section>}
      {shows('advice') && c.advice && <section><h3>Advice</h3><p className="report-text">{c.advice.split('\n').map(tr).join('\n')}</p></section>}
      {shows('refer') && c.refer_to && <section><h3>Referred to</h3><p>{c.refer_to}</p></section>}
      {shows('next_visit') && c.follow_up_on && <section><h3>Next visit</h3><p>{longDate(c.follow_up_on)}</p></section>}
      {(c.amendments?.length ?? 0) > 0 && (
        <section>
          <h3>Amendments</h3>
          {c.amendments!.map((a) => (
            <p key={a.id} className="report-text">
              {stampDate(a.created_at)}, {a.author?.name ?? 'Staff'}: {a.body}
            </p>
          ))}
        </section>
      )}

      <div className="signature">
        {signature && <img src={signature} alt="" />}
        {seal ? <img className="seal" src={seal} alt="" /> : <strong>{visit.doctor.signature_text || visit.doctor.name}</strong>}
      </div>
      {!layout?.letterhead && <ClinicFooter />}
    </div>
  )
}
