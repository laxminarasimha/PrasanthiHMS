import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
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

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
