/*
 * The service worker: what lets a phone offer "Add to Home Screen" and open the app in its own full window.
 *
 * It keeps one thing only: a small "no internet" page, shown when the app is opened with no connection.
 * It never keeps or answers anything under /api, so no patient's details are ever stored on the phone by it,
 * and it never keeps the app's own pages or files either: those always come fresh from the server, so a new
 * release is never held back by an old copy.
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
