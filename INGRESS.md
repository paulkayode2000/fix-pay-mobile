# Single Ingress — hostname contract (no port confusion)

One router owns all host-facing HTTP. **No service URL ever contains a port.**

## Router
- Container `tms-router` (Traefik v3.3) on the shared `tms_default` network.
- Host port `:80` is the ONLY published ingress. `:8099` remains for legacy
  consumers; direct service ports (`:8080`, `:8083`, `:8999`, `:8000`, `:5433`)
  exist only as a debug escape hatch.
- Backends are reached by Docker DNS on `tms_default` (container ports, never
  host ports).

## Service map
| Hostname | Backend | System |
|---|---|---|
| `api.fixpay.test` | `fixpay-api-webserver:80` | fixpay-mobile Laravel API |
| `app.fixpay.test` | `fixpay-frontend:80` | fixpay PWA |
| `gw.payfixy.test` | `java-gateway-e2e:8080` | Payfixy Java gateway |
| `bff.payfixy.test` | `payfixy_gateway-bff-gateway-1:8999` | Deno BFF |
| `antifraud.tms.test` | `antifraud-api:8080` | TMS antifraud-service |
| `aml.tms.test` | `laravel-nginx:80` | TMS aml-system |

## Resolution (both sides, same URL)
- **Containers on `tms_default`** → Docker DNS network aliases on the router
  container (defined in `TMS/traefik/docker-compose.yml`).
- **Host machine** → `C:\Windows\System32\drivers\etc\hosts`:
  ```
  127.0.0.1  api.fixpay.test app.fixpay.test gw.payfixy.test bff.payfixy.test antifraud.tms.test aml.tms.test
  ```
  (run `add-hosts-entries.ps1` once in an **Administrator** PowerShell).

## Configuration
- Static: `TMS/traefik/traefik.yml` (entrypoints `web80: :80`, `web: :8099`).
- Routes: `TMS/traefik/dynamic.yml` (routers + services, file provider, hot-reload).
- Router compose: `TMS/traefik/docker-compose.yml` (aliases + `80:80`).
- Consumers: fixpay `.env` uses `PAYFIXY_GATEWAY_BASE_URL=http://gw.payfixy.test`,
  `TMS_ANTIFRAUD_URL=http://antifraud.tms.test`, `TMS_BASE_URL=http://aml.tms.test`.

## Adding a service
1. Start it on `tms_default` (or `docker network connect tms_default <name>`).
2. Add a router+service in `dynamic.yml`.
3. Add its FQDN as a `tms_default` alias on the router.
4. Add the hostname to the hosts file (host side only).

## Back-compat
Legacy `*.127.0.0.1.nip.io` routers remain registered on the `web` entrypoint
until all consumers migrate.
