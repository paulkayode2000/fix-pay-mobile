import React from 'react'
import ReactDOM from 'react-dom/client'
import { QueryClientProvider } from '@tanstack/react-query'
import { queryClient } from '@/lib/query-client'
import App from './App'
import './index.css'

// Reset the splash guard on every fresh page load so the splash always plays.
sessionStorage.removeItem('splash_shown')

// ── One-time migration ────────────────────────────────────────────────────
try {
  const raw = localStorage.getItem('fixpay-auth')
  if (raw) {
    const stored = JSON.parse(raw)
    const state = stored?.state
    if (state?.isAuthenticated && state?.user?.kycStatus === 'VERIFIED') {
      let dirty = false
      if (!state.kycCompleted) { state.kycCompleted = true; dirty = true }
      if (state.isAuthenticated && !state.pinCreated) { state.pinCreated = true; dirty = true }
      if (dirty) localStorage.setItem('fixpay-auth', JSON.stringify(stored))
    }
  }
} catch { /* ignore */ }

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <QueryClientProvider client={queryClient}>
      <App />
    </QueryClientProvider>
  </React.StrictMode>
)