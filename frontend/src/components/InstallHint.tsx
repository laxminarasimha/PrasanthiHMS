import { useState } from 'react'
import { INSTALL_LATER as KEY, installPutOff as putOff, onIphone, runningInstalled, useInstall } from '../install'
import { usePhone } from '../phone'

const WEEK = 7 * 24 * 60 * 60 * 1000

/**
 * On a phone's browser, a strip offering to put the app on the home screen, because only the installed app opens full
 * screen without the address bar. Not shown inside the installed app, nor on a computer. "Later" hides it for a week.
 */
export function InstallHint() {
  const phone = usePhone()
  const { ready, install } = useInstall()
  const [later, setLater] = useState(putOff)
  // Shown when the browser cannot install at one tap: the taps to make by hand.
  const [how, setHow] = useState(false)
  const [done, setDone] = useState(false)

  if (!phone || later || done || runningInstalled()) return null

  /** Hidden from now for a week; for good once the app is installed. */
  const hideFrom = (when: number) => {
    try {
      localStorage.setItem(KEY, String(when))
    } catch {
      // Without storage it is only hidden until the page is loaded again.
    }
  }
  const press = async () => {
    if (!ready) return setHow(true)
    if (await install()) {
      setDone(true)
      // Installed: the browser's copy of the page need not offer it again.
      hideFrom(Date.now() + 520 * WEEK)
    }
  }
  const notNow = () => {
    setLater(true)
    hideFrom(Date.now())
  }

  return (
    <div className="install-hint no-print">
      <div>
        <strong>Install this app on your phone</strong>
        <span>It then opens full screen from its own icon, without the address bar.</span>
        {how && (
          <span className="install-steps">
            {onIphone() ? (
              <>
                In Safari, tap the <b>Share</b> button at the bottom, then <b>Add to Home Screen</b>.
              </>
            ) : (
              <>
                Tap <b>⋮</b> at the top right of the browser, then <b>Add to Home screen</b> or <b>Install app</b>.
              </>
            )}{' '}
            After that, open it from the new icon.
          </span>
        )}
      </div>
      <div className="install-actions">
        {(ready || !how) && (
          <button type="button" className="btn primary" onClick={() => void press()}>
            {ready ? 'Install' : 'Show me how'}
          </button>
        )}
        <button type="button" className="link" onClick={notNow}>
          Later
        </button>
      </div>
    </div>
  )
}
