# Authentication and identity

## Current model

- Identity Provider: Keycloak 26, realm `irlix`.
- Public OIDC client: `irlix-services-web`.
- Browser flow: Authorization Code + PKCE (`S256`).
- Login accepts username or corporate email.
- Employees frontend at `/employees/` requires login before mounting the Vue application.
- Employees API requires a Bearer token on all endpoints except `/api/employees/health`.
- Backend validates the token through Keycloak `userinfo` and attaches the resulting identity to the request.

## Employee identity lifecycle

Employees owns employee business data; Keycloak owns credentials and authentication identity.

- login: `firstname.lastname` selected/confirmed by HR;
- work email: `<login>@irlix.ru`;
- hire: create/enable Keycloak user, issue temporary password, require password change;
- dismissal: disable Keycloak user;
- rehire: re-enable the same Keycloak identity;
- department change: synchronize the mapped Keycloak group;
- `keycloak_user_id` is stored in Employees as the stable technical mapping.

## Bootstrap

`infra/keycloak/bootstrap.sh` idempotently ensures the OIDC client and `platform-admin` realm role exist. If runtime variable `IRLIX_TEMP_ADMIN_PASSWORD` is supplied, it also creates/updates temporary user `admin`, assigns `platform-admin`, and sets that password.

Passwords are not committed. The stand value must be supplied through the GitHub Actions secret `IRLIX_TEMP_ADMIN_PASSWORD`.

## Next security steps

Before production:

- run Keycloak in production mode with PostgreSQL persistence;
- replace bootstrap-admin use from Employees provisioning with a least-privilege service account;
- validate JWT locally via realm JWKS instead of calling `userinfo` on every request;
- implement application permissions/scopes on top of authenticated identity;
- protect all non-public platform frontends consistently.