import { useBrand } from '../brand'

/** The mark beside the app's name: the hospital's own logo once one has been uploaded, otherwise the plain cross. */
export function BrandMark({ large = false }: { large?: boolean }) {
  const brand = useBrand()
  if (brand?.logo) return <img className={large ? 'brand-logo large' : 'brand-logo'} src={brand.logo} alt={brand.name} />
  if (large) return null
  return (
    <span className="brand-mark" aria-hidden="true">
      <svg viewBox="0 0 24 24">
        <path d="M9.5 3h5v6.5H21v5h-6.5V21h-5v-6.5H3v-5h6.5z" />
      </svg>
    </span>
  )
}
