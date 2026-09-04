/**
 * Platform detection helper.
 *
 * The exact same `src/` ships as:
 *  - the hosted PWA (web)      -> Vite mode "production" / "mock" / "e2e"
 *  - the native mobile app     -> Vite mode "native" (built for Capacitor)
 *
 * All native-only behaviour must be gated through IS_NATIVE so the web
 * bundle is completely unaffected (same screens, same backend, same results).
 */

/** Current Vite build mode (development | production | mock | e2e | native). */
export const APP_MODE: string = import.meta.env.MODE

/** True only for the native (Capacitor) build. */
export const IS_NATIVE: boolean = import.meta.env.MODE === 'native'

/**
 * Window event dispatched by the native build when an authenticated session
 * expires (HTTP 401). The web build keeps its hard `window.location` reload;
 * the Capacitor WebView routes back to /auth/login through React Router
 * instead (see SessionExpiryHandler). No-op on web.
 */
export const SESSION_EXPIRED_EVENT = 'fixpay:session-expired'
