import { useEffect, useRef, useState } from 'react'

type Gsi = {
  accounts: {
    id: {
      initialize: (options: { client_id: string; callback: (response: { credential?: string }) => void; ux_mode?: 'popup'; auto_select?: boolean; cancel_on_tap_outside?: boolean }) => void
      renderButton: (parent: HTMLElement, options: Record<string, unknown>) => void
      disableAutoSelect: () => void
    }
  }
}

const SCRIPT = 'https://accounts.google.com/gsi/client'
let loading: Promise<Gsi> | null = null

/** Google's own sign-in script, fetched once and only on a page that shows the button. */
function loadGoogle(): Promise<Gsi> {
  const ready = (window as unknown as { google?: Gsi }).google
  if (ready?.accounts?.id) return Promise.resolve(ready)
  loading ??= new Promise<Gsi>((resolve, reject) => {
    const script = document.createElement('script')
    script.src = SCRIPT
    script.async = true
    script.onload = () => resolve((window as unknown as { google: Gsi }).google)
    script.onerror = () => {
      loading = null
      reject(new Error('Google could not be reached'))
    }
    document.head.appendChild(script)
  })
  return loading
}

/**
 * Google's "Sign in with Google" button. Google shows its own window to choose an account, then hands back a signed
 * token, which the server checks before anything else happens. Nothing here sees a Google password.
 */
export function GoogleButton({ clientId, text = 'signin_with', onCredential }: { clientId: string; text?: 'signin_with' | 'continue_with'; onCredential: (credential: string) => void }) {
  const box = useRef<HTMLDivElement>(null)
  const latest = useRef(onCredential)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    latest.current = onCredential
  }, [onCredential])

  useEffect(() => {
    let current = true
    loadGoogle()
      .then((google) => {
        if (!current || !box.current) return
        google.accounts.id.initialize({ client_id: clientId, ux_mode: 'popup', auto_select: false, cancel_on_tap_outside: true, callback: (response) => response.credential && latest.current(response.credential) })
        google.accounts.id.renderButton(box.current, { type: 'standard', theme: 'outline', size: 'large', shape: 'pill', text, logo_alignment: 'left', width: Math.min(box.current.offsetWidth || 320, 400) })
      })
      .catch(() => current && setFailed(true))
    return () => {
      current = false
    }
  }, [clientId, text])

  if (failed) return <p className="muted small">Google sign-in could not be loaded. Check the internet connection, or sign in with your password.</p>
  return <div ref={box} className="google-button" />
}
