import { http, HttpResponse, delay } from 'msw'

export const kycHandlers = [
  // ── NIN Verification (MOCKED) ──────────────────────────────────────
  http.post('/api/kyc/nin', async ({ request }) => {
    await delay(1200)
    const body = await request.json() as Record<string, string>
    if (!body.nin || body.nin.length !== 11) return HttpResponse.json({ success: false, message: 'Invalid NIN' }, { status: 400 })
    return HttpResponse.json({
      status: 'VERIFIED',
      message: 'NIN verified successfully.',
      data: { firstName: 'Demo', lastName: 'User' },
    })
  }),

  // ── BVN Verification (MOCKED) ──────────────────────────────────────
  http.post('/api/kyc/bvn', async ({ request }) => {
    await delay(1500)
    const body = await request.json() as Record<string, string>
    if (!body.bvn || body.bvn.length !== 11) return HttpResponse.json({ message: 'Invalid BVN' }, { status: 400 })
    return HttpResponse.json({
      status: 'VERIFIED',
      message: 'BVN verified successfully.',
    })
  }),

  // ── BVN Consent Initiate (NIBSS — parked) ──────────────────────────
  http.post('/api/kyc/bvn/consent/initiate', async ({ request }) => {
    await delay(1200)
    const body = await request.json() as Record<string, string>
    if (!body.bvn || body.bvn.length !== 11) return HttpResponse.json({ status: 'FAILED', message: 'Invalid BVN' }, { status: 400 })
    return HttpResponse.json({
      status: 'PENDING', message: 'Consent initiated.',
      consentUrl: 'https://apitest.nibss-plc.com.ng/api/consent/mock?sessionId=mock',
      sessionId: 'mock_session_from_msw',
    })
  }),

  // ── KYC Status (MOCKED — returns ready_for_ninepsb) ─────────────────
  http.get('/api/kyc/status', async () => {
    await delay(300)
    return HttpResponse.json({
      kyc_status: 'PENDING',
      tier: 1,
      ready_for_ninepsb: true,
      next_action: 'accept_terms',
      next_action_label: 'Review & Accept 9PSB Terms',
      next_action_route: 'GET /wallet/ninepsb/terms',
      verifications: [
        { type: 'NIN', status: 'VERIFIED', provider: 'mock', verified_at: new Date().toISOString() },
        { type: 'BVN', status: 'VERIFIED', provider: 'mock', verified_at: new Date().toISOString() },
      ],
    })
  }),

  // ── NOTE: Wallet creation now routes through backend. MSW does NOT intercept it. ──

  // ── 9PSB Wallet Terms (MOCKED — static text) ────────────────────────
  http.get('/api/wallet/ninepsb/terms', async () => {
    await delay(300)
    return HttpResponse.json({
      status: 'SUCCESS',
      data: {
        title: '9PSB Wallet Terms & Conditions',
        content: `9 Payment Service Bank (9PSB) Wallet Terms & Conditions

1. ACCEPTANCE OF TERMS
By opening a 9PSB wallet account, you agree to these Terms and all applicable laws.

2. ELIGIBILITY
You must be at least 18 years old and a Nigerian resident with a valid BVN or NIN.

3. WALLET OPERATIONS
Funds are held with 9 Payment Service Bank, licensed by the Central Bank of Nigeria.
All transactions require a 6-digit PIN for authorization.

4. FEES & CHARGES
Bank transfers: ₦52.50 per transaction. Other fees as displayed before confirmation.

5. SECURITY
Never share your PIN, OTP, or password. Report unauthorized activity immediately.

6. PRIVACY
Your data is processed per the Nigeria Data Protection Act (NDPA) 2023.

7. GOVERNING LAW
Governed by the laws of the Federal Republic of Nigeria.`,
        version: '1.0',
        last_updated: '2024-12-01',
      },
    })
  }),

  // ── NOTE: All other 9PSB endpoints (enquiry, status, transactions, ──
  //          upgrade, requery) are NOT handled by MSW. They fall through
  //          to the Vite proxy → Laravel backend → 9PSB adapter → live API.
]