import { Link } from 'react-router-dom'

type Props = {
  /** Medicines in stock that have a rack. */
  placed: number
  /** All medicines in stock. */
  inStock: number
  /** Whether to offer the way to the Racks and boxes screen (not needed on that screen itself). */
  link?: boolean
}

/**
 * How far the pharmacy has got with giving every medicine in stock a rack and box. Until a medicine has a place,
 * the billing screen cannot tell the counter where to find it, so this is shown until the work is finished.
 */
export function PlaceProgress({ placed, inStock, link }: Props) {
  if (inStock === 0) return null
  const left = inStock - placed
  const share = Math.round((placed / inStock) * 100)
  return (
    <div className={`place-progress ${left === 0 ? 'done' : ''}`}>
      <p>
        <strong>
          {placed} of {inStock}
        </strong>{' '}
        medicines in stock have a rack or box ({share}%).{' '}
        {left === 0 ? 'Every one can be found from the billing screen.' : `${left} still to place: until then the billing screen cannot say where ${left === 1 ? 'it is' : 'they are'}.`}{' '}
        {link && left > 0 && <Link to="/pharmacy/shelves">Place them</Link>}
      </p>
      <div className="bar" role="progressbar" aria-label="Medicines in stock with a place" aria-valuemin={0} aria-valuemax={inStock} aria-valuenow={placed}>
        <i style={{ width: `${share}%` }} />
      </div>
    </div>
  )
}
