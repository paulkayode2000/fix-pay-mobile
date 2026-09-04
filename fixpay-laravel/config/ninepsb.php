<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 9PSB Terms & Conditions
    |--------------------------------------------------------------------------
    |
    | Shown to users before they open a 9PSB wallet. Updated here to
    | keep the terms in version control. The client fetches this via
    | GET /wallet/ninepsb/terms.
    |
    */
    'terms' => [
        'version' => '1.0',
        'last_updated' => '2024-12-01',
        'title' => '9PSB Wallet Terms & Conditions',
        'content' => <<<'TERMS'
9 Payment Service Bank (9PSB) Wallet Terms & Conditions

1. ACCEPTANCE OF TERMS
By opening a 9PSB wallet account ("Wallet"), you agree to be bound by these Terms and Conditions and all applicable laws and regulations. If you do not agree, you may not open or use the Wallet.

2. ELIGIBILITY
You must be at least 18 years old and a Nigerian resident with a valid Bank Verification Number (BVN) or National Identification Number (NIN) to open a Wallet.

3. ACCOUNT OPENING & KYC
3.1. You must provide accurate and complete information during registration, including your BVN or NIN for identity verification.
3.2. 9PSB reserves the right to request additional documentation for Know Your Customer (KYC) compliance.
3.3. Your Wallet tier determines transaction limits: Tier 1 (₦50,000/day), Tier 2 (₦200,000/day), Tier 3 (₦1,000,000/day).

4. WALLET OPERATIONS
4.1. Funds in your Wallet are held with 9 Payment Service Bank, licensed by the Central Bank of Nigeria.
4.2. You may deposit, withdraw, transfer, and make payments from your Wallet subject to available balance and transaction limits.
4.3. All transactions require a 6-digit Personal Identification Number (PIN) for authorization.
4.4. Your Wallet is bound to a single device. Device changes require additional verification.

5. FEES & CHARGES
5.1. Bank transfers are subject to a standard fee of ₦52.50 per transaction.
5.2. Bill payments and other services may carry additional charges as displayed before confirmation.
5.3. 9PSB reserves the right to modify fees with reasonable notice.

6. SECURITY
6.1. You are responsible for maintaining the confidentiality of your PIN, password, and device.
6.2. Never share your PIN, OTP, or password with anyone. FixPay and 9PSB will never ask for these.
6.3. Report any unauthorized activity immediately to FixPay support.

7. PRIVACY
7.1. Your personal data is processed in accordance with the Nigeria Data Protection Act (NDPA) 2023.
7.2. KYC data (BVN/NIN) is transmitted securely to 9PSB for verification purposes only.

8. LIMITATION OF LIABILITY
8.1. FixPay and 9PSB are not liable for losses arising from unauthorized access due to your failure to secure your credentials.
8.2. Transaction disputes must be reported within 30 days of the transaction date.

9. TERMINATION
9.1. You may close your Wallet at any time by contacting FixPay support.
9.2. 9PSB reserves the right to suspend or close your Wallet for violation of these terms or regulatory requirements.

10. GOVERNING LAW
These terms are governed by the laws of the Federal Republic of Nigeria.
TERMS
    ],
];