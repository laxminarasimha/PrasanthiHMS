/**
 * This browser's own side of notifications: the part of the app that stays awake to show them, and the arrangement the
 * browser has with its notification service. Nothing here asks the hospital's server anything, so it can still be used
 * once the person has been signed out and the server no longer knows who is asking.
 */

export const able = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window

/** The part of the app that stays awake to show a notification. It is started as the page loads, so it may not be there yet. */
export async function worker(): Promise<ServiceWorkerRegistration | null> {
  if (!able()) return null
  const waited = new Promise<null>((done) => window.setTimeout(() => done(null), 5000))
  return Promise.race([navigator.serviceWorker.ready, waited]).catch(() => null)
}

/** The arrangement this browser has with its notification service, if it has one. */
export const held = async (reg: ServiceWorkerRegistration | null | undefined) => (await reg?.pushManager.getSubscription().catch(() => null)) ?? null

/**
 * Ends the arrangement on this browser alone: nothing more is shown here, whoever it was noted for on the server. The
 * server forgets the device the next time it tries to send to it, because the notification service then refuses it.
 */
export async function dropHere(sub: PushSubscription): Promise<void> {
  await sub.unsubscribe().catch(() => undefined)
}

/**
 * The person was signed out from elsewhere, or after a long time unused: this device stops showing their notifications
 * at once, so the next person to use it is not shown them. Only the browser's own arrangement is ended. It never holds
 * up the way to the sign-in page for more than a moment.
 */
export async function stopNoticesHere(): Promise<void> {
  if (!able()) return
  const ended = navigator.serviceWorker
    .getRegistration()
    .then(held)
    .then((sub) => (sub ? dropHere(sub) : undefined))
    .catch(() => undefined)
  await Promise.race([ended, new Promise((done) => window.setTimeout(done, 3000))])
}
