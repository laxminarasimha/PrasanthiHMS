import { useEffect, useState } from 'react'
import { api } from '../api'
import type { BillingPreferences } from '../types'

const CACHE_KEY = 'hms_billing'
const DEFAULTS: BillingPreferences = { default_payment_mode: 'cash', pay_timing: 'before', ipd_prefix: 'IP' }

/** How the front desk bills: the usual payment mode, and whether a visit is paid for at booking. The last copy seen is used at once. */
export function useBillingPreferences(): BillingPreferences {
  const [prefs, setPrefs] = useState<BillingPreferences>(() => {
    try {
      return { ...DEFAULTS, ...JSON.parse(localStorage.getItem(CACHE_KEY) ?? '{}') }
    } catch {
      return DEFAULTS
    }
  })

  useEffect(() => {
    api.get<BillingPreferences>('/billing-preferences').then((res) => {
      setPrefs(res.data)
      try {
        localStorage.setItem(CACHE_KEY, JSON.stringify(res.data))
      } catch {
        // Not being able to store it only means the next screen fetches it again.
      }
    })
  }, [])

  return prefs
}
