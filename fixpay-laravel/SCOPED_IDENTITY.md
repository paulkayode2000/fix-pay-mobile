# Scoped Identity Contract (source · business · user)

> **Status:** Implemented — velocity/blocking is per `(source, business_id,
> user_id)` with an optional device-level correlation bucket. This replaces the
> old business-only velocity bucket, which let one user's burst block an entire
> business (and let a second user bypass a business-level block).

## Why

Each product owns its own user directory:

| source        | user directory                        | business_id meaning         |
|---------------|---------------------------------------|-----------------------------|
| `gateway`     | gateway-service `User`/`Business`     | gateway business (API key)  |
| `mobile`      | fixpay Postgres `AppUser` + `Tenant`  | tenant → gateway business   |
| `pos`         | future PoS product                    | PoS merchant                |
| `portal`/`agent`/`widget` | future products            | their own tenant            |

These directories use independent id spaces, so a bare `user_id` or
`business_id` is meaningless outside its `source`. TMS stays a **stateless
scorer** — it never registers users/businesses; it only keys its rolling
counters on the identity each caller presents.

## Header contract

Canonical HTTP headers (constants in `IdentityHeaders`):

| Header          | Set by            | Description                                        |
|-----------------|-------------------|----------------------------------------------------|
| `X-Source`      | backend (gateway) | channel that owns the user directory. **Never** taken from clients. |
| `X-Business-Id` | backend           | tenant/business within the source.                 |
| `X-User-Id`     | backend           | end-user id, local to the source.                  |
| `X-Device-Id`   | backend           | device fingerprint — cross-channel correlation key.|
| `X-Request-Id`  | backend           | trace/correlation id.                              |

The **gateway derives** `source` from its route (`/api/v1/mobile/**` → `mobile`,
future `/api/v1/pos/**` → `pos`), reads `X-User-Id`/`X-Device-Id`/`X-Business-Id`
from the trusted backend (Laravel / JWT), and forwards them to risk services in
the score/ingest payload `metadata`. Client-supplied `X-Source` is ignored
(gateway pins it); `X-User-Id`/`X-Business-Id` are only honored after
API-key/JWT authentication binds the caller.

## TMS payload metadata

The antifraud-service reads scoped identity from `metadata`:

```json
{
  "ref_no": "...",
  "customer_id": 99,
  "amount": 100,
  "currency": "NGN",
  "type": "bill_payment",
  "metadata": {
    "source": "mobile",
    "business_id": "99",
    "user_id": "demo-user-A",
    "device_id": "dev-A",
    "correlation": { "device_id": "dev-A", "phone": "...", "email": "..." }
  }
}
```

- `extract_identity()` (velocity.py) extracts `source/business_id/user_id/device_id`.
- `compute_velocity()` filters the rolling-window counters by whatever scoped
  dimensions are present. Primary bucket `(source, business_id, user_id)`;
  passing `device_id` adds the cross-channel correlation bucket.
- Legacy callers with no metadata still get business-level velocity via
  `customer_id` fallback.
- The identity is **persisted** on `transactions` (`source`, `business_id`,
  `user_id`, `device_id` columns) so counters are exact per bucket.

## Channel registry

| route prefix        | source       | notes                                   |
|---------------------|--------------|-----------------------------------------|
| `/api/v1/mobile/**` | `mobile`     | fixpay-mobile PWA (Laravel-backed)      |
| `/api/v1/pos/**`    | `pos`        | future PoS product (plug-in)            |
| `/api/v1/payment-page/**` | `payment_page` | future hosted checkout           |
| `/api/v1/agent/**`  | `agent`      | future agent network                    |
| `/api/v1/widget/**` | `widget`     | future embeddable widget                |
| other               | `gateway`    | gateway-native flows (JWT `sub`)        |

## Rules authority + version guard

Thresholds and on/off switches are **not hardcoded anywhere**. TMS is the single
source of truth:

```http
GET /v1/rules?source=mobile&business_id=99
→ { "version": "1.0", "velocity": {...}, "aml": {"enabled": true, "amount_threshold": 1000000.00, "screen_wallet_open": true}, "fraud": {"enabled": true, "amount_threshold": 100000.00}, "block_on_flag": true }
```

Requesting entities (gateway, fixpay-mobile, POS) **fetch the ruleset, cache it
in memory (TTL ~60s), and decide locally BEFORE dispatching**: velocity always
(all amounts), AML at/above its threshold, fraud at/above its threshold.

Every `/ingest` and `/score` call carries the version:

```http
POST /v1/transactions/score
  X-Rules-Version: 1.0
```

- **Stale version → `412 Precondition Failed`** with `{code:"RULES_VERSION_STALE",
  current_version, rules}` — the inline ruleset lets the client refresh without
  an extra round-trip, then retry once (safe: both endpoints upsert by ref_no).
- **Version scope is per business** — composite `"{global}.{override}"`. A global
  change bumps `global` (invalidates everyone); a business override bumps only
  that business's `override`. `X-Rules-Version` is **lenient** today: a missing
  header is allowed (warn); a stale value still 412s. Flip `rules_version_mode=strict`
  once all clients send it.
- **Admin**: `PUT /v1/admin/rules` (global) and `PUT /v1/admin/rules/{business_id}`
  (override) bump the version.
- **Checks**: clients send `aml_check`/`fraud_check` booleans; TMS runs the
  AML/PEP watchlist and/or the ML fraud score accordingly (`checks` echoed back).

## Verification (E2E)

`java-scoped-identity-e2e.ps1` (fixpay-mobile root) proves:

1. **Per-user isolation** — user A bursts 6 (5 allowed + 6th `403 count_1m>5`);
   user B is unaffected (allowed).
2. **Cross-channel** — `pos/user 42` bursts and breaches; `mobile/user 42`
   starts at count 1 (separate bucket).
3. **Device correlation** — same device across two channels accumulates a
   device-level row set.
4. **Spoofing rejection** — client `X-Source: pos` on a mobile route is
   ignored; the row is stored `source=mobile`.
5. **PoS plug-in** — `source=pos` scores and enforces velocity identically.
