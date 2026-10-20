# Independent scenario 2 — API, OAuth/OIDC and asynchronous errors

## Prompt to skill (hypothetical Vue SPA)

"Integrate Keycloak login with a protected records view and Fetch REST API. Several simultaneous requests may encounter expired sessions; handle 401/403/409, network failure and logout without leaking tokens."

## Simulated discovery

Assume Vue + Router + existing Keycloak JS adapter and API fetch wrapper; server remains authoritative. Check actual versions, client type, provider config, callback URLs, router startup order, token storage and error response schemas before coding. Do not install a second auth SDK or add a custom backend.

## Skill-guided outcome

- Browser OAuth client uses provider's Authorization Code + PKCE flow and safe callback validation; no bundled client secret, no localStorage/sessionStorage token persistence; consider existing BFF as preferable when present.
- Auth states `bootstrapping → anonymous/authenticated → renewing/expired`, appropriate route guard, no redirect loop. Keycloak initializes ahead of router if it mutates callback URL.
- API layer maps HTTP statuses and 204 correctly, catches network failure separately; handles `401` via **single-flight renewal** and at most one replay for safe operations; `403` shows denied without a refresh; `409` provides reconciliation rather than silent overwrite. No automatically repeated non-idempotent writes.
- Logout clears private in-memory cache and component visibility state; error log redacts secrets.
- Tests: concurrent 401 only one renewal, repeated 401 logs out / avoids loop; 403 no refresh, 409 doesn't overwrite draft, missing session/denied deep link, expired during mutation, cookie restriction fallback where applicable. Browser-supported Keycloak authentication still needs real provider test.

## Executed evidence

`node --test validation/behavior-models.test.mjs`: `case 2` implements single-flight renewal and tests 401 concurrency, 403 denial and repeated-401 termination. It **does not emulate a complete OAuth provider, JWT verification, Keycloak adapter or browser**. Those require integration/E2E and configuration review in the actual project.

## Assessment

API/auth architecture: pass at plan/model level; security review: correct protocol primitives and trust boundaries; concurrency: pass at model level; Vue integration, Keycloak flows, visual/UI E2E: not tested in this environment.
