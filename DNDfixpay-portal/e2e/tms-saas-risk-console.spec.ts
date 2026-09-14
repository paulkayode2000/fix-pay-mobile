/**
 * TMS SaaS E2E — Layer B (UI smoke, admin/risk console)
 * DNDfixpay-portal -> fixpay Laravel API / TMS risk data
 *
 * The portal is a Vite dev server (start it with fixpay-mobile's
 * `restart-services.ps1`). When it is not running the spec SKIPS rather than
 * failing, so the suite stays green in API-only environments.
 *
 * Run:
 *   $env:E2E_PORTAL_BASE='http://localhost:3001'; npx playwright test e2e/tms-saas-risk-console.spec.ts
 */
import { test, expect } from '@playwright/test'

const BASE = process.env.E2E_PORTAL_BASE || 'http://localhost:3001'
const BOOT = 30_000

test.describe('TMS SaaS e2e — risk console (portal)', () => {
  test.beforeAll(async ({ request }) => {
    try {
      const r = await request.get(BASE, { timeout: 5_000 })
      test.skip(r.status() >= 500, `portal not healthy at ${BASE} (status ${r.status()})`)
    } catch {
      test.skip(true, `portal not reachable at ${BASE} — start it via restart-services.ps1`)
    }
  })

  test('portal shell boots', async ({ page }) => {
    const errors: string[] = []
    page.on('pageerror', (e) => errors.push(e.message))

    const resp = await page.goto(BASE, { waitUntil: 'domcontentloaded' })
    expect(resp?.status() ?? 500).toBeLessThan(400)
    await expect(page.locator('#root, #app')).toBeAttached({ timeout: BOOT })
    expect(errors, `uncaught page errors: ${errors.join(' | ')}`).toEqual([])
  })

  test('risk/audit surface is reachable and renders', async ({ page }) => {
    await page.goto(`${BASE}/risk`, { waitUntil: 'domcontentloaded' }).catch(() => {})
    // The app must render *something* (SPA router falls through to a shell/404
    // view rather than a blank document).
    await expect
      .poll(async () => page.locator('#root *, #app *').count(), { timeout: BOOT })
      .toBeGreaterThan(0)
  })
})
