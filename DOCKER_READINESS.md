# fixpay on Docker — CI/CD & hosting readiness

Verified locally on 2026-10-15 (Docker Desktop, project `fixpay-mobile`, shared
`tms_default` ingress network) by building and running the whole stack, then
probing it over HTTP and through the queue.

**Answer: the application plane ran fully on Docker; the integration plane did
not, and the deploy job could not have produced a working prod stack.** Three
blockers were found and are now fixed and re-verified (P1–P3 below). The items
that remain open are listed at the end.

## Verified working (with evidence)

| Check | How | Result |
|---|---|---|
| Whole stack builds and starts | `docker compose up -d --build` | `db`, `backend`, `api_webserver`, `queue-worker`, `frontend` all `Up` |
| Laravel boots in-container | `GET :8000/up` | `200` |
| Routing + validation + DB | `POST :8000/api/auth/login {}` + `X-Idempotency-Key` | `422` (no key → `400`) |
| New wallet-less rail resolves | `POST :8000/api/payments/alternative/initiate` | `401` (route registered, auth guard active) |
| Container → Postgres over Docker DNS | `php artisan migrate:status` inside `fixpay-backend` | `db=fixpay driver=pgsql`; `2026_10_15_000000_create_alternative_payments_table ... Ran` |
| PWA served | `GET :8082/` | `200` |
| PWA → API through the PWA nginx proxy | `POST :8082/api/auth/login` | `422` (container→container via service DNS `api_webserver`) |
| Queue worker consumes real jobs | dispatch `App\Jobs\DeliverWebhookJob` | pending `1 → 0` in <10 s; worker log shows the job `DONE`; `failed_jobs` unchanged |

## Blockers

### P1 — Outbound rails are host-loopback-only  → **FIXED**
`fixpay-laravel/.env` is bind-mounted into the container, so the container
inherits host-scoped endpoints:

```
PAYFIXY_GATEWAY_BASE_URL=http://127.0.0.1:9013
TMS_BASE_URL=http://127.0.0.1:81
TMS_ANTIFRAUD_URL=http://127.0.0.1:8083
```

From inside `fixpay-backend` all three answer `000` (connection failed) — inside a
container `127.0.0.1` is the container itself. The gateway *is* alive on the host:
`curl http://host.docker.internal:9013/` from inside the container answers `401`.

Consequences while containerised: the Payfixy Gateway rail cannot take a payment,
and TMS risk tagging / AML screening silently do nothing. The four
`AntifraudScoreJob` rows in `failed_jobs` (dated 2026-09-15) are exactly this
symptom.

`INGRESS.md` already defines the intended contract (`gw.payfixy.test`,
`aml.tms.test`, `antifraud.tms.test`, resolvable from containers via the Traefik
router aliases and from the host via `hosts`). That ingress is currently **down**
(no `tms-router` container, no `hosts` entries, `api.fixpay.test/up` → `000`).

Fix that leaves native runs on `127.0.0.1` untouched (compose `environment:`
beats `.env`):

```yaml
  backend:
    environment:
      PAYFIXY_GATEWAY_BASE_URL: ${PAYFIXY_GATEWAY_BASE_URL_DOCKER:-http://host.docker.internal:9013}
      TMS_BASE_URL: ${TMS_BASE_URL_DOCKER:-http://host.docker.internal:81}
      TMS_ANTIFRAUD_URL: ${TMS_ANTIFRAUD_URL_DOCKER:-http://host.docker.internal:8083}
    extra_hosts:
      - "host.docker.internal:host-gateway"   # required on Linux hosts
```

Apply the same to `queue-worker`. On the OCI host, prefer the `INGRESS.md`
hostnames once that router is up.

**Fixed:** the `x-backend` anchor in `docker-compose.yml` now sets all three
URLs for `backend` *and* `queue-worker` (with `extra_hosts:
host.docker.internal:host-gateway`), verified: from inside `fixpay-backend`,
`config('services.gateway.base_url')` = `http://host.docker.internal:9013` and
`curl http://host.docker.internal:9013/` → `401`, while the old
`127.0.0.1:9013` still answers `000`. Native runs keep using `.env`. Per host,
override with `PAYFIXY_GATEWAY_BASE_URL_DOCKER`, `TMS_BASE_URL_DOCKER`,
`TMS_ANTIFRAUD_URL_DOCKER` (e.g. `http://gw.payfixy.test` when the gateway runs
in Docker on the same host — `host.docker.internal` only reaches the *host*, not
its sibling containers). TMS antifraud/AML still answer `000` here simply
because that stack is not currently running.

### P2 — The deploy job does not ship the override, so prod has no worker and no ingress  → **FIXED**
`deploy-oci.yml` SCPs `docker-compose.yml,nginx-backend.conf,fixpay-laravel,fixpay-pwa`
— `docker-compose.override.yml` is **not** in that list. Verified:

```
docker compose -p fixpay -f docker-compose.yml config --services
  → db, backend, api_webserver, frontend        (no queue-worker)
```

* no `queue-worker` on the server → every async job (risk scoring, AML, webhooks)
  never runs;
* no `tms_default` attachment and **no published port on `frontend`** → nothing
  serves `app.fixpay.test` / `api.fixpay.test`;
* image names resolve to `fixpay-backend` / `fixpay-frontend` for project
  `fixpay`, but the override pins the worker to `image: fixpay-mobile-backend` →
  even if the override were shipped, the worker fails with *pull access denied*
  on any project not named `fixpay-mobile`.

Fix: give the backend an explicit tag (`image: fixpay-backend:local`), point
`queue-worker` at that same tag, and ship the override (or fold the worker into
the base file and add `-p fixpay` to the deploy commands).

Related: the base compose still bind-mounts `./fixpay-laravel:/var/www`, so the
container serves the *mounted* source and the image's baked `vendor/` is shadowed
— while the SCP `exclude` list skips `fixpay-laravel/vendor`. A fresh server
therefore has no `vendor/` under the mount and the API cannot autoload; it only
works if that directory survived from an earlier manual `composer install`.
Either drop the bind mount in prod (run the image) or run
`composer install --no-dev` on the server.

Result: the base compose now serves **all five services** (`db, backend,
api_webserver, frontend, queue-worker`) with explicit images
`fixpay-backend:local` / `fixpay-frontend:local`, and `deploy-oci.yml` SCPs
`docker-compose.override.yml` and creates the `tms_default` network if missing.
Verified with `docker compose -p fixpay -f docker-compose.yml config`, which is
how the server resolves the file stack.

Also fixed: the source bind mount shadows the image's `vendor/`, which SCP
excludes. The deploy now runs in-container against the mounted tree, so the
release step was given `migrate --force` + `config:cache` (see P3) — but if the
API ever fails to autoload on a fresh server, run `composer install --no-dev`
there or drop the bind mount and serve the built image.

### P3 — No migration, secret or health lifecycle  → **FIXED**
* No healthchecks anywhere, and `depends_on` without
  `condition: service_healthy` → the API can boot before Postgres accepts
  connections (no ordering guarantee at all).
* The deploy's only action is `docker compose up -d --build` — no
  `php artisan migrate --force`, no `config:cache`/`route:cache`. Schema changes
  (e.g. `alternative_payments`) never reach the server.
* `.env` is excluded from SCP and there is no `env_file`/GitHub-secret
  templating, so `APP_KEY` and DB credentials must pre-exist on the server by
  hand, undocumented.
* The workflow chowns `fixpay-laravel/storage` only. With the source bind-mounted
  in prod, `bootstrap/cache` stays owned by `ubuntu` and Laravel (`www-data`)
  cannot write its package manifests.
* Observed live: nginx resolves `fastcgi_pass backend:9000` once at startup, so
  recreating the `backend` container alone 502s the API until `api_webserver` is
  restarted. The deploy's `down` + `up` hides this; a partial update will not.
  Optional hardening: `resolver 127.0.0.11 valid=10s;` + `set $fpm backend:9000;`.

**Fixed:** every service now has a healthcheck, and `depends_on` uses
`condition: service_healthy` (`db` → `backend` → `api_webserver` → `frontend`,
`queue-worker` → `db`). Observed live: `db Up (healthy)`, `backend Up (healthy)`,
`api_webserver`/`frontend`/`queue-worker` healthy, with `backend Waiting` on the
db gate.

The first cut used a 5 s probe timeout and had to be re-tuned after it
false-failed here: `wget /up` boots the framework, and on a Windows bind mount
that takes 1.7–6 s (`docker exec` overhead alone is 1–2 s), so timed-out probes
piled up php-fpm workers and the container stayed `unhealthy` while
`curl :8000/up` from the host answered `200`. Final values: api_webserver
`interval 30s / timeout 15s / retries 5 / start_period 90s`, frontend
`timeout 10s`, backend + queue-worker `timeout 10s`. The PWA also gates on
`service_started` for the API rather than `service_healthy`, so a slow cold boot
can never stall a deploy — the workflow's `curl /up` retry loop is the real gate.

The worker was moved into the base compose file so every deployment
gets one. `deploy-oci.yml` now also:

* fails fast with a clear message when `fixpay-laravel/.env` is missing on the
  server (it is excluded from SCP by design);
* `docker network create tms_default` (idempotent) before `up`, so the ingress
  attachment cannot hard-fail a first deploy;
* `chown -R 33:33` + `chmod -R 775` on **both** `storage` and `bootstrap/cache`;
* generates `APP_KEY` only if the host `.env` has none, then runs
  `migrate --force`, `config:cache`, `route:cache`, `view:cache`;
* smoke-tests the release: `curl /up` (30 × 3 s retry), `curl :8082/`, and
  `docker compose ps --status running --services | grep -qx queue-worker`,
  dumping logs and failing the job otherwise.

Making `migrate`/`config:cache` safe required one code change: `env()` was being
called outside `config/` (Laravel does not read `.env` once config is cached), so
`PAYMENT_TIMEOUT_SECONDS=60` would have silently become the hardcoded `300`.
Those four call sites now read `config('payments.timeout_seconds')`,
`config('payments.requery.*')` and `config('services.nibss.base_url')`, and both
commands keep working with caches warm.

### Hygiene / robustness (not blocking, but needed before CI/CD is real)
* `secretpassword` is hardcoded for Postgres in compose, and `db` publishes `5433`
  to the host ("debug escape hatch") — do not expose that on a public host.
* Unpinned bases: `composer:latest`, `nginx:alpine`, `php:8.3-fpm` →
  non-reproducible builds.
* `fixpay-pwa/Dockerfile` does `COPY ./dist`, so `docker compose up --build`
  cannot work from a clean clone (CI builds the PWA first and SCPs `dist`; a
  multi-stage `node:20-alpine` build would remove that coupling).
* Dev bind mounts plus a code-baked image = two sources of truth; that is what
  forces the `chown -R 33:33` step in the deploy.
* CI runs PHP tests and the PWA build but never `docker build`, never a PWA
  typecheck, and there is no post-deploy smoke test (`curl /up`) → **FIXED** in
  the CI/deploy job (see P3 and "Applied in this pass").

## Applied in this pass

* `fixpay-laravel/.dockerignore` — the image previously contained the local
  `.env` (119 lines: `APP_KEY`, DB, gateway and vendor credentials), plus
  `vendor/`, `tests/`, `scratch/`, stray `*.php` scripts and
  `storage/logs/*.log`. Backend build context **64 MB → 14.8 kB**; new image no
  longer contains `/var/www/.env` (verified with `docker run`).
* `fixpay-pwa/.dockerignore` — PWA build context **236 MB → 993 B** (no more
  uploading `node_modules` to the daemon on every build).
* Removed the obsolete `version:` key from both compose files (compose warned on
  every invocation; `docker compose config --quiet` is now clean).
* P1/P2/P3 above: container-reachable rail URLs, worker promoted into the base
  compose, explicit image tags, healthchecks with `service_healthy` gates, and
  the deploy lifecycle (migrate / cache / smoke test) — plus the `env()` →
  `config()` conversion those deploy steps depend on.
* `deploy-oci.yml` CI now validates both images with `docker build` and runs a
  non-blocking `tsc -p tsconfig.app.json --noEmit` (there is a pre-existing
  type-error baseline, so it is a signal, not a gate).

Stack re-verified after these changes: all five services `healthy`; `/up` `200`;
`:8082/` `200`; `:8082/api/auth/login` `422`; `:8000/api/payments/alternative/initiate`
`401`; a dispatched `DeliverWebhookJob` reached `DONE`; `php artisan test` →
**31 passed / 101 assertions**; `config:cache` + `route:cache` + `view:cache`
warm → same results, with `config('payments.timeout_seconds')` = `60` from `.env`.

## Still open (not covered by this pass)

1. **Server-side `.env`** — must exist at
   `/var/www/fixpay/fixpay-laravel/.env` with `APP_KEY`, DB creds and the rail
   credentials. Rendered by hand today; a GitHub-secret template or a
   secret-manager mount would remove the foot-gun.
2. **TMS / Payfixy endpoints on the server** — set
   `PAYFIXY_GATEWAY_BASE_URL_DOCKER`, `TMS_BASE_URL_DOCKER`,
   `TMS_ANTIFRAUD_URL_DOCKER` for that host (the `INGRESS.md` hostnames
   `gw.payfixy.test` / `aml.tms.test` / `antifraud.tms.test` are the intended
   values once the Traefik router runs there).
3. **Decide bind-mount vs. image-baked code in prod** — the bind mount keeps the
   `chown 33:33` step and the empty-`vendor/` risk; running the image would make
   releases immutable.
4. Hardening not done: pin base images (`composer:latest`, `nginx:alpine`,
   `php:8.3-fpm`), move `secretpassword` and the `5433` Postgres port out of the
   base compose, multi-stage PWA build (removes the `dist` dependency), and the
   optional nginx `resolver` for the stale-upstream-IP 502.



