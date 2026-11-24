import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
// Before the app and its router: the guard for unsaved work listens for Back and Forward ahead of them.
import './unsaved'
// Before the app: the browser may offer to install it before any screen is drawn.
import './install'
import App from './App.tsx'
import { applyAppearance, remembered } from './appearance'

// Wear the remembered look before anything is drawn, so the page does not flash the standard colours first.
applyAppearance(remembered())

// Scrolling the page with the pointer resting on a number box would quietly change a price or a
// quantity. Letting go of the box first means the wheel only ever scrolls.
document.addEventListener('wheel', () => {
  const el = document.activeElement
  if (el instanceof HTMLInputElement && el.type === 'number') el.blur()
}, { passive: true })

// What lets a phone add the app to its home screen and open it full screen. It keeps no pages and no patient
// data (see public/sw.js), so failing to start only means the phone does not offer to install the app.
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => undefined)
  })
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
