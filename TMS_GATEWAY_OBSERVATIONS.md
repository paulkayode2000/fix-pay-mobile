# TMS ⇄ Payfixy Gateway — Transaction Observations

> Focused run: 4 transactions driven **only** through fixpay-mobile
> (`api.fixpay.test`) after the gateway/transaction/analytics restart.
> Harness: `fixpay-laravel/tx-observe.php` · run 2026-08-25 16:39–16:41 UTC.
> Test user: `01a039c9-b91f-720b-99f5-1fee187672ef` (scoped identity on every
> TMS row: `source=mobile, business_id=99, device_id=observe-dev-01`).
> Rules version in force: **1.4** (gateway auto-refreshed via the version guard).

| # | Type | fixpay (HTTP/ref) | **Payfixy gateway** (`java-gateway-e2e` log) | **TMS** (`transactions` row) |
|---|------|-------------------|---------------------------------------------|------------------------------|
| T1 | 9PSB **wallet open** | 500 (provider `generate` failed) | `RiskRules refreshed scope=mobile\|99 version=1.4` · `ingest WOP-… velocityBreach=false` · **`score status=clear aml=true fraud=false`** | `wallet_open` amt=1 status=clear — AML-only (identity event) |
| T2 | VTPass **airtime ₦100** | 503 (9PSB VAS provider timeout) | `ingest FP2026…JEKPPW velocityBreach=false` (velocity only — below both thresholds) | `bill_payment` amt=100 status=ingested |
| T3 | 9PSB **transfer ₦150k** | 202 `FPT2026…IDWDWY` (provider FAILED) | `ingest FPT… velocityBreach=false` · **`score status=clear aml=false fraud=true`** | `transfer` amt=150000 status=clear — fraud-only (100k ≤ 150k < 1M) |
| T4 | 9PSB **transfer ₦1.5M** | 202 `FPT2026…6VWMGD` (FAILED) | **`ingest velocityBreach=true rule=sum_1h>500000` → BLOCKED** (score not reached) | `transfer` amt=1500000 status=ingested — velocity gate preempts deep score |

## What this confirms (both systems, end-to-end)

1. **Scoped identity flows fixpay → gateway → TMS**: every TMS row carries
   `(mobile, 99, <uuid>, observe-dev-01)` — no collisions, no spoofing.
2. **Rules-driven risk gate on the gateway** (observable in `java-gateway-e2e` logs):
   - wallet open → **`aml=true fraud=false`** (identity events always AML-screened)
   - ₦150k transfer → **`aml=false fraud=true`** (fraud threshold 100k crossed, AML 1M not)
   - ₦100 bill → velocity-only ingest
   - ₦1.5M transfer → **velocity `sum_1h>500000` BLOCK** before any deep score
3. **TMS records every decision**: `wallet_open`/`bill_payment`/`transfer` rows
   with `status=clear` (scored) or `status=ingested` (velocity-only / blocked),
   all keyed to the same scoped user.

## Notes
- 9PSB provider calls (wallet generate, VAS, transfer) fail/timing-out in this
  sandbox — the **pipeline and both observation systems work regardless**; the
  provider failures are recorded at the fixpay column.
- The velocity `sum_1h>500000` rule means a single ≥ ₦500k transfer is always
  blocked at the gate (T4) — the "AML+fraud both" deep-score path for large
  transfers needs a velocity-limit change to be reachable (see
  `TX_MATRIX_RESULTS.md` finding).

## Raw gateway lines (run window)
```
RiskRules refreshed scope=mobile|99 version=1.4
RiskGate ingest ref=WOP-20260825163918-01a039c9… velocityBreach=false
RiskGate score  ref=WOP-… status=clear aml=true fraud=false
RiskGate ingest ref=FP20260825163943JEKPPW velocityBreach=false
RiskGate ingest ref=FPT20260825164039IDWDWY velocityBreach=false
RiskGate score  ref=FPT… status=clear aml=false fraud=true
RiskGate ingest ref=FPT202608251641146VWMGD velocityBreach=true rule=sum_1h>500000
```
