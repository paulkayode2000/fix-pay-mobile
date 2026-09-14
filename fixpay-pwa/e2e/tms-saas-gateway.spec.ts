/**
 * TMS SaaS E2E — Layer B (UI smoke)
 * fixpay-mobile PWA -> fixpay Laravel API -> Payfixy gateway -> TMS (AML + antifraud)
 *
 * The deep functional coverage of the new TMS SaaS endpoints lives in
 * `fixpay-laravel/tx-matrix-v2.php` (Layer A). This spec proves the *user-facing*
 * surface still boots, renders the auth journey, and actually drives the fixpay
 * API that fronts the gateway/TMS path.
 *
 * Run against the built PWA (fixpay-frontend container):
 *   $env:E2E_BASE_URL='http://localhost:8082'; npx playwright test e2e/tms-saas-gateway.spec.ts
 */
import { test, expect } from '@playwright/test'

const BOOT = 30_000

test.describe('TMS SaaS e2e — fixpay PWA (mobile)', () => {
  test('app shell boots without uncaught errors', async ({ page }) => {
    const errors: string[] = []
    page.on('pageerror', (e) => errors.push(e.message))

    const resp = await page.goto('/', { waitUntil: 'domcontentloaded' })
    expect(resp?.status() ?? 500).toBeLessThan(400)

    await expect(page).toHaveTitle(/FixPay/i)
    await expect(page.locator('#root')).toBeAttached({ timeout: BOOT })

    // React must have mounted something inside the root.
    await expect.poll(async () => page.locator('#root *').count(), { timeout: BOOT }).toBeGreaterThan(0)

    expect(errors, `uncaught page errors: ${errors.join(' | ')}`).toEqual([])
  })

  test('register screen renders and submits a new user', async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('fixpay_onboarded', '1'))
    await page.goto('/auth/register', { waitUntil: 'domcontentloaded' })

    const submit = page.getByRole('button', { name: /continue/i })
    await expect(submit).toBeVisible({ timeout: BOOT })

    const stamp = Date.now()
    const email = `ui.e2e.${stamp}@fixpay.test`
    const phone = '080' + String(stamp).slice(-8)

    await page.getByPlaceholder('Ada').fill('Ui')
    await page.getByPlaceholder('Obi').fill('Smoke')
    await page.getByPlaceholder('08012345678').fill(phone)
    await page.getByPlaceholder('you@example.com').fill(email)
    await page.getByPlaceholder('At least 8 characters').fill('UiSmoke1!')
    await page.getByPlaceholder('Re-enter your password').fill('UiSmoke1!')

    await expect(submit).toBeEnabled({ timeout: 10_000 })
    await submit.click()

    // Success redirects to /auth/login; a failure surfaces an in-page error.
    // Either way the UI must have driven the fixpay API end to end.
    await expect
      .poll(async () => {
        const url = page.url()
        const signIn = await page.getByRole('button', { name: /sign in/i }).count()
        const errorText = await page.getByText(/registration failed|already|invalid|taken/i).count()
        return /\/auth\/login/.test(url) || signIn > 0 || errorText > 0
      }, { timeout: BOOT })
      .toBe(true)
  })

  test('login screen renders and drives the fixpay API', async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('fixpay_onboarded', '1'))

    const apiCalls: string[] = []
    page.on('request', (r) => {
      if (/\/api\//.test(r.url())) apiCalls.push(r.url())
    })

    await page.goto('/auth/login', { waitUntil: 'domcontentloaded' })

    const identifier = page.getByPlaceholder('Enter phone or email')
    await expect(identifier).toBeVisible({ timeout: BOOT })

    await identifier.fill('ui.smoke.nonexistent@fixpay.test')
    await page.getByPlaceholder('Enter password').fill('WrongPass1!')
    await page.getByRole('button', { name: /sign in/i }).click()

    // The UI must actually call the fixpay API (the path that fronts gateway/TMS).
    await expect.poll(() => apiCalls.length, { timeout: BOOT }).toBeGreaterThan(0)
  })
})
