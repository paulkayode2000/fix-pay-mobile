import type { CapacitorConfig } from '@capacitor/cli'

/**
 * FixPay — Capacitor native shell.
 *
 * Builds the exact source from ../fixpay-pwa (Vite mode "native") into
 * installable iOS / Android applications. Web assets are emitted to
 * ../fixpay-pwa/dist by `npm run build:native` and copied into each native
 * project by `npx cap sync`.
 *
 * Parity with the hosted PWA is structural: same src/, same Laravel backend,
 * same API contracts. Only the runtime origin differs (see ../fixpay-native/.env.native).
 *
 * ── Dev-only overrides (see README "Test on a real device") ─────────────
 *   CAP_DEV_URL=http://<your-pc-ip>:5273  → WebView loads the Vite dev server
 *                                          (live reload; NOT for release)
 *   CAP_CLEARTEXT=true                    → allow plain-http LAN traffic
 *                                          (backend / dev server during testing)
 *   VITE_API_URL is read from process env by Vite when set, overriding .env.native.
 *   All of these must stay OFF for release builds.
 */
const devServerUrl = process.env.CAP_DEV_URL?.trim() || ''
const allowCleartext = devServerUrl.length > 0 || process.env.CAP_CLEARTEXT === 'true'

const config: CapacitorConfig = {
  appId: 'com.fixpay.mobile',
  appName: 'FixPay',
  webDir: '../fixpay-pwa/dist',
  backgroundColor: '#ffffff',
  android: {
    allowMixedContent: false,
  },
  ios: {
    contentInset: 'automatic',
  },
  server: {
    // Dev live-reload: load the app from the Vite dev server instead of the bundle.
    ...(devServerUrl ? { url: devServerUrl } : {}),
    // Android WebView serves the bundle over https://localhost (iOS uses
    // capacitor://localhost). Both origins are on the Laravel CORS allow-list.
    androidScheme: 'https',
    // Android-only (iOS uses ATS in Info.plist). True only for LAN testing.
    cleartext: allowCleartext,
  },
}

export default config
