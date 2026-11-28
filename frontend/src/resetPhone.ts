/** Takes away what the app keeps on this phone (it never holds patient records) and starts it afresh. */
export async function resetThisPhone() {
  try {
    const workers = (await navigator.serviceWorker?.getRegistrations?.()) ?? []
    await Promise.all(workers.map((w) => w.unregister()))
  } catch {
    // Nothing to take away.
  }
  try {
    if ('caches' in window) await Promise.all((await caches.keys()).map((name) => caches.delete(name)))
  } catch {
    // Nothing to take away.
  }
  try {
    localStorage.clear()
    sessionStorage.clear()
  } catch {
    // Nothing to take away.
  }
  window.location.assign('/')
}
