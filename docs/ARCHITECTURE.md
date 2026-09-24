# Architecture

## Repository layout

```text
apps/
  portal/               # Dashboard / root service launcher at /
  web/                  # Employees frontend at /employees/
  design-system/        # standalone UI catalogue
packages/
  ui/                   # shared design tokens and Vue components
  auth/                 # shared browser OIDC client
services/
  platform-core/        # shared platform capabilities
  employees/            # first business service
infra/
  postgres/init/        # shared PostgreSQL bootstrap with isolated schemas
  keycloak/             # realm import + idempotent bootstrap + login theme
.github/workflows/      # CI/CD
```

## Runtime

The current stand runs as one Docker Compose project while preserving service boundaries.

```text
User browser
   |
Host nginx :80
   |-- / --------------------------> Dashboard (portal)
   |-- /employees/* ---------------> Employees web
   |-- /design-system/* -----------> Design System
   |-- /keycloak/auth/* -----------> Keycloak realm `irlix`
   |-- /api/platform/* ------------> Platform Core
   `-- /api/employees/* -----------> Employees API

Dashboard ----- OIDC Authorization Code ----------┐
Employees web -- OIDC Authorization Code ----------+--> Keycloak
Employees API -- Bearer validation / realm role ----┘
Employees API -- identity provisioning -------------> Keycloak Admin API

Platform Core ----┐
Employees ---------+--> PostgreSQL
                   +--> Redis
                   `--> RabbitMQ
```

Backend services are independently buildable containers and must not read another service's tables directly.

## Routing and frontend ownership

- `/` — **Dashboard**, platform-level service launcher and health overview;
- `/employees/` — Employees business frontend;
- `/design-system/` — standalone design-system catalogue;
- `/keycloak/auth/` — Keycloak Identity Provider and Admin Console;
- `/api/platform/` — Platform Core API;
- `/api/employees/` — Employees API.

The former public `/auth/` Keycloak prefix is not used anymore. Identity-provider infrastructure is intentionally grouped below `/keycloak/auth/` to avoid confusion with application-level auth code and future platform routes.

`Dashboard` is not part of Employees. The Employees sidebar contains only Employees sections (`Сотрудники`, `Подразделения`). The global launcher opened from the IRLIX logo contains a link back to Dashboard plus available/future services.

Stable frontend route scopes are part of the architecture. They must not be coupled to backend filesystem paths so that manifests/service workers can be introduced later without moving business routes.

## Data ownership

- `platform_core` schema → Platform Core, DB user `platform_core_app`;
- `employees` schema → Employees, DB user `employees_app`;
- Keycloak → corporate identities, credentials, realm roles and groups.

Employees stores `keycloak_user_id` and identity state only as technical mapping alongside its employee business data.

## Identity and authentication

Keycloak realm `irlix` is the Identity Provider. Login accepts either username or corporate email. Dashboard and Employees require authentication before exposing their working UI.

Browser authentication is centralized in `packages/auth`. Dashboard and Employees use the same OIDC Authorization Code implementation, callback transaction handling, token refresh and logout behavior. The shared transaction persists the exact callback URL and original application URL and validates returned `state` before exchanging the authorization code.

The internal stand is intentionally HTTP-only while it is reachable only through the corporate VPN. It temporarily uses Authorization Code without PKCE. When the platform becomes reachable outside the corporate VPN, HTTPS is a prerequisite; the shared auth client then automatically adds PKCE `S256`.

A backend API `401` does not automatically trigger another browser login if the frontend already has a token. This prevents an invalid/rejected token from producing an infinite Keycloak SSO callback loop. The frontend displays the API rejection and lets the user explicitly re-authenticate.

The auth client returns the browser to the URL from which authentication was initiated. Frontends must not replace this with a hard-coded service home URL during login, so a direct request to `/employees/` returns to Employees and a request to `/` returns to Dashboard after a successful callback.

Keycloak uses the shared `infra/keycloak/themes/irlix` login theme. The login screen is Russian, uses the platform SVG logo and the same compact white/turquoise visual language as the rest of IRLIX Services. This theme is platform infrastructure and is not owned by Employees.

Current mapping:

- employee `login` → Keycloak username;
- `<login>@irlix.ru` → Keycloak email;
- department `ldap_group` → Keycloak group;
- employee `keycloak_user_id` → stable identity reference.

Hire creates the Keycloak user with a temporary password and required password change. Dismissal disables the identity; rehire re-enables it. Department changes synchronize the mapped group.

Employees API requires a Bearer access token for all endpoints except `/api/health`. The token is validated against Keycloak userinfo, the authorized OIDC client is checked, and the current temporary authorization baseline requires realm-role `platform-admin`. This role is a bootstrap access boundary until the full permission + scope model is implemented.

`infra/keycloak/bootstrap.sh` ensures the web client and `platform-admin` realm role are present even when the realm already exists in the persistent Keycloak volume. Deploy provisions or updates the temporary `admin` application user from GitHub secret `TEMP_ADMIN_PASSWORD`; the password is never stored in git. Bootstrap explicitly re-enables the account, completes the technical profile (`IRLIX Admin`), clears required actions, refreshes the password credential and verifies the `platform-admin` mapping.

The current stand uses Keycloak `start-dev` and persistent Keycloak volume. Before production it must move to production mode with PostgreSQL storage, and Employees provisioning must use a least-privilege service account.

## PWA readiness

PWA functionality is **not enabled on the current HTTP/VPN-only stand**. The architecture is prepared so it can be added later without rewriting business services:

- frontend applications keep stable URL scopes and do not depend on server-side browser sessions;
- all business data access goes through versionable HTTP APIs under `/api/*`;
- authentication is isolated from business components and uses OIDC tokens;
- shared UI lives in `packages/ui`, so future install/update/offline-state components can be reused;
- backend services remain unaware of service workers or installation state;
- application assets are produced by frontend builds and can later be precached without changing backend contracts;
- favicon/branding assets already use the platform SVG logo and can be reused as the source for future PWA icons.

When external access is introduced, the PWA phase should add HTTPS first, then a web app manifest, generated icon sizes, service worker/update strategy and explicit cache policy. Dynamic HR/business API responses should not be cached implicitly; offline behavior must be designed per use case rather than enabled globally.

## Infrastructure capacity

Current stand baseline is documented in `docs/STAND.md`: 4 vCPU, 4 GB RAM, 10 GB disk. Resource expansion is proposed only after identifying an actual bottleneck and explaining the required increase.

## CI/CD

GitHub Actions uses path-aware jobs: change detection, frontend build, backend build, infrastructure validation, affected-service deploy, authentication bootstrap and stand verification. Frontend and backend builds can run in parallel. Documentation-only commits do not run the main CI workflow.

Deployment reuses the server-side Docker cache and does **not** force-recreate unchanged containers. Authentication bootstrap runs only when auth/infra changes require it.

After deploy, CI verifies frontend assets, public health endpoints, OIDC discovery under `/keycloak/auth/`, the real authorization endpoint for `irlix-services-web`, and anonymous rejection on protected Employees API endpoints.

## Current iteration

Iteration 1 establishes Platform Core, Dashboard, Employees, Design System, Keycloak, PostgreSQL, Redis and RabbitMQ. Employees is the reference business service and currently includes employee registry, organizational structure, employee card/lifecycle/history, salary history, identity provisioning and authenticated OIDC access.
