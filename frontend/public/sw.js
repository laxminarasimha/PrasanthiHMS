/*
 * The service worker: what lets a phone offer "Add to Home Screen" and open the app in its own full window.
 *
 * It keeps one thing only: a small "no internet" page, shown when the app is opened with no connection.
 * It never keeps or answers anything under /api, so no patient's details are ever stored on the phone by it,
 * and it never keeps the app's own pages or files either: those always come fresh from the server, so a new
 * release is never held back by an old copy.
 *
 * It also shows the notifications the hospital sends to the person signed in on this device: a new appointment, a
 * task given to them, their leave decided and the like. A notification arrives already written; it is shown and
 * nothing of it is kept here.
 */
const CACHE = 'hms-shell-1'
const OFFLINE = '/offline.html'

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.add(new Request(OFFLINE, { cache: 'reload' }))))
  self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((names) => Promise.all(names.filter((name) => name !== CACHE).map((name) => caches.delete(name))))
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('fetch', (event) => {
  // Only the opening of a page is looked at. Everything else goes to the network untouched.
  if (event.request.mode !== 'navigate') return
  event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE).then((page) => page ?? Response.error())))
})

self.addEventListener('push', (event) => {
  let note = {}
  try {
    note = event.data ? event.data.json() : {}
  } catch {
    note = {}
  }
  const shown = self.registration.showNotification(note.title || 'Prasanthi HMS', {
    body: note.body || '',
    icon: '/icon-192.png',
    badge: '/icon-192.png',
    // A second notice about the same thing takes the place of the first instead of piling up.
    tag: note.tag || undefined,
    renotify: Boolean(note.tag),
    data: { url: note.url || '/' },
  })
  // An open screen is told as well, so its list can load again without waiting.
  const told = self.clients
    .matchAll({ type: 'window', includeUncontrolled: true })
    .then((open) => open.forEach((client) => client.postMessage({ type: 'hms-push', kind: note.kind || '' })))
    .catch(() => undefined)
  event.waitUntil(Promise.all([shown, told]))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const target = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin)
  // Only a page of this app is ever opened inside an open window.
  const page = target.origin === self.location.origin ? target.pathname + target.search + target.hash : null
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((open) => {
      const windows = open.filter((client) => 'focus' in client)
      const app = windows.find((client) => client.focused) || windows.find((client) => client.visibilityState === 'visible') || windows[0]
      if (!app) return self.clients.openWindow(target.href)
      // With the app already open it is brought to the front and asked to go to the page. The app goes there only if
      // nothing being written would be lost: otherwise it asks first, and staying leaves everything as it was.
      return app
        .focus()
        .catch(() => app)
        .then((shown) => page && (shown || app).postMessage({ type: 'hms-open', url: page }))
    }),
  )
})
