import { defineConfig, devices } from '@playwright/test'

// `E2E_BASE_URL` lets the TMS SaaS e2e suite point at the built PWA served by the
// fixpay-frontend container (ingress: app.fixpay.test, host escape hatch :8082)
// instead of the local Vite dev server.
const baseURL = process.env.E2E_BASE_URL || 'http://localhost:5173'
const headless = process.env.E2E_HEADLESS ? process.env.E2E_HEADLESS !== 'false' : false

export default defineConfig({
  testDir: './e2e',
  timeout: 60_000,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL,
    headless,
    viewport: { width: 390, height: 844 }, // iPhone 14 Pro
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
})
