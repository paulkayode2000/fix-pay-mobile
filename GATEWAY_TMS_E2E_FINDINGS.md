# Payfixy Gateway ⇄ TMS — Fresh E2E Sweep (2026-09-04)

> **Fix status:** items 1, 3, 4, 5, 6, 7, 8 and the 400-as-500 part of item 2
> were **fixed & verified live** on 2026-09-04 — see `GATEWAY_TMS_E2E_FIXES.md`.
> The gateway (Java) was rebuilt and redeployed with the fixes. Remaining: the
> bill-payment provider leg (`transaction-service → processor-service` switch
> chain 401 + 9PSB VAS sandbox timeouts) needs a coordinated multi-service
> rebuild/redeploy in the Payfixy_Gateway repo.

**Run:** fixpay-mobile (Laravel, Docker `fixpay-backend`) → `gw.payfixy.test`
(`java-gateway-e2e` :8080) → `antifraud.tms.test` (`antifraud-api`) / TMS postgres.
Drivers: `fixpay-laravel/tx-observe.php`, `fixpay-laravel/tx-matrix.php`, new
`fixpay-laravel/scratch/sweep-probes.php`, `single-bill-live.php`, and direct
TMS DB/log correlation. Raw outputs: `tx-observe-run2.out`, `tx-matrix-run2.out`,
`gateway-tms-e2e-results.json`.

## Pre-flight fixes required to make a fresh run possible
1. **Gateway cluster was down.** `java-gateway-e2e`, `java-transaction-e2e`,
   `java-processor-e2e`, `java-business-e2e` were all `Exited (255)` (Docker
   restart with no restart policy). Started all four → `GET /actuator/health` = UP.
2. **`.env` config drift (broken in-container wiring).** `fixpay-laravel/.env`
   pointed at host loopback (`127.0.0.1:9013/81/8083`) which is unreachable from
   inside `fixpay-backend`. Aligned to the documented ingress contract
   (`INGRESS.md`): `gw.payfixy.test`, `aml.tms.test`, `antifraud.tms.test`.
3. **`.env` business-id drift → gateway 401 "Invalid API key".** The gateway maps
   the test secret key to its internal business **99**; fixpay was sending UUID
   `00d4fecd-…`. Set `PAYFIXY_GATEWAY_BUSINESS_ID=99` (backup:
   `.env.e2e-prefix-20260904-100454.bak`).
4. **Leftover test watchlist seed** (`E2E Watchlist Target`, `source=test-seed`,
   created 2026-09-03) was making *every* wallet-open flag/block (see Finding 3).
   Removed for the matrix baseline.

## What still works (regression pass — no loopholes found here)
| Check | Result |
|---|---|
| Scoped identity end-to-end | Every TMS row `source=mobile, business_id=99, user_id=<uuid>` |
| Wallet open AML screen | `clear aml=true fraud=false` (identity event) |
| ₦150k transfer fraud screen | `clear aml=false fraud=true` (fraud-only) |
| ≥ ₦500k transfer | `sum_1h>500000` velocity BLOCK (TMS status `ingested`) |
| Rules version guard | stale `X-Rules-Version: 0.0` → HTTP 412 + inline rules |
| Rules bump auto-refresh | gateway refreshes 1.0 → 1.1 without restart |
| Per-user velocity isolation | A: 5× then 6th 403 · B: unaffected |
| X-Source / X-Business-Id spoof | client `X-Source: pos` → stored `mobile`; `X-Business-Id: 999` → stored `99` |
| Idempotency (same key ×2) | only 1 TMS row (dedupe at ingest) |
| Earlier fixes (canonical `9psb` name, single idempotency) | no regression |

---

## Findings — loopholes still to fix (ranked)

### 1. 🔴 HIGH — fixpay bill payments are broken: schema drift + "ambiguous" classifier traps funds in holds
- `VtpassService::submit()` writes `provider_request_id`, `last_requeried_at`,
  `requery_count`, and the requery scheduler writes status `REQUIRES_RECONCILIATION`,
  but **the live `vtpass_payments` table has none of those columns and its CHECK
  constraint only allows `PENDING|PROCESSING|COMPLETED|FAILED|REVERSED`**.
  Migration `2026_08_27_000000_add_requery_fields_to_vtpass_payments_table.php`
  was never run (`migrations` table: only the original `2026_01_01_…` batch).
- Result for **every** bill (M3–M8 and live repro `single-bill-live.php`):
  submit throws on the first column write → the exception string contains
  `(Connection: pgsql, …)`, so `str_contains($msg, 'connection')` in
  `VtpassService::submit()` classifies it **ambiguous** ("money may have moved —
  keep the hold") → the follow-up write of `last_requeried_at` then also fails →
  a raw exception reaches the controller → HTTP 503, payment stuck `PROCESSING`
  forever with only `HOLD`+`SUBMIT` journal rows, **user funds locked in the hold**.
- The bills never even reached the gateway (no `GatewayClient: POST /bills/pay`
  log, no TMS `bill_payment` row). Direct gateway bill calls DO ingest into TMS
  (status `ingested`) before failing (see Finding 2).
- **Fix:** run the pending migration; scope the ambiguity classifier to genuine
  transport errors (`ConnectionException`, timeouts, DNS) and **never** substring
  match `"connection"` inside an exception message.

### 2. 🟠 HIGH — gateway `/bills/pay` returns HTTP 500 with an empty body (after ingesting), and masks 400s as 500s
- Direct `POST /api/v1/mobile/bills/pay`: airtime → HTTP 500 after ~24 s;
  data/electricity → HTTP 500 in <1 s. A TMS `bill_payment` row is created
  (`status=ingested`) *before* the failure — provider leg erroring.
- Validation failures are also surfaced as HTTP 500: a 10-digit NUBAN check
  rejection (wallet `'000'`) returns `500` (P1: `500,500,500,500,500,403`), not a
  4xx. Clients/consumers can't distinguish a bad request from a server fault.
- **Fix (gateway repo):** map downstream provider/validation results to proper
  status codes + structured bodies; the risk-ingest-first ordering is fine but
  the payment leg error should carry a machine-readable body.

### 3. 🟠 MEDIUM — AML name-scoring false positive flags/blocks ordinary users
- With a single seeded PEP entry (`E2E Watchlist Target`, jurisdiction NG),
  wallet-open for `Observe Test` scored **17.13** (threshold 10.0) purely from
  token Jaro–Winkler similarity of generic words (`test`↔`target`), giving
  `status=flagged aml=true` and **HTTP 403 blocking wallet creation**.
- 8/25 runs appeared "clean" only because the watchlist was empty — the matcher
  had never really been exercised. False-positive blocking with
  `block_on_flag=true` is a real availability risk; conversely the low 10-point
  threshold and pure-string matching deserve a review (add DOB/country weight,
  require a proper name-token match, alias-only matches, etc.).

### 4. 🟠 MEDIUM — antifraud ML/anomaly detector is a stub (`model_not_trained`)
- `GET /v1/ml/models` → `[]`; scored responses return
  `anomaly.reason: model_not_trained`, `ensemble_scores: {}`,
  `is_anomaly: false` always. Only the rules-based fraud threshold and velocity
  gate actually enforce anything. Train the ensemble (POST `/v1/ml/train`) or
  wire the routed XGBoost path, else the "ML fraud" leg is decoration.

### 5. 🟡 MEDIUM — velocity `sum_1h>500000` preempts the deep AML+fraud score (tuning)
- Re-confirmed M14/M15: any single ≥ ₦500k transfer is blocked at the velocity
  gate *before* the AML/fraud deep score runs (TMS status stays `ingested`).
  The "AML+fraud both flagged" scored path is unreachable under current limits.
  Decide: raise `sum_1h`/`sum_24h`, exempt deep-scored items, or score-then-block.

### 6. 🟡 MEDIUM — async TMS tagging is dead in the fixpay Docker stack
- `QUEUE_CONNECTION=database` and no queue worker in the fixpay compose stack →
  **93 jobs pending in `jobs`, `risk_assessments` = 0** after a full matrix.
  Every bill dispatch of `AntifraudScoreJob` / `TransactionAmlScreenJob` sits
  unprocessed. The synchronous gateway risk gate still enforces live, but the
  async AML/risk tagging pipeline (the data the admin console shows) never runs.

### 7. 🔵 LOW — gateway device-id duplication (data quality / correlation)
- When fixpay sends both `X-Device-ID` and `X-Device-Id` (its guard headers send
  both, same value), the gateway persists `device_id = "dev-01, dev-01"` on TMS
  rows (and inside `metadata.correlation.device_id`). Direct calls with only
  `X-Device-Id` store a single value, so the gateway is merging both header
  spellings. Pick one canonical header server-side.

### 8. 🔵 LOW — gateway OTEL exporter floods logs (observability defect)
- `java-gateway-e2e` logs are drowned in
  `Failed to export spans … UnknownHostException: alloy` retries (OTEL endpoint
  `alloy:4318` does not resolve on this network). This buries the `RiskGate`
  ingest/score lines the whole monitoring story depends on. Point OTEL at a real
  collector or disable it in this environment.

### 9. 🔵 LOW/INFO — reads bypass the risk pipeline (documented, not a defect)
- Meter verify (M9), account lookup (M11), and wallet enquiry (M2) go direct to
  the processor/VTPass and are not recorded in TMS. Acceptable for read-only
  calls, but name-enquiry volume is exactly what structuring/OSINT tools mine —
  consider at least logging them.

### 10. 🔵 LOW/INFO — `vtpass_payments` status enum drift (same root as #1)
- Requery scheduler fails every cycle trying to set
  `REQUIRES_RECONCILIATION`/`UNAVAILABLE` on old stuck payments because the DB
  CHECK constraint (and the migration that extends it) is missing. Subsumed by
  Finding 1's migration fix.

---

## Environment limitations (not pipeline defects)
- 9PSB sandbox (`102.216.128.75:9090/waas`) wallet-generate, VAS and transfer
  calls time out / fail → provider-level 500s on M1/M3–M8/M10 etc. VTPass sandbox
  responds (`000` on M9).
- `java-transaction-e2e` webhook scheduler churns "0 delivered, 50 failed" every
  minute (no webhook consumer) — harmless, noisy.
- Gateway container healthcheck is misconfigured: its own `wget
  localhost:8080/actuator/health` returns **401** (actuator behind Spring
  Security), so Docker reports `java-gateway-e2e (unhealthy)` while the endpoint
  answers 200 to every real client. Fix the healthcheck URL/probe.

## Recommended next actions
1. fixpay-laravel: run the pending vtpass requery-fields migration; tighten the
   ambiguous-failure classifier (transport only).
2. Gateway repo: return structured 4xx/5xx + bodies from `/bills/pay`; pick one
   device header; fix/disable OTEL `alloy` exporter.
3. TMS antifraud: train the model or remove the stub path; review the 10-point
   AML match threshold / token-only matching; decide the velocity-vs-deep-score
   precedence for large transfers.
4. fixpay compose: add a queue worker (or flip `QUEUE_CONNECTION` to a real
   broker) so `AntifraudScoreJob`/`TransactionAmlScreenJob` actually run.

## Artifacts
- `gateway-tms-e2e-results.json` — structured run results
- `tx-observe-run2.out`, `tx-matrix-run2.out` — raw harness output
- `fixpay-laravel/scratch/{sweep-probes,single-bill-live,gw-bills-probe,gw-auth-probe,config-probe}.php` — reproducers/harnesses
- `.env` backup: `fixpay-laravel/.env.e2e-prefix-20260904-100454.bak`
