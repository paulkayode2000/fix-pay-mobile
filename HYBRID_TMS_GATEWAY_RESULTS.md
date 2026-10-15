# Hybrid E2E Results — fixpay-mobile (local) → Payfixy Gateway (local) → TMS (Docker)

> Round run **2026-09-15 12:26–12:28 UTC**.
> Mode: **fixpay-mobile and the Payfixy Gateway run natively on the host; TMS stays in Docker**
> (as requested). Driver: `scratch/hybrid-run/tx-native.php` (adapted from `tx-observe.php`).
> Evidence: `scratch/hybrid-run/24-rerun.txt` (harness + gateway log), `26-tms.txt` (TMS rows),
> `32-fxdb.txt` / `33-fx2.txt` (fixpay risk records).

## 1. Topology actually exercised

| Hop | Component | Runtime | Endpoint |
|---|---|---|---|
| 1 | fixpay-mobile (Laravel) | **native** (host) | `http://127.0.0.1:8081` |
| 2 | Payfixy Gateway (edge + risk gate) | **native** (host) | `http://127.0.0.1:9013` |
| 3 | transaction-service → processor-service | **native** (host) | `:9006` → `:9019` |
| 4 | Processors (9PSB WAAS/VAS, VTPass) | external sandboxes | `102.216.128.75:9090`, `sandbox.vtpass.com` |
| 5 | TMS antifraud + aml + PG + redis | **Docker** | `:8083` (antifraud-api), `:81` (laravel-nginx), `:5434` (postgres) |

Launch used: `Payfixy_Gateway\.local-dev\start-scripts\start-all.bat` (gateway chain),
host PHP + native queue worker for fixpay, and the existing Docker TMS stack.

## 2. Wiring changes applied (to reach the local gateway + Docker TMS)

`fixpay-laravel/.env` (backup: **`.env.hybrid.bak`**):

| Key | Before (all-Docker) | After (hybrid) |
|---|---|---|
| `DB_PORT` | `5432` | `5433` |
| `PAYFIXY_GATEWAY_BASE_URL` | `http://gw.payfixy.test` | `http://127.0.0.1:9013` |
| `TMS_BASE_URL` | `http://aml.tms.test` | `http://127.0.0.1:81` |
| `TMS_ANTIFRAUD_URL` | `http://antifraud.tms.test` | `http://127.0.0.1:8083` |
| `PAYFIXY_GATEWAY_BUSINESS_ID` | `99` | `00d4fecd-ed98-4869-4a21-62247cbba0c5` |

**No gateway `.bat` change was needed** — `start-gateway-service.bat` already points
`APP_RISK_GATE_ANTIFRAUD_URL=http://127.0.0.1:8083`, which is exactly the Docker
antifraud port (`TMS/.tms/ports.json` → `TMS_ANTIFRAUD_PORT=8083`).

DB note: the native `postgresql-x64-17/18` services are **Stopped (Manual)** and cannot be
started without elevation, so the native fixpay backend runs against the already-populated
Docker **`fixpay-db` on `:5433`** (db `fixpay`, user `fixpayuser`).

Port note: `:8081` was held by the Docker `payfixy_gateway-payfixy-frontend-1`; it was stopped
to give the native backend the canonical `:8081`. The Docker `fixpay-queue-worker` was stopped
so the native queue worker owns job processing (no double-consumption).

## 3. Sample requests / responses / statuses at each point

Test identity: user `01a0a508-431c-71df-aed1-07ad838a9d18`, `source=mobile`,
`business_id=00d4fecd-ed98-4869-4a21-62247cbba0c5`, `device_id=hybrid-dev-01`.

### T1 — 9PSB wallet open (identity → AML screen)

| Hop | Endpoint | Sample request | Sample response | Status |
|---|---|---|---|---|
| fixpay | `POST /api/wallet/ninepsb/create` | `{"terms_accepted":true,"bvn":"12345678901"}` + `X-Device-Id` | `Failed to create 9PSB wallet: Gateway request failed [/api/v1/mobile/wallet/open]: HTTP 403 — Transaction blocked by risk assessment` | **500** |
| Gateway | `POST /api/v1/mobile/wallet/open` | `{"wallet_provider":"9psb","reference":"WOP-…","bvn":…}` | `RiskGate ingest ref=WOP-20260915122619-… velocityBreach=false rule=null` → `RiskGate score ref=WOP-… status=flagged aml=true fraud=false` | **403 blocked** |
| Processor | `/nuban/9psb/generate` | — | not reached (blocked at the gate) | — |
| TMS | ingest → `transactions` #215 | `{source:mobile, business_id:00d4fecd…, user_id:…, device_id:hybrid-dev-01}` | `wallet_open` amt=1 | **flagged** |

### T2 — 9PSB wallet enquiry (read-only)

| Hop | Endpoint | Sample request | Sample response | Status |
|---|---|---|---|---|
| fixpay | `GET /api/wallet/ninepsb/enquiry` | + `X-Device-Id` | `{"message":"Successful", …}` | **200** |
| Gateway | `GET /api/v1/mobile/wallet/enquiry` | proxied, **not risk-gated** | 9PSB balance enquiry OK | 200 |
| TMS | — | not ingested (by design) | — | — |

### T3 — VTPass airtime ₦100

| Hop | Endpoint | Sample request | Sample response | Status |
|---|---|---|---|---|
| fixpay | `POST /api/payments/vtpass` | `{"service_id":"mtn","amount_kobo":10000,"phone":"08011111111","billers_code":"08011111111"}` + `X-Pin-Token` | `{"payment_reference":"FP20260915122643R44VHC", "message":"Transaction failed."}` | **422** |
| Gateway | `POST /api/v1/mobile/bills/pay` | `{"payment_method":"wallet","wallet_provider":"9psb","service_id":"mtn","amount":100,…}` | `RiskGate ingest ref=FP20260915122643R44VHC velocityBreach=false rule=null` (velocity only) | 200 (risk passed) |
| Processor | transaction `:9006` → processor `:9019` → 9PSB VAS | bill payload | provider `responseCode 300` (9PSB VAS failure) | provider fail |
| TMS | ingest → `transactions` #216 | scoped identity | `bill_payment` amt=100 | **ingested** |

### T4 — Bank transfer ₦150,000 (fraud-only band)

| Hop | Endpoint | Sample request | Sample response | Status |
|---|---|---|---|---|
| fixpay | `POST /api/transfers/bank` | `{"amount_kobo":15000000,"account_number":"0123456789","bank_code":"058"}` | `{"transfer_reference":"FPT202609151227121KURCH","status":"FAILED","amount_kobo":15000000,"fee_kobo":0}` | **202** |
| Gateway | `POST /api/v1/mobile/transfer/bank` | same + wallet routing | `ingest ref=FPT…1KURCH velocityBreach=false` → **`score ref=FPT…1KURCH status=clear aml=false fraud=true`** | 200; then downstream 400 |
| Processor | 9PSB `/nuban/9psb/transfer` | transfer payload | `Downstream 4xx from transaction-service: path=/api/v1/transaction/nuban/9psb/transfer status=400 BAD_REQUEST` | provider fail |
| TMS | ingest → `transactions` #217 | scoped identity | `transfer` amt=150000 | **clear** |

### T5 — Bank transfer ₦1,500,000 (velocity breach → deep AML+fraud score)

| Hop | Endpoint | Sample request | Sample response | Status |
|---|---|---|---|---|
| fixpay | `POST /api/transfers/bank` | `{"amount_kobo":150000000,…}` | `{"transfer_reference":"FPT20260915122724IZJSVE","status":"FAILED",…}` | **202** |
| Gateway | `POST /api/v1/mobile/transfer/bank` | same | `ingest ref=FPT…IZJSVE velocityBreach=true rule=sum_1h>500000` **then** **`score ref=FPT…IZJSVE status=clear aml=true fraud=true`** | 200; then downstream 400 |
| Processor | 9PSB transfer | — | `Downstream 4xx … status=400 BAD_REQUEST` | provider fail |
| TMS | ingest → `transactions` #218 | scoped identity | `transfer` amt=1500000 | **clear** |

**This is the key regression proof:** the velocity gate no longer pre-empts the deep score —
the 1.5M transfer is ingested (`velocityBreach=true`) **and** deep-scored `aml=true fraud=true`
(the `GATEWAY_TMS_E2E_FIXES.md` item-5 fix holds in the local gateway).

### T6 — Account lookup (name enquiry)

| Hop | Endpoint | Sample request | Sample response | Status |
|---|---|---|---|---|
| fixpay | `POST /api/transfers/verify-account` | `{"accountNumber":"0123456789","bankCode":"058"}` | `{"data":{"accountNumber":"0123456789","bankCode":"058","accountName":"Unknown","status":"SUCCESS"}}` | **200** |
| Gateway | `/api/v1/mobile/transfer/lookup` | proxied | 9PSB name enquiry → `Unknown` | 200 |
| TMS | — | not ingested (by design) | — | — |

### T7 — Meter verify (VTPass, direct)

| Hop | Endpoint | Sample request | Sample response | Status |
|---|---|---|---|---|
| fixpay | `POST /api/payments/verify` | `{"service_id":"ikeja-electric","billers_code":"1111111111111","type":"prepaid"}` | `{"code":"000","content":{"Customer_Name":"TESTMETER1","Meter_Number":"68100017372","Can_Vend":"yes",…}}` | **200** |
| Gateway | — | **bypassed** (Laravel → VTPass direct) | — | — |

## 4. Summary matrix

| # | Flow | fixpay HTTP | Gateway risk gate | Processor | TMS status |
|---|---|---|---|---|---|
| T1 | 9PSB wallet open | 500 (gateway 403) | `flagged aml=true fraud=false` | blocked | `flagged` |
| T2 | 9PSB wallet enquiry | 200 | not risk-gated | OK | — (by design) |
| T3 | VTPass airtime ₦100 | 422 | `velocityBreach=false` (velocity only) | 9PSB VAS `300` fail | `ingested` |
| T4 | 9PSB transfer ₦150k | 202 (FAILED) | `clear aml=false fraud=true` | 9PSB `400` fail | `clear` |
| T5 | 9PSB transfer ₦1.5M | 202 (FAILED) | `velocityBreach=true` → `clear aml=true fraud=true` | 9PSB `400` fail | `clear` |
| T6 | Account lookup | 200 | proxied (not gated) | `Unknown` | — (by design) |
| T7 | Meter verify | 200 (`000`) | bypassed | VTPass OK | — (by design) |

## 5. TMS evidence (Docker `postgres`, db `antifraud`)

```
 id  | source |             business_id              |      user_id       |  device_id   | amount  |  status  |     type     |              ref_no
-----+--------+--------------------------------------+--------------------+--------------+---------+----------+--------------+-----------------------------------
 218 | mobile | 00d4fecd-ed98-4869-4a21-62247cbba0c5 | 01a0a508-431c-71df | hybrid-dev-01| 1500000 | clear    | transfer     | FPT20260915122724IZJSVE
 217 | mobile | 00d4fecd-ed98-4869-4a21-62247cbba0c5 | 01a0a508-431c-71df | hybrid-dev-01|  150000 | clear    | transfer     | FPT202609151227121KURCH
 216 | mobile | 00d4fecd-ed98-4869-4a21-62247cbba0c5 | 01a0a508-431c-71df | hybrid-dev-01|     100 | ingested | bill_payment | FP20260915122643R44VHC
 215 | mobile | 00d4fecd-ed98-4869-4a21-62247cbba0c5 | 01a0a508-431c-71df | hybrid-dev-01|       1 | flagged  | wallet_open  | WOP-20260915122619-01a0a508-…
```

Every row is correctly scoped (`source=mobile`, business UUID, user UUID, **single** device id).

Antifraud-service access log confirms the gateway leg:
```
GET  /v1/rules?source=mobile&business_id=00d4fecd-… -> 200
POST /v1/transactions/ingest -> 200
POST /v1/transactions/score  -> 200
```

## 6. fixpay async tagging (native queue worker)

`jobs = 0` (worker drained the queue). `risk_assessments`:

```
   type    |   status    | count
-----------+-------------+-------
 AML       | PENDING     |   142
 AML       | UNAVAILABLE |     2
 ANTIFRAUD | CLEAR       |    30
 ANTIFRAUD | SKIPPED     |    82
 ANTIFRAUD | UNAVAILABLE |     1
```

`ANTIFRAUD` decision payload recorded for T4 (transfer ₦150k):
```json
{"checks":{"aml":false,"fraud":true},"ref_no":"FPT202609151227121KURCH","status":"clear",
 "decision":"allow","block_on_flag":true,
 "velocity":{"rule":null,"breach":false,"sum_1h":0,"count_1h":0,"structuring_24h":0},
 "aml_match":{"type":"none","score":0,"matched_name":null,"watchlist_id":null},
 "anomaly":{"reason":"model_not_trained","model_version":"v20260915.122620","score":0,"is_anomaly":false},
 "transaction_id":217}
```
`ANTIFRAUD` for T3 (bill): `{"reason":"below_ruleset_threshold","rules_version":"1.0"}` → SKIPPED.
`AML` jobs are queued against the TMS aml system (`call_ref CALL-mobile-…`, `status queued`).

## 7. Findings

### 🔴 F1 — `PAYFIXY_GATEWAY_BUSINESS_ID` is environment-specific (native ≠ Docker)
The **first** run (business id still `99`, the Docker value) failed at the gateway:
`T1 wallet open → HTTP 401 Invalid API key`, and the TMS rows for that run were written with
`business_id=99` by fixpay's *async* path (gateway leg dead) — with a **duplicated**
`device_id` (`hybrid-dev-01, hybrid-dev-01`).

Root cause (native MySQL `business` schema):
```
business.business_api_keys.raw_secret_once = ce_sk_test_fixpaymobile_…
business.business_api_keys.business_id     = 00d4fecd-ed98-4869-4a21-62247cbba0c5
business.businesses.alias                  = fixpaymobile
```
The Docker stack maps the same key to **`99`**; the native stack maps it to the **UUID**.
fixpay sends the configured value as `X-Business-Id` (`GatewayClient::authHeaders()`), so it
must match the target gateway's DB. **Fixed** by setting the UUID. **No code change needed** —
only the env value.

> Recommendation: make this a checked-in per-environment value (e.g. `PAYFIXY_GATEWAY_BUSINESS_ID`
> in a native `.env.local` / docker `.env.docker`) so the two stacks can't be crossed again.

### 🟡 F2 — Wallet-open now BLOCKS (403) on an AML false positive
`RiskGate score ref=WOP-… status=flagged aml=true fraud=false` → gateway returns
`403 Transaction blocked by risk assessment` because `block_on_flag=true`.
Previously (2026-09-04 runs) wallet-open scored `clear aml=true`.
The TMS watchlist is now populated from real sanctions data — **163,045 entries / 646,106 tokens**
(NIGSAC / HMT / EU / OFAC, loaded 2026-09-10) — so the identity screen for the synthetic name
`Hybrid Test` trips. The screening match detail is not surfaced in the antifraud logs.
> Recommendation: expose the `aml_match` block for wallet-open (it is present for transfers),
> review the match threshold for identity events, and decide whether wallet-open should hard-block
> (vs. flag-and-allow) for sandbox/test identities.

### 🟡 F3 — ML anomaly model is a stub again (`model_not_trained`)
`anomaly_scores.details.reason = "model_not_trained"`, `score=0` for every scored transaction
(`model_version v20260915.122620`). The 2026-09-04 fix trained `ensemble_if_lof`; the current
runtime reports the model as untrained, so the ML leg contributes nothing and the risk decision
is rules/AML-only.
> Recommendation: re-run `POST /v1/ml/train` and assert `/v1/ml/models` is non-empty in the
> TMS bring-up health check.

### 🟡 F4 — Risk rules exist only for business `99`, not the native business UUID
`risk_rules` has a single row scoped to `business_id=99` (`fraud_amount_threshold=100000`).
For the native UUID there is **no row**, so:
- the **gateway** applied defaults (which is why the risk decisions were still correct), but
- fixpay's **async** screening marked the bill `ANTIFRAUD/SKIPPED` with
  `{"reason":"below_ruleset_threshold","rules_version":"1.0"}`.

So the two systems were reading different rule sets for the same transaction.
> Recommendation: seed `risk_rules` for the native business UUID (or make the default row
> business-agnostic) so the synchronous gate and the async tagging agree.

### 🔵 F5 — Device-id duplication on the fixpay async path
Rows written via fixpay's async job (business `99`, run 1) stored
`device_id="hybrid-dev-01, hybrid-dev-01"` (fixpay sends both `X-Device-ID` and `X-Device-Id`);
rows written **via the gateway** (run 2) store the single `hybrid-dev-01` — the gateway's
`IdentityResolver` dedupe fix holds. The duplicate only appears on the direct async path.
> Recommendation: normalise the device header in fixpay's async dispatch before posting to TMS.

### 🔵 F6 — Gateway OTEL exporter floods the local log
`java-gateway-service.out.log` is dominated by
`Failed to export spans … UnknownHostException: alloy`, which buries the `RiskGate` lines the
whole monitoring story depends on (the same defect noted for the Docker gateway; the local
gateway runs without `MANAGEMENT_TRACING_ENABLED=false`).

### ⚙️ Environment notes (not pipeline defects)
- **Native PG cannot start without elevation.** `postgresql-x64-17/18` are `Stopped (Manual)`
  and `Start-Service` fails without admin, so the native fixpay backend uses the Docker
  `fixpay-db` on `:5433`. `restart-services.ps1` still hard-codes `DB_PORT=5432` / `fixpay_db`
  — it will not connect natively until either PG is started or the launcher is updated.
- **9PSB sandbox** wallet-generate / VAS / transfer still fail
  (VAS `responseCode 300`, transfer `400 BAD_REQUEST`) — provider-side, as in every prior run.
  VTPass sandbox works (`000` on meter verify).
- `:8081` was occupied by the Docker Payfixy frontend and had to be freed for the native backend.

## 8. Artifacts & revert

Artifacts (all under `scratch/hybrid-run/`):
`tx-native.php` (driver) · `24-rerun.txt` (valid run: harness + gateway RiskGate lines) ·
`26-tms.txt` / `28-payload.txt` (TMS rows + payloads) · `32-fxdb.txt` / `33-fx2.txt`
(fixpay risk records) · `22-mysqlprobe2.php` / `mysql-probe3.php` + `23-mysqlprobe3.txt`
(business-id discovery) · `01`–`34` step logs.

To return to the all-Docker baseline: restore `fixpay-laravel/.env.hybrid.bak` over `.env`,
then `docker start fixpay-queue-worker payfixy_gateway-payfixy-frontend-1`.

---

# 9. Fixes applied & verification rerun (2026-09-15 ~12:40–12:45)

Every key finding was fixed at its root and re-verified on a fresh run.

| # | Finding | Fix | Where | Verified |
|---|---|---|---|---|
| F1 | `PAYFIXY_GATEWAY_BUSINESS_ID` env-specific | Set the native business UUID `00d4fecd-…` | `fixpay-laravel/.env` | gateway no longer 401/403; all calls reach the risk gate |
| F2 | Wallet-open AML false positive (`Hybrid Test` ↔ OFAC "33RD SCIENTIFIC RESEARCH AND **TESTING** INSTITUTE") | `strong_name_signal()` now requires a **distinctive** (non-boilerplate) token — added `GENERIC_NAME_TOKENS` so `test`/`testing`/`institute`/`and`/`ltd`… cannot flag on their own | `TMS/antifraud-service/src/services/screening_service.py` | `Hybrid Test` → `clear` (was `flagged` 31.25); `CHIKWUKA GODWIN EZE`, `Ernest Abdulovich VALEEV` → still `flagged` |
| F3 | ML detector reported `model_not_trained` (DB had 36 models; runtime loads from **disk**) | `POST /v1/ml/train` → `v20260915.123503` (219 samples) + `POST /v1/ml/reload` | TMS antifraud-service | `/v1/ml/reload` → `ensemble_trained: true`; scores now `reason=normal` |
| F4 | No `risk_rules` row for the native business | Seeded an explicit row (scope `mobile`, thresholds aml 1M / fraud 100k) | TMS `risk_rules` | gateway logs `RiskRules refreshed scope=mobile\|00d4fecd… version=1.1` |
| F5 | `device_id = "x, x"` on the fixpay async path | New `App\Support\DeviceId` normaliser (split/trim/dedupe) applied at the job-dispatch sites and `GatewayClient::authHeaders()` | `fixpay-laravel/app/Support/DeviceId.php` (+ 3 call sites) | unit probe: `"dev-01, dev-01"`→`"dev-01"`; TMS rows store a single `hybrid-dev-01` |
| F6 | Gateway OTEL `alloy` exporter flooded the log | `SET MANAGEMENT_TRACING_ENABLED=false` in the native launcher + gateway restart | `Payfixy_Gateway\.local-dev\start-scripts\start-gateway-service.bat` | `alloy` occurrences since restart: **0** |

### F7 (NEW, surfaced by the F2 fix) — 9PSB onboarding could never upgrade a registered user
Once the AML gate stopped blocking, wallet-open reached the DB and failed with
`SQLSTATE[23505] … wallets_user_id_unique`. Root cause: `AuthController.php:48` creates the
default (Providus) wallet **at registration**, and the 9PSB controller only *closes* it
(`status=CLOSED`) before inserting a **second** wallet — violating the one-wallet-per-user
constraint. Since the wallet row is unique per user, onboarding must adopt it.
**Fix:** `WalletService::createNinePsbWallet()` now uses
`Wallet::updateOrCreate(['user_id' => $user->id], […])`.

## Rerun results (final, user `01a0a517-7470-72f4-944d-7c4099a1b3a4`)

| # | Flow | fixpay | Gateway risk gate | Processor | TMS |
|---|---|---|---|---|---|
| T1 | 9PSB wallet open | **201** `9PSB Wallet created successfully` · account `0476177855` | `score status=clear aml=true fraud=false` | 9PSB NUBAN issued | #229 `wallet_open` amt=1 **clear** |
| T2 | 9PSB wallet enquiry | 200 Successful | not risk-gated | OK | — |
| T3 | VTPass airtime ₦100 | **200 COMPLETED** (provider 200) | `ingest velocityBreach=false` | VTPass 200 | #230 `bill_payment` **ingested** |
| T4 | 9PSB transfer ₦150k | 202 FAILED | `ingest velocityBreach=false` → **`score clear aml=false fraud=true`** | 9PSB 400 | #231 `transfer` 150000 **clear** |
| T5 | 9PSB transfer ₦1.5M | 202 FAILED | **`ingest velocityBreach=true rule=sum_1h>500000`** → **`score clear aml=true fraud=true`** | 9PSB 400 | #232 `transfer` 1500000 **clear** |
| T6 | Account lookup | 200 `accountName=Unknown` | proxied (not gated) | OK | — |
| T7 | Meter verify | 200 `code 000` | bypassed | VTPass OK | — |

All TMS rows: `source=mobile`, `business_id=00d4fecd-…`, `device_id=hybrid-dev-01` (single),
rules `version=1.1`, anomaly `reason=normal`.

Before → after, same flows: **T1 500 → 201**, **T3 422 → 200 COMPLETED**,
**wallet_open flagged → clear**, **ML `model_not_trained` → `normal`**, **0 alloy log spam**,
**single device id**.

## Remaining observations (not part of the key findings)

- **Anomaly score magnitude** — *fixed in §10*: `anomaly_scores.score` was ~`1.246e9`; now `0.06–0.34`.
- **9PSB transfer leg still fails** (`400 BAD_REQUEST` from transaction→processor) — the sandbox
  provider leg, unchanged from all prior runs. VTPass bills now complete.
- Native PG (`postgresql-x64-17/18`) remains unstartable without elevation, so the hybrid mode
  uses the Docker `fixpay-db` on `:5433`.

---

# 10. Second round — anomaly-score fix, logging restoration, final rerun

## F8 — the ML anomaly score was meaningless (~1.25e9)

`anomaly_scores.score` was `1246148011.43`. Diagnosis (live probe, `ensemble_scores`):

```
isolation_forest: { score:  0.0443,  is_anomaly: false, probability: 0.4668 }   <- sane
lof:              { score: 3115370028.44, is_anomaly: false, probability: 0.0 } <- broken
ensemble score = 0.6*0.0443 + 0.4*3.115e9 = 1.246e9
```

**Root cause:** `LocalOutlierFactor.decision_function` is unbounded and blows up when the
training matrix is degenerate — LOF is distance-based, the training set contained many
*identical* feature rows (zero reachability distance), and the raw `log_amount` feature
(0–15) sits an order of magnitude above the 0–1 flags beside it. The exponent in the
probability mapping then overflowed, pinning `lof.probability` at `0.0`.

**Fix** (`TMS/antifraud-service/src/ml/detectors/lof_detector.py`):
1. collapse identical rows (`np.unique(..., axis=0)`) before fitting;
2. `StandardScaler` the features (stored with the model, applied at score time);
3. clamp `decision_function` to `±DECISION_CLIP` (5.0) and clip the logistic exponent.

**Verified** — retrained `v20260916.171908` (233 samples), `/v1/ml/reload` → trained:

| | before | after |
|---|---|---|
| `lof.score` | `3,115,370,028.44` | `0.39 / 0.34 / 0.65` |
| `lof.probability` | `0.0` | `0.21 – 0.34` |
| ensemble `score` | `1,246,148,011` | `0.06 – 0.34` |

Real flows still score `normal` (`is_anomaly=false`) — only the synthetic outlier probes flag,
so no behaviour regression.

## F9 — gateway RiskGate logging was lost by my own F6 restart

The F6 restart launched `start-gateway-service.bat` without redirecting stdout, so the running
gateway stopped writing `java-gateway-service.out.log` (its RiskGate lines became invisible —
defeating the purpose of F6). **Fixed** by relaunching with
`-RedirectStandardOutput/-RedirectStandardError`. A direct gateway probe (₦150k bill, which
exceeds the 100k fraud threshold and `block_on_flag=true`) then produced:

```
gateway probe -> HTTP 403
RiskRules refreshed scope=mobile|00d4fecd-… version=1.1
RiskGate ingest ref=38685ffe-… velocityBreach=false rule=null
RiskGate score  ref=38685ffe-… status=flagged aml=false fraud=true
alloy occurrences = 0
```

## Final rerun (fully observable end-to-end)

User `01a0ab3e-dab8-7101-ae86-5b91c7e0698f`:

| # | Flow | fixpay | Gateway (`RiskGate`) | TMS |
|---|---|---|---|---|
| T1 | 9PSB wallet open | **201** account `0579422764` | `score status=clear aml=true fraud=false` | #243 `wallet_open` **clear** |
| T2 | 9PSB wallet enquiry | 200 | not gated | — |
| T3 | VTPass airtime ₦100 | **200 COMPLETED** | `ingest velocityBreach=false` | #244 `bill_payment` **ingested** |
| T4 | 9PSB transfer ₦150k | 202 FAILED | `score clear aml=false fraud=true` | #245 `transfer` **clear** (score 0.2972, `normal`) |
| T5 | 9PSB transfer ₦1.5M | 202 FAILED | `ingest velocityBreach=true rule=sum_1h>500000` → `score clear aml=true fraud=true` | #246 `transfer` **clear** (score 0.2614, `normal`) |
| T6 | Account lookup | 200 `Unknown` | proxied | — |
| T7 | Meter verify | 200 `000` | bypassed | — |
| — | Direct gateway probe ₦150k bill | — | **403 blocked** (`score flagged fraud=true`) | #242 `flagged` |

Every TMS row: `source=mobile`, `business_id=00d4fecd-…`, single `device_id=hybrid-dev-01`,
rules `version=1.1`.

## Summary of the whole exercise

| Finding | Status |
|---|---|
| F1 business-id env drift | ✅ fixed (env) |
| F2 wallet-open AML false positive → 403 | ✅ fixed (distinctive-token gate) |
| F3 ML `model_not_trained` | ✅ fixed (train + reload) |
| F4 rules missing for the native business | ✅ fixed (seeded row, v1.1) |
| F5 duplicated `device_id` | ✅ fixed (`DeviceId` normaliser) |
| F6 OTEL log flood | ✅ fixed, and logging restored (F9) |
| F7 9PSB onboarding unique-constraint 500 | ✅ fixed (`updateOrCreate`) |
| F8 anomaly score ~1.25e9 | ✅ fixed (LOF dedupe/scale/clamp) |

**Still environment/provider-side (not code defects):**
- 9PSB *transfer* leg returns `400 BAD_REQUEST` (transaction→processor) in the sandbox — unchanged
  across every run. The risk gate, TMS ingestion and scoring all complete correctly around it.
- Native `postgresql-x64-17/18` services cannot start without elevation → hybrid mode uses the
  Docker `fixpay-db` on `:5433`.




| TMS | — | not ingested | — | — |

