# Canonical internal stand origin

## Public browser origin

The internal IRLIX Services stand has one canonical browser origin:

`http://services.lan`

All user-facing applications are path-based under this origin. This includes Dashboard `/`, Employees `/employees/`, Vacations `/vacations/`, Clients `/clients/`, Timesheets `/timesheets/`, Specialists `/specialists/`, Recruitment `/recruitment/`, CV `/cv-converter/`, Equipment `/equipment/` and Design System `/design-system/`.

The application origin is intentionally independent from the server IP and from the SSH address used by CI/CD. Users must open the platform through `services.lan` both from corporate Wi-Fi and through VPN.

## Authentication

Keycloak is exposed through the same origin at `/keycloak/auth/`.

Canonical values on the stand are:

- `IRLIX_PUBLIC_URL=http://services.lan`
- `KEYCLOAK_PUBLIC_URL=http://services.lan/keycloak/auth`
- `KEYCLOAK_ISSUER=http://services.lan/keycloak/auth/realms/irlix`
- OIDC redirect URI: `http://services.lan/*`
- OIDC web origin: `http://services.lan`

Browser OIDC traffic, API requests and frontend navigation therefore stay on the same host. Backend services validate JWT `iss` against the same canonical issuer while fetching JWKS through the internal Docker Keycloak endpoint.

## Reverse proxy

Host nginx owns port 80 and has `server_name services.lan`. All frontend, API and Keycloak routes are proxied from this virtual host to loopback-only Docker ports. For Keycloak nginx forwards `Host`, `X-Forwarded-Host`, `X-Forwarded-Proto`, `X-Real-IP` and `X-Forwarded-For`.

## Deployment invariant

`scripts/deploy-from-ghcr.sh` persists the canonical origin to the server `.env`. The server IP or GitHub deployment SSH target must never be reused as the browser application origin.

When adding a new authenticated service, its browser frontend must use relative/same-origin routes and its backend must validate the canonical `KEYCLOAK_ISSUER`. A new service must not introduce its own public hostname without an explicit architecture decision.
