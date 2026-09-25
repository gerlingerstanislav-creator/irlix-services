# Infrastructure

Infrastructure required by the local environment and stand lives here. The current PostgreSQL bootstrap creates isolated service roles/schemas. Runtime orchestration is defined in the repository root `docker-compose.yml`.

## Keycloak URLs

Keycloak has two different addresses by design:

- internal service-to-service address: `http://keycloak:8080/keycloak/auth`;
- public browser-facing address: `KEYCLOAK_PUBLIC_URL` (stand default: `http://192.168.90.100/keycloak/auth`).

`KC_HOSTNAME` must always use the public address. Keycloak uses it for OIDC metadata and for user-facing links such as password setup/reset links sent by email. Internal services may continue to call the Docker-network address directly.
