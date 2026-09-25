# Authentication and identity

## Current model

- Identity Provider: Keycloak 26, realm `irlix`.
- Public OIDC client: `irlix-services-web`.
- Public Keycloak base path on the stand: `/keycloak/auth/`.
- Browser auth is centralized in `packages/auth` and shared by Dashboard and Employees.
- Current internal HTTP/VPN stand uses Authorization Code without PKCE because PKCE `S256` requires a secure browser context.
- When HTTPS is introduced, the same shared auth client automatically enables PKCE `S256`.
- Login accepts username or corporate email.
- Dashboard and Employees require login before exposing their working UI.
- Employees API requires a Bearer token on all endpoints except `/api/employees/health`.
- Employees validates Keycloak access tokens locally using realm JWKS, issuer/client/time checks and then resolves application permissions/scopes from Employees data.
- Browser auth preserves the originally requested application URL and restores it after the OIDC callback.

## SSO behavior

The platform uses Keycloak SSO. A user authenticates once in realm `irlix`; Dashboard, Employees and future platform services use the same Keycloak browser session. Each frontend still performs its own OIDC Authorization Code exchange and keeps its own application tokens, but Keycloak can complete later authorization requests without asking the user for credentials again while the realm session is active.

A technical platform reset route is available at `/auth/logout`. It clears local IRLIX browser-auth state for the platform applications, terminates the realm `irlix` Keycloak SSO session through the OIDC end-session endpoint and returns to `/`. It is intended for testing and explicit sign-out. It does not sign out the separate `keycloak-admin` session in the `master` realm.

## Access contours

Keycloak and the IRLIX platform are intentionally separate access contours and must not be merged into one reverse-proxy authentication boundary.

- **Keycloak contour** lives under `/keycloak/auth/*`. The Admin Console uses the dedicated `keycloak-admin` identity in the `master` realm. Administrative access policy can be restricted independently from business applications.
- **Platform contour** covers `/`, `/employees/` and future business-service routes. These applications authenticate against realm `irlix` and share the platform browser-auth layer.
- Public OIDC endpoints required by platform applications — realm discovery, authorization, token, logout and related protocol endpoints under `/keycloak/auth/realms/*` — must remain reachable by the browser. Platform authentication must not be placed in front of the whole `/keycloak/auth/*` prefix, otherwise the Identity Provider would be hidden behind the authentication mechanism that depends on it.
- `keycloak-admin` and platform users are separate identity concepts. Keycloak console administration does not imply platform application access, and platform administration does not imply Keycloak console access.

## Browser transaction handling

`packages/auth` stores pending OIDC transactions by `state` instead of keeping only one mutable transaction record. Each transaction stores `state`, exact callback URI, original return URL, start time and PKCE verifier when HTTPS is available. This prevents a second authorization attempt from overwriting the transaction needed by a callback already in flight. Expired transactions are removed automatically.

A callback is accepted only when its returned `state` matches a pending transaction. If a stale callback arrives after an older browser build or lost transaction, the auth layer performs one bounded clean recovery: callback parameters are removed, stale transactions are cleared and a fresh SSO authorization is started. Repeated recovery is blocked so the browser cannot enter an infinite redirect loop.

The shared browser-auth layer obtains `issuer`, authorization endpoint, token endpoint and logout endpoint from OIDC discovery instead of manually constructing those URLs. This keeps browser-side validation consistent with the actual Keycloak reverse-proxy address.

An API `401` must not automatically start another login redirect when the browser already holds a token. Otherwise a backend token rejection can produce an infinite Keycloak SSO callback loop. Employees therefore surfaces the API rejection to the user instead of repeatedly redirecting. A fresh login remains an explicit user/session action.

## Employee identity lifecycle

Employees owns employee business data; Keycloak owns credentials and authentication identity.

- login: `firstname.lastname` selected/confirmed during employee creation;
- work email: `<login>@irlix.ru`;
- hire: create/enable Keycloak identity and bind it through `keycloak_user_id`;
- onboarding: Keycloak sends a one-time `UPDATE_PASSWORD` action link to `personal_email`; the employee sets the password personally, and administrators do not need to know or transmit it;
- onboarding delivery can be retried by an administrator from the employee card;
- dismissal: disable Keycloak user;
- rehire: re-enable the same Keycloak identity;
- department change: synchronize the mapped Keycloak group;
- hard delete: a full administrator can remove a mistakenly created employee; Keycloak identity is deleted first and local business data is removed only after the identity deletion succeeds;
- `keycloak_user_id` is stored in Employees as the stable technical mapping.

SMTP delivery for onboarding is configured in Keycloak. On the current stand Yandex 360 SMTP credentials are injected from GitHub Secrets and are never committed to the repository.

## Employees authorization

Authentication and application authorization are separate layers. Keycloak answers **who the user is**; Employees answers **what the user may read/change and within which organizational scope**.

Current first-iteration access rules:

- department manager: reads employees and salaries for the managed department and its complete descendant subtree;
- Finance subtree: reads all employees and all salaries;
- HR subtree: reads all employees but receives no salary history;
- ordinary employee: no access to Employees;
- `company-admin`: explicit employee-level assignment with full Employees access independent of department/position;
- `platform-admin`: technical bootstrap/emergency role with full access.

`company-admin` is stored in Employees (`employee_access_roles`) and is not inferred from organizational position. A user with `access.manage` can assign or revoke `company-admin` directly from the employee card. The backend protects the access-management endpoints; hiding UI controls is not the security boundary.

The initial authorization model intentionally keeps HR, Finance and managers read-only. Mutation permissions can be granted independently later instead of being implied by read scope.

## Administrators

Administrator concepts are intentionally separate:

- `company-admin` is the normal business/platform administrator assigned to a concrete employee. It grants full Employees permissions and can be managed from Employees by another full administrator.
- `admin` in realm `irlix` is the technical **application bootstrap administrator** used to recover/manage IRLIX Services before normal administrators exist. Its password comes from GitHub Actions secret `TEMP_ADMIN_PASSWORD` and it receives realm role `platform-admin`.
- `keycloak-admin` in realm `master` is the dedicated **Keycloak Admin Console** administrator. Its password comes from GitHub Actions secret `KEYCLOAK_ADMIN_PWD`. Bootstrap assigns the master `admin` realm role (when present) and the `realm-management / realm-admin` client role.

The technical bootstrap administrator is not intended as the normal day-to-day company administrator. Once real employees receive `company-admin`, `platform-admin` remains an emergency/bootstrap path.

`infra/keycloak/bootstrap.sh` idempotently ensures the OIDC client, application role, temporary application admin, SMTP realm configuration and (when the relevant secret is supplied) the dedicated console admin.

The dedicated console admin can be reprovisioned/rotated manually through the `Provision Keycloak Admin` GitHub Actions workflow. Password values are never committed to git.

Admin Console URL on the stand: `/keycloak/auth/admin/`.

The previous public `/auth/` route is intentionally not used for Keycloak anymore. `/auth/logout` belongs to the platform and is the only currently defined `/auth/*` utility route; the Keycloak infrastructure remains under `/keycloak/auth/*`.

## Frontend delivery and auth bootstrap

Dashboard is a Vite application. Its container must serve generated `/assets/*` files as well as `index.html`; otherwise the static loading shell can render while the authorization JavaScript never starts. Stand verification explicitly checks that the Dashboard JavaScript bundle referenced by the deployed HTML returns HTTP 200.

The Dashboard authorization overlay must be removed explicitly after successful initialization; its CSS must not leave the full-screen loading shell visible over an already rendered Dashboard.

## CI/deploy interaction

Change detection is based on the latest successful CI/deploy SHA, not only the immediate previous commit. This is required because `cancel-in-progress` can cancel intermediate pipelines during a multi-commit iteration. The final pipeline must deploy the accumulated diff so a cancelled earlier run cannot leave shared packages or dependent services on an older build.

## Next security steps

Before production:

- run Keycloak in production mode with PostgreSQL persistence;
- replace bootstrap-admin use from Employees provisioning with a least-privilege service account;
- enable HTTPS and require PKCE `S256` for browser authorization;
- protect all non-public platform frontends consistently;
- define a separate network/access policy for the Keycloak Admin Console without blocking OIDC protocol endpoints required by platform applications;
- add audit events for access-role changes and other sensitive mutations.
