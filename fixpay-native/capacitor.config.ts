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
 */
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
    // Android WebView serves the bundle over https://localhost (iOS uses
    // capacitor://localhost). Both origins are on the Laravel CORS allow-list.
    androidScheme: 'https',
    cleartext: false,
  },
}

export default config
