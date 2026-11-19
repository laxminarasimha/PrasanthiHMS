import { useEffect, useState, type ReactNode } from 'react'
import { useParams, useSearchParams } from 'react-router-dom'
import { api } from '../api'
import { ClinicFooter, ClinicHeader } from '../components/ClinicHeader'
import { useSignature } from '../components/useSignature'
import { clock, longDate, fullName } from '../format'
import type { LabOrder, LabOrderItem, LabSettings } from '../types'

const stamp = (at: string) => `${longDate(at)}, ${clock(at)}`
const earliest = (times: (string | null)[]) => (times.filter(Boolean) as string[]).sort()[0]
const latest = (times: (string | null)[]) => (times.filter(Boolean) as string[]).sort().pop()

type Group = { department: string; items: LabOrderItem[] }

/** The tests under their departments, the departments in the lab's own order; tests filed nowhere come last. */
function byDepartment(items: LabOrderItem[], order: string[]): Group[] {
  const place = (name: string) => {
    const at = order.findIndex((d) => d.toLowerCase() === name.toLowerCase())
    return name === '' ? Number.MAX_SAFE_INTEGER : at === -1 ? order.length : at
  }
  const groups: Group[] = []
  for (const item of items) {
    const department = (item.department ?? '').trim()
    const group = groups.find((g) => g.department.toLowerCase() === department.toLowerCase())
    if (group) group.items.push(item)
    else groups.push({ department, items: [item] })
  }
  // A stable sort: departments the lab has not listed keep the order their tests were ordered in.
  return groups.map((g, i) => ({ g, i })).sort((a, b) => place(a.g.department) - place(b.g.department) || a.i - b.i).map(({ g }) => g)
}

/**
 * The lab report as given to the patient: the patient's details, then the results section by section
 * (haematology, biochemistry and so on), then who reported them.
 *
 * `?letterhead=1` leaves the top and bottom of each page blank for pre-printed paper; `?items=1,2` prints only
 * those tests; `?tabular=1` puts everything in one compact table; `?with=8,9` adds the same patient's other
 * orders, so a day's tests come out as one report instead of one for each order.
 */
export function LabReportPrint() {
  const { id } = useParams()
  const [params] = useSearchParams()
  const [orders, setOrders] = useState<LabOrder[] | null>(null)
  const [settings, setSettings] = useState<LabSettings | null>(null)
  const others = params.get('with') ?? ''

  useEffect(() => {
    const ids = [...new Set([Number(id), ...others.split(',').map(Number).filter((n) => n > 0)])]
    Promise.all([api.get<LabSettings>('/lab/settings'), ...ids.map((one) => api.get<LabOrder>(`/lab/orders/${one}`))]).then(([s, ...list]) => {
      setSettings(s.data as LabSettings)
      setOrders(list.map((o) => o.data as LabOrder).sort((a, b) => a.ordered_at.localeCompare(b.ordered_at) || a.id - b.id))
    })
  }, [id, others])

  // Several orders are read as one: their numbers side by side, the earliest time, and every test of all of them.
  const mixed = Boolean(orders && orders.some((o) => o.patient.id !== orders[0].patient.id))
  const owing = (orders ?? []).filter((o) => o.bill && o.bill.due > 0)
  const order: LabOrder | null =
    orders && orders.length > 0
      ? {
          ...orders[0],
          order_no: orders.map((o) => o.order_no).join(', '),
          referred_by: [...new Set(orders.map((o) => o.referred_by).filter(Boolean))].join(', ') || null,
          items: orders.flatMap((o) => o.items),
        }
      : null

  const letterhead = params.get('letterhead') === '1'
  const tabular = params.get('tabular') === '1'
  const only = params.get('items')?.split(',').map(Number)
  const ready = (order?.items ?? []).filter((item) => (settings?.approval_required ? item.approved_at : item.reported_at))
  const printed = ready.filter((item) => !only || only.includes(item.id))
  const unpaid = Boolean(order && settings && !settings.allow_print_without_payment && owing.length > 0)
  const canPrint = printed.length > 0 && !unpaid
  const lastPrinted = printed[printed.length - 1]
  const signerSignature = useSignature((lastPrinted?.approver ?? lastPrinted?.reporter)?.id)

  useEffect(() => {
    if (!canPrint) return
    const timer = setTimeout(() => window.print(), 800)
    return () => clearTimeout(timer)
  }, [canPrint])

  if (!order || !orders || !settings) return <p className="loading">Loading report…</p>
  if (mixed) return <p className="loading">These orders belong to different patients, so they cannot be printed as one report.</p>
  if (unpaid) {
    return <p className="loading">{orders.length > 1 ? `The bill for ${owing.map((o) => o.order_no).join(', ')} has to be paid before these reports can be printed.` : 'The bill for this order has to be paid before the report can be printed.'}</p>
  }
  if (printed.length === 0) {
    return <p className="loading">{settings.approval_required ? 'No test here has been approved yet.' : 'No test here has been marked as reported yet.'}</p>
  }

  const last = printed[printed.length - 1]
  const signer = last.approver ?? last.reporter
  const pending = order.items.length - ready.length
  const groups = settings.group_by_department ? byDepartment(printed, settings.departments ?? []) : [{ department: '', items: printed }]
  const named = groups.some((g) => g.department !== '')
  const heading = (g: Group) => (named ? g.department || 'Other tests' : '')
  const flagged = printed.some((item) => item.results?.some((r) => r.flag))
  // Each test says when it was collected and reported only when the tests on this report differ; otherwise once, at the top.
  const distinct = (pick: (i: LabOrderItem) => string | null) => new Set(printed.map((i) => pick(i)?.slice(0, 16) ?? '')).size > 1
  const perTestDates = distinct((i) => i.collected_at) || distinct((i) => i.reported_at)

  /** The patient's details and the dates of the tests shown beside them, in a ruled box. */
  const patientBox = (items: LabOrderItem[]) => {
    const collected = settings.print_sample_date ? earliest(items.map((i) => i.collected_at)) : undefined
    const reported = latest(items.map((i) => i.reported_at))
    const approved = settings.print_approved_date ? latest(items.map((i) => i.approved_at)) : undefined
    const rows: [string, ReactNode][] = [
      ['Patient', <strong key="name">{fullName(order.patient)}</strong>],
      ['Order no.', <strong key="no">{order.order_no}</strong>],
      ['Age / Sex', `${order.patient.age_label} / ${order.patient.gender.charAt(0).toUpperCase()}${order.patient.gender.slice(1)}`],
      ['Registered', stamp(order.ordered_at)],
      ['Patient ID', order.patient.uhid],
      ['Collected', collected ? stamp(collected) : '—'],
      ['Referred by', order.referred_by || 'Self'],
      ['Reported', reported ? stamp(reported) : '—'],
    ]
    if (approved) rows.push(['', ''], ['Approved', stamp(approved)])
    return (
      <dl className="lab-patient">
        {rows.map(([label, value], i) => (
          <div key={i}>
            <dt>{label}</dt>
            <dd>{value}</dd>
          </div>
        ))}
      </dl>
    )
  }

  /** One test: its name, then a line for each parameter, or its written report. */
  const testBody = (item: LabOrderItem) => (
    <tbody key={item.id} className="lab-test">
      <tr className="lab-test-name">
        <td colSpan={4}>
          {item.test_name}
          {item.sample_type && <small>Sample: {item.sample_type}</small>}
        </td>
      </tr>
      {perTestDates && (
        <tr className="lab-test-dates">
          <td colSpan={4}>
            {settings.print_sample_date && item.collected_at && <span>Collected {stamp(item.collected_at)}</span>}
            {item.reported_at && <span>Reported {stamp(item.reported_at)}</span>}
            {settings.print_approved_date && item.approved_at && <span>Approved {stamp(item.approved_at)}</span>}
          </td>
        </tr>
      )}
      {item.kind === 'text' ? (
        <tr>
          <td colSpan={4} className="report-text">
            {item.report_text}
          </td>
        </tr>
      ) : (
        (item.results ?? []).map((r) =>
          r.is_heading ? (
            <tr key={r.id} className="lab-heading">
              <td colSpan={4}>{r.parameter}</td>
            </tr>
          ) : (
            <tr key={r.id}>
              <td>
                {r.parameter}
                {settings.print_method && r.method && <small className="method">{r.method}</small>}
              </td>
              <td className={r.flag ? 'result abnormal' : 'result'}>
                {r.value}
                {r.flag && <span className="flag">{r.flag === 'high' ? 'H' : 'L'}</span>}
              </td>
              <td>{r.unit}</td>
              <td>{r.reference_range}</td>
            </tr>
          ),
        )
      )}
      {item.remarks && (
        <tr>
          <td colSpan={4} className="lab-comment">
            <strong>Comment:</strong> {item.remarks}
          </td>
        </tr>
      )}
    </tbody>
  )

  const columns = (
    <thead>
      <tr>
        <th>Investigation</th>
        <th>Result</th>
        <th>Unit</th>
        <th>Reference range</th>
      </tr>
    </thead>
  )

  // How the report is cut into pages: everything running on, a page for each department, or a page for each test.
  const pages: { key: string; items: LabOrderItem[]; body: ReactNode }[] = tabular
    ? [
        {
          key: 'all',
          items: printed,
          body: (
            <table className="lab-values">
              {columns}
              {groups.map((g) => (
                <FragmentRows key={g.department} title={heading(g)}>
                  {g.items.map(testBody)}
                </FragmentRows>
              ))}
            </table>
          ),
        },
      ]
    : settings.each_test_on_new_page
      ? groups.flatMap((g) =>
          g.items.map((item) => ({
            key: `t${item.id}`,
            items: [item],
            body: (
              <>
                {heading(g) && <h3 className="lab-dept">{heading(g)}</h3>}
                <table className="lab-values">
                  {columns}
                  {testBody(item)}
                </table>
              </>
            ),
          })),
        )
      : groups.map((g) => ({
          key: `d${g.department}`,
          items: g.items,
          body: (
            <>
              {heading(g) && <h3 className="lab-dept">{heading(g)}</h3>}
              <table className="lab-values">
                {columns}
                {g.items.map(testBody)}
              </table>
            </>
          ),
        }))
  const breaks = !tabular && (settings.each_test_on_new_page || settings.department_on_new_page)

  return (
    <div className="receipt prescription lab-report">
      {letterhead && <style>{`@media print { @page { margin: ${settings.letterhead_top_mm}mm 12mm ${settings.letterhead_bottom_mm}mm; } }`}</style>}
      {letterhead ? (
        <div className="letterhead-gap no-print" style={{ height: `${settings.letterhead_top_mm}mm` }}>
          Space left for the letterhead ({settings.letterhead_top_mm} mm)
        </div>
      ) : (
        <ClinicHeader name={settings.lab_name} address={settings.lab_address}>
          <h2>Laboratory report</h2>
        </ClinicHeader>
      )}
      {letterhead && <h2 className="lab-title">Laboratory report</h2>}

      {!breaks && patientBox(printed)}

      {pages.map((page, index) => (
        <section key={page.key} className={breaks && index > 0 ? 'new-page' : ''}>
          {/* A page that starts afresh says again whose report it is. */}
          {breaks && patientBox(page.items)}
          {page.body}
        </section>
      ))}

      {pending > 0 && <p className="lab-pending">{pending === 1 ? '1 test' : `${pending} tests`} {orders.length > 1 ? 'of these orders' : 'in this order'} {pending === 1 ? 'is' : 'are'} still pending and will follow.</p>}
      {flagged && <p className="lab-legend">Results outside the reference range are in bold: H = above the range, L = below it.</p>}
      {settings.print_end_mark && <p className="lab-end">End of report</p>}
      {settings.report_note && <p className="lab-note">{settings.report_note}</p>}

      <div className="lab-signatures">
        {([1, 2] as const).filter((slot) => settings[`technician_${slot}`]).map((slot) => (
          <div key={slot}>
            {settings[`technician_${slot}_signature`] && <img src={settings[`technician_${slot}_signature`]!} alt="" />}
            <strong>{settings[`technician_${slot}`]}</strong>
            <br />
            <small>Lab technician</small>
          </div>
        ))}
        <div className="right">
          {signerSignature && <img src={signerSignature} alt="" />}
          <strong>{signer?.signature_text || signer?.name}</strong>
          <br />
          <small>{last.approver ? 'Approved by' : 'Reported by'}</small>
        </div>
      </div>
      {!letterhead && <ClinicFooter />}
    </div>
  )
}

/** In the compact layout a department is a banded row inside the one table, followed by its tests. */
function FragmentRows({ title, children }: { title: string; children: ReactNode }) {
  return (
    <>
      {title && (
        <tbody>
          <tr className="lab-dept-row">
            <td colSpan={4}>{title}</td>
          </tr>
        </tbody>
      )}
      {children}
    </>
  )
}
