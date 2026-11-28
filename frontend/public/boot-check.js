/*
 * Runs before the app, in the oldest kind of script every phone understands. If the app cannot start on a phone,
 * the person sees why and what to do, instead of a blank white page: most often a Chrome that has not been updated
 * for years. It sends nothing anywhere; it only looks at this page.
 */
(function () {
  var shown = null
  var firstProblem = ''

  // Runs a step that may be refused on some phones, and carries on either way.
  function attempt(step) {
    try {
      step()
      return null
    } catch (problem) {
      return problem
    }
  }

  function remove(node) {
    if (node && node.parentNode) node.parentNode.removeChild(node)
  }

  function root() {
    return document.getElementById('root')
  }

  function started() {
    var r = root()
    return Boolean(r && r.childNodes.length > 0)
  }

  function browser() {
    var ua = navigator.userAgent || ''
    var chrome = ua.match(/Chrome\/(\d+)/)
    var android = ua.match(/Android ([\d.]+)/)
    var parts = []
    if (chrome) parts.push('Chrome ' + chrome[1])
    if (android) parts.push('Android ' + android[1])
    return parts.length ? parts.join(' · ') : ua
  }

  // Too old: a Chrome from before 2018, or code this browser could not read on a Chrome from before 2020 (or one
  // that does not say its version). A new Chrome that could not read the code has some other trouble.
  function tooOld() {
    var chrome = (navigator.userAgent || '').match(/Chrome\/(\d+)/)
    var version = chrome ? Number(chrome[1]) : null
    var unreadable = /SyntaxError|Unexpected token|Unexpected identifier|Invalid or unexpected/i.test(firstProblem)
    return (version !== null && version < 64) || (unreadable && (version === null || version < 85))
  }

  function resetThisPhone() {
    var done = function () {
      attempt(function () { localStorage.clear() })
      attempt(function () { sessionStorage.clear() })
      window.location.assign('/')
    }
    var steps = []
    attempt(function () {
      if (navigator.serviceWorker && navigator.serviceWorker.getRegistrations) {
        steps.push(navigator.serviceWorker.getRegistrations().then(function (list) {
          return Promise.all(list.map(function (w) { return w.unregister() }))
        }))
      }
      if (window.caches && caches.keys) {
        steps.push(caches.keys().then(function (names) {
          return Promise.all(names.map(function (n) { return caches.delete(n) }))
        }))
      }
    })
    if (window.Promise && steps.length) Promise.all(steps).then(done, done)
    else done()
  }

  function button(text, primary, onClick) {
    var b = document.createElement('button')
    b.type = 'button'
    b.textContent = text
    b.style.cssText = 'font:inherit;font-weight:600;padding:10px 16px;margin:6px 8px 0 0;border-radius:9px;cursor:pointer;' +
      (primary ? 'background:#6b2c91;color:#fff;border:1px solid #6b2c91;' : 'background:#fff;color:#6b2c91;border:1px solid #c9b3d9;')
    b.onclick = onClick
    return b
  }

  function show(old) {
    if (started()) return
    remove(shown)
    var box = document.createElement('div')
    box.setAttribute('role', 'alert')
    box.style.cssText = 'font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;max-width:560px;margin:40px auto;padding:20px;color:#1f1a24;line-height:1.5;'
    var h = document.createElement('h1')
    h.style.cssText = 'font-size:1.3rem;margin:0 0 10px;color:#6b2c91;'
    h.textContent = 'Prasanthi HMS could not start on this phone'
    box.appendChild(h)
    var p = document.createElement('p')
    p.style.margin = '0 0 10px'
    p.textContent = old
      ? 'This phone’s Chrome is too old for the app. Open the Play Store, update Google Chrome (and Android System WebView, if it is listed), then open the site again.'
      : 'Something stopped the app from starting. Try again. If it still does not open, use “Reset the app on this phone” (you will sign in again afterwards), or send a photo of this page to the office.'
    box.appendChild(p)
    box.appendChild(button('Try again', true, function () { window.location.reload() }))
    box.appendChild(button('Reset the app on this phone', false, resetThisPhone))
    var d = document.createElement('p')
    d.style.cssText = 'margin:16px 0 0;font-size:0.8rem;color:#6b6472;word-break:break-word;'
    d.textContent = browser() + (firstProblem ? ' — ' + firstProblem : '')
    box.appendChild(d)
    document.body.appendChild(box)
    shown = box
  }

  function check() {
    if (!started()) show(tooOld())
  }

  // A browser too old to run the app's kind of script at all.
  if (!('noModule' in document.createElement('script'))) {
    firstProblem = 'This browser cannot run the app'
    document.addEventListener('DOMContentLoaded', function () { show(true) })
    return
  }

  // A problem before the app has drawn anything: a file that would not load, or code this browser cannot read.
  window.addEventListener('error', function (e) {
    if (started() || firstProblem) return
    var target = e.target
    if (target && target !== window && (target.src || target.href)) firstProblem = 'Could not load ' + (target.src || target.href)
    else firstProblem = e.message || (e.error && e.error.message) || 'An error'
    setTimeout(check, 1500)
  }, true)
  window.addEventListener('unhandledrejection', function (e) {
    if (started() || firstProblem) return
    firstProblem = (e.reason && (e.reason.message || String(e.reason))) || 'An error'
    setTimeout(check, 1500)
  })

  // Once everything has loaded, the app has had its chance to draw; a page still empty a few seconds later is stuck.
  window.addEventListener('load', function () { setTimeout(check, 4000) })

  // When the app does start after all (a slow connection), the message goes away by itself.
  if (window.MutationObserver) {
    document.addEventListener('DOMContentLoaded', function () {
      var r = root()
      if (!r) return
      new MutationObserver(function () {
        if (started() && shown) {
          remove(shown)
          shown = null
        }
      }).observe(r, { childList: true })
    })
  }
})()
