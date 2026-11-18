import { useEffect, useState } from 'react'
import { api } from './api'

/** The hospital's name and logo as the app itself wears them: at the top of every screen, on the sign-in page and on the browser tab. */
export type Brand = { name: string; logo: string | null }

const KEY = 'hms_brand'
const CHANGED = 'hms:brand'

const remembered = (): Brand | null => {
  try {
    return JSON.parse(localStorage.getItem(KEY) ?? 'null')
  } catch {
    return null
  }
}

/** Called when the logo is uploaded or removed under Setup, so the top of the app changes at once. */
export function rememberBrand(brand: Brand) {
  try {
    localStorage.setItem(KEY, JSON.stringify(brand))
  } catch {
    // A full or blocked store only means it is fetched again next time.
  }
  window.dispatchEvent(new Event(CHANGED))
}

/** The last copy seen is used at once, so the logo does not pop in a moment after the page. */
export function useBrand(): Brand | null {
  const [brand, setBrand] = useState<Brand | null>(remembered)

  useEffect(() => {
    const reread = () => setBrand(remembered())
    window.addEventListener(CHANGED, reread)
    api
      .get<Brand>('/brand')
      .then((res) => rememberBrand(res.data))
      .catch(() => undefined)
    return () => window.removeEventListener(CHANGED, reread)
  }, [])

  // The browser tab wears the logo too, when there is one.
  useEffect(() => {
    const icon = document.querySelector<HTMLLinkElement>('link[rel="icon"]')
    if (!icon) return
    if (brand?.logo) {
      icon.type = brand.logo.slice(5, brand.logo.indexOf(';'))
      icon.href = brand.logo
    } else {
      icon.type = 'image/svg+xml'
      icon.href = '/favicon.svg'
    }
  }, [brand?.logo])

  return brand
}
