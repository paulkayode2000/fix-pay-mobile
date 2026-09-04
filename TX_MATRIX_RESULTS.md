# Transaction Matrix — fixpay-mobile → gateway → processors → TMS

> One transaction per type/processor, driven **only** through fixpay-mobile
> (`http://api.fixpay.test/api/...`, port-free via the Traefik ingress).
> Harness: `fixpay-laravel/tx-matrix.php` · raw results: `tx-matrix-out.txt`.
> Run: 2026-08-25 · test user `01a03985-45ba-71ab-b9e7-d6da65039c79` (KYC-VERIFIED fixture).

## Legend
| Column | Source of truth |
|---|---|
| **fixpay** | HTTP status + ref/body from the API (Laravel) |
| **gateway** | `docker logs java-gateway-e2e` — RiskRules cache + `RiskGate ingest/score` |
| **txn/processor** | `docker logs java-transaction-e2e` / provider response code |
| **TMS** | `transactions` row (source, business_id, user_id, device_id, amount, status) |

## Results

| # | Type | Processor | fixpay | gateway (risk gate) | txn / processor | TMS row | Verdict |
|---|------|-----------|--------|---------------------|------------------|---------|---------|
| M1 | Wallet create | 9PSB | **500** `Gateway request failed [/wallet/open] HTTP 50x` | `ingest WOP-… velocityBreach=false` · `score aml=true fraud=false` status=clear | `/nuban/9psb/generate` → provider failed (timeout) | `wallet_open` amt=1 status=clear, identity ✓ | ✅ pipeline + AML-flag proven; ❌ provider generate (env) |
| M2 | Wallet enquiry | 9PSB | **200** `Successful` | proxied `/wallet/enquiry` (not risk-gated) | 9PSB balance enquiry OK | none (not ingested — correct) | ✅ OK |
| M3 | Airtime | VTPass/9PSB VAS | **503** | `ingest FP… velocityBreach=false` (velocity only) | `9PSB VAS airtime network=MTN amount=100` → provider timeout | `bill_payment` amt=100 status=ingested | ✅ pipeline+ingest; ❌ VAS provider (env) |
| M4 | Data | VTPass/9PSB VAS | **503** | `ingest FP… velocityBreach=false` | `9PSB VAS data productId=mtn-10mb-100` → timeout | `bill_payment` amt=100 status=ingested | ✅ pipeline+ingest; ❌ VAS provider (env) |
| M5 | Electricity | VTPass/9PSB VAS | **503** | `ingest FP… velocityBreach=false` | `9PSB VAS electricity (routed to pay)` → timeout | `bill_payment` amt=1000 status=ingested | ✅ pipeline+ingest; ❌ VAS provider (env) |
| M6 | Cable TV | VTPass/9PSB VAS | **503** | `ingest FP… velocityBreach=false` | `9PSB VAS cable-tv (routed to pay)` → timeout | `bill_payment` amt=1000 status=ingested | ✅ pipeline+ingest; ❌ VAS provider (env) |
| M7 | Education | VTPass/9PSB VAS | **503** | `ingest FP… velocityBreach=false` | `9PSB VAS exams (routed to pay)` → timeout | `bill_payment` amt=1000 status=ingested | ✅ pipeline+ingest; ❌ VAS provider (env) |
| M8 | Insurance | VTPass/9PSB VAS | **503** | `ingest FP… velocityBreach=false` | `9PSB VAS pay billerId= customerId=ABC123XY` → timeout | `bill_payment` amt=1000 status=ingested | ✅ pipeline+ingest; ❌ VAS provider (env) |
| M9 | Meter verify | VTPass | **200** provider `code 000` | **bypassed** (direct Laravel→VTPass) | VTPass sandbox returned meter data (TESTMETER1) | none | ✅ OK — note: bypasses gateway |
| M10 | Bank transfer ₦150k | 9PSB | **202** `FPT…HQBLKH` status=FAILED | `ingest FPT… velocityBreach=false` · **`score aml=false fraud=true`** status=clear | 9PSB transfer → provider FAILED (balance/limits) | `transfer` amt=150000 status=clear | ✅ **fraud-only flag proven**; ❌ provider transfer |
| M11 | Account lookup | 9PSB | **200** `accountName=Unknown` | proxied `/transfer/lookup` | 9PSB name enquiry returned Unknown | none | ✅ OK |
| M12 | Wallet transfer | internal | **500** `Recipient not found.` | not reached | n/a | none | ✅ app-level finding (no recipient wallet) |
| M13 | Alternative init | Paystack | **400** `payment option coming soon` | not reached | stub endpoint | none | ✅ stub documented |
| M14 | Transfer ₦1.5M (AML+fraud probe) | 9PSB | **202** `FPT…DWUHN` status=FAILED | **`ingest velocityBreach=true rule=sum_1h>500000` → BLOCKED** (score not reached) | not reached (blocked at gate) | `transfer` amt=1500000 status=ingested | ✅ velocity sum-rule correctly blocks large transfer; ⚠️ deep score preempted |
| M15 | Transfer ₦2M (AML-only probe; fraud threshold bumped 5M) | 9PSB | **202** `FPT…AYA5` status=FAILED | **`ingest velocityBreach=true rule=sum_1h>500000` → BLOCKED** | not reached (blocked at gate) | `transfer` amt=2000000 status=ingested | ✅ rules bump applied + velocity block; ⚠️ deep score preempted |

## What the matrix proved

1. **Port-free ingress end-to-end**: every fixpay→gateway→TMS hop used `.test` hostnames through Traefik (no host ports). `api.fixpay.test` → `gw.payfixy.test`/`antifraud.tms.test` all resolved by Docker DNS.
2. **Scoped identity is persisted at TMS**: every ingested row carries `source=mobile, business_id=99, user_id=<uuid>, device_id=matrix-dev-01`.
3. **Rules-driven risk gate (new code)**:
   - wallet-open (M1) → **`aml=true fraud=false`** (identity event)
   - ₦150k transfer (M10) → **`aml=false fraud=true`** (fraud-only: 100k ≤ 150k < 1M)
   - bills (M3–M8) → velocity only (below both thresholds)
   - every transaction ingested (`velocityBreach=false`), `RiskRules refreshed version=1.0` on cold cache.
4. **Velocity sum rule (`sum_1h>500000`) is a hard ceiling for large transfers** — any single ≥ ₦500k transfer breaches it and is blocked **before** the AML/fraud deep score runs (M14/M15). The AML+fraud "both" flag combo is therefore unreachable for large single transfers under the current velocity limits — a tuning decision to revisit (raise `sum_1h`, or exempt deep-scored transfers).
5. **TMS rules admin works through the ingress** (M15 fraud-threshold bump applied; gateway auto-refreshed via the version guard).

## Bugs found & fixed during the run
- **`GatewayClient` provider-name mismatch**: Laravel stores `wallet_provider='ninepsb'`, gateway only accepts `'9psb'` → added `canonicalProvider()` normalization in `GatewayClient::post()`.
- **`TransactionGuardService` double-idempotency**: the `IdempotencyMiddleware` pre-creates a `PROCESSING` `IdempotentRequest`, which the wallet-create controller's own `checkIdempotency()` then misread as a duplicate (409). Fixed to only treat `COMPLETED` rows as duplicates.

## Environment findings (not pipeline defects)
- 9PSB sandbox (`102.216.128.75:9090/waas`) root responds in ~0.36 s but the **VAS/generate/transfer calls time out** (~15 s) → M1/M3–M8 provider-level failures. VTPass sandbox works (`000` on M9).
- fixpay has **no queue worker** in its compose stack → the 50 async `AntifraudScoreJob` dispatches sit in the `jobs` table; `risk_assessments` = 0. The synchronous gateway risk gate covers live enforcement; add a worker for async TMS tagging.

## Artifacts
- `fixpay-laravel/tx-matrix.php` — the repeatable harness (auth → KYC fixture → M1–M15)
- `fixpay-laravel/tx-smoke.php` — auth + single-bill smoke test
- `tx-matrix-out.txt` — full run output incl. raw JSON results

