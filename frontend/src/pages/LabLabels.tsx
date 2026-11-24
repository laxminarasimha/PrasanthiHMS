import JsBarcode from 'jsbarcode'
import { useEffect, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api } from '../api'
import { clock, fullName } from '../format'
import { useAutoPrint } from '../printPaper'
import type { LabOrder, LabOrderItem, LabSettings } from '../types'

type Tube = { sample: string; outside: string; tests: string[]; taken: string | null }

/** One sticker for each tube: tests share a tube when they need the same kind of sample and go to the same place. */
function tubes(items: LabOrderItem[]): Tube[] {
  const list: Tube[] = []
  for (const item of items) {
    const sample = (item.sample_type ?? '').trim()
    const outside = (item.outside_lab ?? '').trim()
    const tube = list.find((t) => t.sample.toLowerCase() === sample.toLowerCase() && t.outside.toLowerCase() === outside.toLowerCase())
    if (tube) {
      tube.tests.push(item.test_name)
      if (item.collected_at && (!tube.taken || item.collected_at < tube.taken)) tube.taken = item.collected_at
    } else {
      list.push({ sample, outside, tests: [item.test_name], taken: item.collected_at })
    }
  }
  return list
}

/** "2026-11-24 16:31" -> "24 Nov 4:31 PM": the year is left out, a sticker has no room for it and a sample lives a few days. */
const when = (at: string) => `${new Date(at.replace(' ', 'T')).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })} ${clock(at)}`

/** The order number as a barcode, so a scanner pointed at the tube types it. */
function Barcode({ value }: { value: string }) {
  const svg = useRef<SVGSVGElement>(null)
  useEffect(() => {
    if (svg.current) JsBarcode(svg.current, value, { format: 'CODE128', displayValue: false, margin: 0, width: 2, height: 40 })
  }, [value])
  // Stretched to the width of the sticker: the bars keep their proportions to one another, which is all a scanner reads.
  return <svg ref={svg} className="label-barcode" preserveAspectRatio="none" aria-label={`Barcode of ${value}`} />
}

/**
 * The stickers for the sample tubes of one lab order: the patient, the order number, the kind of sample and the
 * tests it is for. Each sticker is its own page, at the size set in the lab's preferences, for a label printer.
 */
export function LabLabels() {
  const { id } = useParams()
  const [order, setOrder] = useState<LabOrder | null>(null)
  const [settings, setSettings] = useState<LabSettings | null>(null)

  useEffect(() => {
    api.get<LabOrder>(`/lab/orders/${id}`).then((res) => setOrder(res.data))
    api.get<LabSettings>('/lab/settings').then((res) => setSettings(res.data))
  }, [id])
  useAutoPrint(Boolean(order && settings), 600)

  if (!order || !settings) return <p className="loading">Loading labels…</p>

  const [width, height] = [settings.label_width_mm || 50, settings.label_height_mm || 25]
  const labels = tubes(order.items)
  const patient = order.patient

  return (
    <div className="label-sheet">
      <style>{`@media print { @page { size: ${width}mm ${height}mm; margin: 0; } }`}</style>
      <div className="label-bar no-print">
        <div>
          <strong>Sample labels · {order.order_no}</strong>
          <small className="muted block">
            {labels.length === 1 ? '1 label' : `${labels.length} labels, one for each sample`} · {width} × {height} mm. The size is set in Lab → Preferences. In the print window choose the label printer.
          </small>
        </div>
        <button className="btn primary" onClick={() => window.print()}>
          Print labels
        </button>
      </div>
      {labels.map((tube, i) => {
        const at = tube.taken ?? order.ordered_at
        return (
          <div className="sample-label" key={i} style={{ width: `${width}mm`, height: `${height}mm`, ['--bar' as string]: `${Math.max(4.5, Math.round(height * 2.2) / 10)}mm` }}>
            <strong className="label-name">{fullName(patient)}</strong>
            <span className="label-line">
              {patient.age_label} / {patient.gender.charAt(0).toUpperCase()} · {patient.uhid}
            </span>
            <span className="label-line">
              <b>{order.order_no}</b> · {tube.sample || 'Sample'} · {when(at)}
            </span>
            <span className="label-tests">
              {tube.outside ? `For ${tube.outside}: ` : ''}
              {tube.tests.join(', ')}
            </span>
            {settings.label_barcode && <Barcode value={order.order_no} />}
          </div>
        )
      })}
    </div>
  )
}
