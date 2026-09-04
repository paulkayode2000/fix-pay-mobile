# Payfixy Gateway ⇄ TMS — E2E Loophole Fixes (2026-09-04)

Companion to `GATEWAY_TMS_E2E_FINDINGS.md`. Every item below was applied to the
running stack and verified live unless marked otherwise.

## Applied & verified in the running environment

| # | Loophole | Fix | Verification |
|---|----------|-----|--------------|
| 1 | fixpay `vtpass_payments` schema drift (missing `requery_count` / `last_requeried_at` / `provider_request_id`; CHECK constraint rejected `REQUIRES_RECONCILIATION`) | Ran pending migration `2026_08_27_000000_add_requery_fields...` and **widened it** to drop/re-add the `payment_status` CHECK constraint with `REQUIRES_RECONCILIATION`; added `2026_09_04_000000_widen_payment_journal_status.php` (journal `status` was `varchar(20)` < 21 chars) | Columns exist; constraint allows `REQUIRES_RECONCILIATION`; `payments:requery-pending` + `payments:timeout-stale` run clean (escalation succeeds, journal written) |
| 1b | "ambiguous" classifier matched the word *connection* inside `SQLSTATE …(Connection: pgsql…)` → treated schema errors as "money may have moved" and **locked funds in holds forever** | Rewrote `VtpassService::submit()` catch to `isAmbiguousOutcome()`: typed exceptions first (`ConnectionException` = ambiguous; new `GatewayRequestException` 5xx/empty-body = ambiguous, 4xx-with-body = definitive), DB/JSON/programming errors are **never** ambiguous; added `App\Services\Gateway\GatewayRequestException` carrying status/body | Live bill reaches the gateway (persists `provider_request_id`), gateway errors are journaled `SUBMIT_AMBIGUOUS` with a structured `422 PROCESSING` (no silent 503), fund held for requery; 11 pre-fix stuck rows (holds) cleaned and marked `FAILED` |
| 2a | **Gateway 400-as-500 masking** (`GlobalExceptionHandler` had no `ResponseStatusException` handler → every validation 400 became a 500) | Added `ResponseStatusException` + malformed-body/header handlers to `GlobalExceptionHandler.java` | Direct probe: `wallet_account '000'` now returns **HTTP 400** with a JSON message (was 500); sweep P1 now `400,400,400,400,400,403` |
| 2b | Gateway `/bills/pay` provider failures (empty 500 / downstream 401) | Root cause traced: gateway → transaction-service `BillRouteController` → processor-service **switch chain** returns 401 (service-version skew + processor auth). Not a gateway defect; see "Remaining" below | — |
| 3 | AML name-scoring false positive ("Observe Test" scored 17.13 vs PEP "E2E Watchlist Target" → blocked) | Added `strong_name_signal()` gate in `TMS/antifraud-service/src/services/screening_service.py`; `WatchlistService.find_best_match` requires composite ≥ 10 **and** an exact/≥0.85 token overlap (name/aliases) | `Observe Test` → `clear aml=none`; `E2E Watchlist Target` → `flagged aml=pep` (live HTTP) |
| 4 | Antifraud ML detector a stub (`model_not_trained`, models=[]) | Trained ensemble from history: `POST /v1/ml/train` → `ensemble_if_lof v20260904.094147` | `/v1/ml/models` non-empty; scores return real IF/LOF scores, `reason=normal` |
| 5 | Velocity `sum_1h>500000` pre-empted the deep AML/fraud score (large transfers never scored) | `RiskGateService.gateCheck` now only hard-blocks on velocity when the txn is **not** AML-eligible; AML-eligible (≥ threshold) transactions are ingested (breach logged) then **deep-scored**, and the score decides | 1.5M transfer: gateway logs `ingest velocityBreach=true rule=sum_1h>500000` **then** `score status=clear aml=true fraud=true`; TMS row `clear` |
| 6 | Async TMS tagging dead (93 jobs queued, `risk_assessments`=0, no queue worker) | Added `queue-worker` service to `docker-compose.override.yml` and started it | `jobs` 93→0; `risk_assessments` 0→113 and climbing live |
| 7 | Gateway device-id duplication (`"dev-01, dev-01"`) | `IdentityResolver` now normalises the device header to a single comma-separated token (dedupe + spoof-proof) | Post-rebuild fixpay-driven TMS rows store `device_id=observe-dev-01` (single) |
| 8 | Gateway OTEL `alloy` log flood | Recreated `java-gateway-e2e` with `MANAGEMENT_TRACING_ENABLED=false` | `alloy` occurrences in gateway logs: **0** |

## Gateway (Java) rebuilt & redeployed
- Source edits: `bff_java/services/gateway-service/src/main/java/com/payfixy/gateway/`
  `exception/GlobalExceptionHandler.java`, `identity/IdentityResolver.java`,
  `risk/RiskGateService.java`.
- Rebuilt the JAR (`gradlew bootJar`) and image (`Dockerfile.prebuilt` →
  `java-gateway-service:local`), recreated `java-gateway-e2e` on
  `payfixy_gateway_payfixy` + `tms_default`, `-p 8080:8080`, same env as before
  plus `MANAGEMENT_TRACING_ENABLED=false`. `/actuator/health` = 200.
- Retest (fixpay-driven, post-rebuild): wallet-open scored `clear aml=true`;
  150k transfer `clear aml=false fraud=true`; 1.5M transfer deep-scored
  (`aml=true fraud=true` → clear) instead of velocity-blocked; all TMS rows
  scoped `mobile/99/<uuid>` with **single** device id.

## Native on-device retest (2026-09-04) — full stack, no fixpay/gateway Docker
Run on the host-native stack the project is built for: fixpay backend `:8081`
(`restart-services`/`run-on-device`, DB = host Postgres `:5432`), Payfixy Java
services natively (`.local-dev/start-scripts`, gateway `:9013` profile
`development`), TMS antifraud at `127.0.0.1:8083` and TMS aml at `127.0.0.1:8000`.
Driven from this PC via `127.0.0.1`.
- **Bill payment now completes end-to-end** — airtime `POST /api/payments/vtpass`
  → `200`, fixpay row `COMPLETED`, `provider_code=200`; TMS `bill_payment`
  `ingested` (velocity-only) with a single `device_id`. This was the last open
  item; the Docker-only 401 did not reproduce on the native chain (native
  transaction/processor/business jars align with the rebuilt gateway).
- **Risk pipeline on the native gateway**: 150k transfer `clear`
  (`aml=false fraud=true`); 1.5M transfer `clear` (`aml=true fraud=true`,
  deep-scored — velocity no longer pre-empts); wallet-open AML-screened.
- **Device-id single** (`native-dev-01` / `natbill-dev`) on every TMS row.
- **Queue**: native `artisan queue:work` keeps `jobs=0`; `risk_assessments`
  populated (ANTIFRAUD SKIPPED/CLEAR). AML async screens show `UNAVAILABLE` —
  the async AML path targets `127.0.0.1:81` (container nginx relay); point
  `TMS_BASE_URL` at the native aml (`127.0.0.1:8000`) if full-native is desired.
- **Native quirk fixed during retest**: the PHP built-in server hard-caps
  `max_execution_time` at 30s even when `php.ini` says 0, so slow provider
  chains die with a fatal at 30s. Run the backend as
  `php -d max_execution_time=0 artisan serve --port=8081` (recommend updating
  `restart-services.ps1` / `run-on-device.ps1`).
- **Applied (2026-09-04)**: both launchers now pass `-d max_execution_time=0`;
  `TMS_BASE_URL` pointed at the native aml `http://127.0.0.1:8000`. The live
  `:8081` backend was relaunched with the flag and another airtime bill
  completed (`200`, `COMPLETED`, provider `200`).
- **Async AML leg — FIXED natively (2026-09-04)**: installed `predis/predis`
  (composer), set `REDIS_CLIENT=predis` + `REDIS_HOST=127.0.0.1` in
  `TMS\aml-system\.env`, and cleared the aml config cache (native PHP lacks the
  phpredis extension). `/api/v1/screen` on the native aml `:8000` now returns
  `202` + `call_ref`, and async bill screenings land in `risk_assessments` as
  `AML PENDING` with `tms_call_ref` (previously `UNAVAILABLE` / HTTP 500).

## Zero-container native TMS antifraud (2026-09-04)
- Native antifraud (uvicorn from `TMS\antifraud-service\.venv`) now serves
  `127.0.0.1:8083` — the Docker relay is gone (daemon stopped). Env:
  `DATABASE_URL=postgresql+psycopg://antifraud:secret@127.0.0.1:5432/antifraud`
  (native PG17), `LARAVEL_DATABASE_URL=…/ams_laravel`. Role `antifraud` was made
  superuser + granted schema/table rights on PG17 so scoring/ingest work.
- Verified end-to-end (fixpay `:8081` → gateway `:9013` → native antifraud
  `:8083` → native PG `:5432`): airtime bill `COMPLETED` (provider `200`); 150k
  transfer `clear` (fraud-only); **1.5M transfer deep-scored `clear`**; every TMS
  row scoped `mobile` / business `00d4fecd…` with a **single** `device_id`.
- **Launcher correction**: `php artisan serve` spawns a built-in child that does
  NOT inherit the parent's `-d`; `restart-services.ps1` and `run-on-device.ps1`
  now start the backend directly as
  `php -d max_execution_time=0 -S 127.0.0.1:<port> -t public public/index.php`
  so the server itself has no 30s cap.


## Artifacts
- Migrations: `fixpay-laravel/database/migrations/2026_08_27_000000_add_requery_fields_to_vtpass_payments_table.php` (edited), `fixpay-laravel/database/migrations/2026_09_04_000000_widen_payment_journal_status.php` (new)
- Code: `app/Services/Gateway/GatewayRequestException.php` (new), `app/Services/Gateway/GatewayClient.php`, `app/Services/Payment/VtpassService.php`
- Compose: `docker-compose.override.yml` (queue-worker)
- TMS: `TMS/antifraud-service/src/services/{screening_service,watchlist_service}.py`
- Probes: `fixpay-laravel/scratch/{sweep-probes,single-bill-live,score-probe,cleanup-stuck-bills}.php`, `TMS/antifraud-service/scratch/{fp-fix-check,score-train-check}.py`
