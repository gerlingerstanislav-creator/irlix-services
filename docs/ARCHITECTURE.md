# Architecture

## Repository layout

```text
apps/
  portal/               # Dashboard / root service launcher at /
  web/                  # Employees frontend at /employees/
  design-system/        # standalone UI catalogue
packages/
  ui/                   # shared design tokens and Vue components
services/
  platform-core/        # shared platform capabilities
  employees/            # first business service
infra/
  postgres/init/        # shared PostgreSQL bootstrap with isolated schemas
  keycloak/             # realm import + idempotent bootstrap
.github/workflows/      # CI/CD
```

## Runtime

The current stand runs as one Docker Compose project while preserving service boundaries.

```text
User browser
   |
Host nginx :80
   |-- / --------------------> Dashboard (portal)
   |-- /employees/* ---------> Employees web
   |-- /design-system/* -----> Design System
   |-- /auth/* --------------> Keycloak realm `irlix`
   |-- /api/platform/* ------> Platform Core
   `-- /api/employees/* -----> Employees API

Dashboard ----- OIDC Authorization Code + PKCE ----┐
Employees web -- OIDC Authorization Code + PKCE ----+--> Keycloak
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
- `/auth/` — Keycloak;
- `/api/platform/` — Platform Core API;
- `/api/employees/` — Employees API.

`Dashboard` is not part of Employees. The Employees sidebar contains only Employees sections (`Сотрудники`, `Подразделения`). The global launcher opened from the IRLIX logo contains a link back to Dashboard plus available/future services.

## Data ownership

- `platform_core` schema → Platform Core, DB user `platform_core_app`;
- `employees` schema → Employees, DB user `employees_app`;
- Keycloak → corporate identities, credentials, realm roles and groups.

Employees stores `keycloak_user_id` and identity state only as technical mapping alongside its employee business data.

## Identity and authentication

Keycloak realm `irlix` is the Identity Provider. Login accepts either username or corporate email. Dashboard and Employees use OIDC Authorization Code flow with PKCE (`S256`) and require authentication before exposing their working UI.

Current mapping:

- employee `login` → Keycloak username;
- `<login>@irlix.ru` → Keycloak email;
- department `ldap_group` → Keycloak group;
- employee `keycloak_user_id` → stable identity reference.

Hire creates the Keycloak user with a temporary password and required password change. Dismissal disables the identity; rehire re-enables it. Department changes synchronize the mapped group.

Employees API requires a Bearer access token for all endpoints except `/api/health`. The token is validated against Keycloak userinfo, the authorized OIDC client is checked, and the current temporary authorization baseline requires realm-role `platform-admin`. This role is a bootstrap access boundary until the full permission + scope model is implemented.

`infra/keycloak/bootstrap.sh` ensures the web client and `platform-admin` realm role are present even when the realm already exists in the persistent Keycloak volume. Deploy can provision the temporary `admin` application user from GitHub secret `TEMP_ADMIN_PASSWORD`; the password is never stored in git.

The current stand uses Keycloak `start-dev` and persistent Keycloak volume. Before production it must move to production mode with PostgreSQL storage, and Employees provisioning must use a least-privilege service account.

## Infrastructure capacity

Current stand baseline is documented in `docs/STAND.md`: 4 vCPU, 4 GB RAM, 10 GB disk. Resource expansion is proposed only after identifying an actual bottleneck and explaining the required increase.

## CI/CD

GitHub Actions validates Docker Compose and builds the stack. Deployment reuses the server-side Docker cache and does **not** force-recreate unchanged containers. This avoids restarting PostgreSQL, Redis, RabbitMQ and Keycloak for frontend-only changes and reduces deploy time and service churn.

After deploy, CI runs database migrations, idempotent Keycloak bootstrap, verifies public health endpoints, OIDC discovery and frontends, and checks that protected Employees API endpoints reject anonymous requests.

The present pipeline is intentionally simple. If total CI time becomes materially inconvenient, the next optimization should be persistent BuildKit/GitHub Actions image-layer cache or publishing built images once and deploying those images, rather than weakening build/verify checks.

## Current iteration

Iteration 1 establishes Platform Core, Dashboard, Employees, Design System, Keycloak, PostgreSQL, Redis and RabbitMQ. Employees is the reference business service and currently includes employee registry, organizational structure, employee card/lifecycle/history, salary history, identity provisioning and authenticated OIDC access.
