import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'
import { applyAppearance, remembered } from './appearance'

// Wear the remembered look before anything is drawn, so the page does not flash the standard colours first.
applyAppearance(remembered())

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
