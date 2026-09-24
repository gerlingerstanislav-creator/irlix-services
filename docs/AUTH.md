# Authentication and identity

## Current model

- Identity Provider: Keycloak 26, realm `irlix`.
- Public OIDC client: `irlix-services-web`.
- Browser auth is centralized in `packages/auth` and shared by Dashboard and Employees.
- Current internal HTTP/VPN stand uses Authorization Code without PKCE because modern `keycloak-js` requires a secure browser context even when PKCE is disabled.
- When HTTPS is introduced, the same shared auth client automatically enables PKCE `S256`.
- Login accepts username or corporate email.
- Dashboard and Employees require login before exposing their working UI.
- Employees API requires a Bearer token on all endpoints except `/api/employees/health`.
- Backend validates the token through Keycloak `userinfo` and attaches the resulting identity to the request.
- Browser auth preserves the originally requested application URL and restores it after the OIDC callback.

## Employee identity lifecycle

Employees owns employee business data; Keycloak owns credentials and authentication identity.

- login: `firstname.lastname` selected/confirmed by HR;
- work email: `<login>@irlix.ru`;
- hire: create/enable Keycloak user, issue temporary password, require password change;
- dismissal: disable Keycloak user;
- rehire: re-enable the same Keycloak identity;
- department change: synchronize the mapped Keycloak group;
- `keycloak_user_id` is stored in Employees as the stable technical mapping.

## Administrators

Two administrator concepts are intentionally separate:

- `admin` in realm `irlix` is the temporary **application** administrator used to enter IRLIX Services. Its password comes from GitHub Actions secret `TEMP_ADMIN_PASSWORD` and it receives realm role `platform-admin`.
- `keycloak-admin` in realm `master` is the dedicated **Keycloak Admin Console** administrator. Its password comes from GitHub Actions secret `KEYCLOAK_ADMIN_PWD` and it receives the `realm-management / realm-admin` client role.

The technical Keycloak bootstrap administrator is not used as the day-to-day console account.

`infra/keycloak/bootstrap.sh` idempotently ensures the OIDC client, application role, temporary application admin and (when the relevant secret is supplied) the dedicated console admin.

The dedicated console admin can be reprovisioned/rotated manually through the `Provision Keycloak Admin` GitHub Actions workflow. Password values are never committed to git.

Admin Console URL on the stand: `/auth/admin/`.

## Next security steps

Before production:

- run Keycloak in production mode with PostgreSQL persistence;
- replace bootstrap-admin use from Employees provisioning with a least-privilege service account;
- validate JWT locally via realm JWKS instead of calling `userinfo` on every request;
- implement application permissions/scopes on top of authenticated identity;
- enable HTTPS and require PKCE `S256` for browser authorization;
- protect all non-public platform frontends consistently.
