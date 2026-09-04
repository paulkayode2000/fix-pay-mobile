import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { SESSION_EXPIRED_EVENT } from '@/lib/platform'

/**
 * Native (Capacitor) shell session-expiry bridge.
 *
 * The web build handles a 401 by forcing `window.location` to /auth/login
 * (a hard reload that clears in-memory state). A raw location change inside
 * the Capacitor WebView can hit the local asset server without an SPA
 * fallback, so the native build dispatches a window event instead and we
 * navigate with the React Router here.
 *
 * Rendered above every route. Safe no-op on web — the event is never fired.
 */
export function SessionExpiryHandler() {
  const navigate = useNavigate()

  useEffect(() => {
    const handler = () => navigate('/auth/login', { replace: true })
    window.addEventListener(SESSION_EXPIRED_EVENT, handler)
    return () => window.removeEventListener(SESSION_EXPIRED_EVENT, handler)
  }, [navigate])

  return null
}
