# FixPay Comprehensive Change Plan

**Date:** 21 July 2026
**Based on:** FixPay Product Review (22 findings) + BVN/NIN Restructuring Request
**App:** fixpay-pwa (PWA deployed at fixpay-pwa.vercel.app)

---

## PART 1: RESTRUCTURE BVN/NIN AWAY FROM ONBOARDING

### 1.1 Modify App.tsx Routing Guard
- Remove forced KYC redirect in `RequireAuth`. Users proceed to /home regardless of KYC status.
- KYC becomes a voluntary flow accessible from settings/wallet.

### 1.2 Modify CreatePinScreen.tsx Navigation
- After PIN creation, always navigate to /home (no longer to /kyc).

### 1.3 Modify KycStepper.tsx - Add Method Toggle & Make Voluntary
- Add tab/toggle allowing users to switch between NIN, BVN, and Selfie verification methods.
- Remove forced sequential flow. User picks which method to verify with.
- Add clear "Skip All" option at the top level.

### 1.4 Gate Wallet Creation & High-Limit Features Behind KYC
- Keep BVN gate on SendScreen but update messaging for tiered limits.
- Add tier-limit prompt in WalletScreen.

### 1.5 Add Tiered KYC System
- Tier 1 (no verification): ₦50,000/day, low wallet cap
- Tier 2 (NIN or BVN): ₦200,000/day
- Tier 3 (NIN + BVN + Selfie): ₦5,000,000/day

---

## PART 2: QUICK WINS

### 2.1 Fix 4-digit vs 6-digit PIN Messaging
- WelcomeScreen.tsx: Change "6-digit PIN" to "4-digit PIN"

### 2.2 Rewrite Hero Copy & Replace Dollar Icon
- Rewrite all 3 slides with concrete Nigerian-bank-transfer benefits
- Replace 💸 with 🏦

### 2.3 Add BVN/NIN Name-Matching Alert at Signup
- RegisterScreen.tsx: Add amber info banner about name matching

### 2.4 Enforce Password Complexity Policy
- Add uppercase, lowercase, number, special character requirements
- Add real-time password strength meter

### 2.5 Add Footer Links
- Social handles, FAQ, legal docs, Contact Us

---

## PART 3: UI CONSISTENCY - ICONS & FONTS

### Icon Size Standardization
| Usage | Current | Standard |
|-------|---------|----------|
| BottomNav icons | w-6 h-6 | w-5 h-5 |
| Menu/list icons | w-5 h-5 | w-4 h-4 |
| Chevrons | w-4 h-4 | w-3.5 h-3.5 |
| Inline indicators | w-5 h-5 | w-4 h-4 |
| Hero/decorative | w-8 to w-20 | w-6 h-6 max |
| Emoji slides | text-[80px] | text-[48px] |
| Avatars | w-24 h-24 | w-16 h-16 |

### Font Size Standardization
| Purpose | Standard |
|---------|----------|
| Screen title | text-[15px] font-semibold |
| Section headers | text-[11px] font-semibold uppercase |
| Body text | text-[13px] |
| Captions | text-[11px] text-gray-400 |
| Buttons | text-[14px] font-semibold |
| Balance | text-[26px] font-black |
| Hero title | text-[22px] font-black |
| Hero body | text-[14px] |

### Color Theme
- Interactive: var(--brand-primary)
- Success: #34C759, Failure: #FF3B30, Warning: #FF9500
- Text: gray-900 (primary), gray-500 (secondary), gray-400 (tertiary)
- BG: #F2F2F7 (screens), white (cards)
- Cards: border border-black/5 rounded-[16px]

---

## PART 4: NEXT-UP FEATURES
1. Dashboard search bar + FAQ
2. Support contact bar (WhatsApp, phone, email)
3. Beneficiaries grouped by service type
4. Transactions & expense insights
5. Scheduled/recurring bill payments
6. Commission/rewards button
7. Token retrieval surfaced on dashboard
8. Chat-based payments integration (long-term)

---

## PART 5: IMPLEMENTATION PHASING
- Sprint 1: Critical Fixes (Part 2.1-2.5 + Part 1.1-1.2)
- Sprint 2: UI Consistency + KYC Restructure (Part 3 + Part 1.3-1.5)
- Sprint 3: New Features (Part 4.1-4.4)
- Sprint 4: Advanced (Part 4.5-4.8)