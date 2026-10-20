# Authentication, authorization, Keycloak, OAuth2/OIDC/JWT

## Principal vs permission vs resource scope

Authentication proves who/client; authorization permits **specific action on specific object and fields**. RBAC role membership, ABAC contextual policies, OAuth scopes and resource/tenant ownership serve different purposes. JWT signature validity and bearer scope alone do not grant record access.

1. Verify OIDC discovery `/.well-known/openid-configuration`, expected issuer, signature with an allowlisted algorithm and JWKS key rotation, token type/use, `exp`, `nbf`, allowed `aud`, intended client (`azp` if protocol/config requires), identity, key ID handling and clock skew. Reject `alg=none`, unsupported algorithms, malformed or wrong-issuer/audience JWTs. Don't confuse ID tokens with API access tokens.
2. Follow Keycloak realm/client mapping semantics and actual provider settings; introspection may be needed for opaque/lightweight/revocable tokens. Local verification cannot guarantee instant revocation; define staleness and outage policy. Do not write crypto primitives or treat unsigned decoded JWT payloads as proof.
3. Prefer Authorization Code + PKCE for interactive clients, client credentials for service-to-service, short-lived access tokens; avoid resource-owner password flow and long-lived shared secrets. Secret storage and rotation belong to deployment config, never logs.
4. Each endpoint: authenticate → validate audience/scope → check functional permission → check object/tenant ownership → check field-level read/write privileges. Default deny and positive+negative tests.
5. `Policy`/`Gate` encodes resource action. Filter list endpoints by authorized scope **before pagination/aggregation**; route bindings must not bypass scope. `denyAsNotFound()` where hiding existence is chosen; never expose sensitive record before auth.
6. Validate arrays, nested fields and relation ownership; use allowlist mass assignment and resource serialization to prevent overposting/BOPLA. Check SSRF on supplied URLs, injection (including dynamic SQL identifiers), broken auth, rate limits, resource consumption, logging leakage and CORS/CSRF based on auth mode.
7. Permission changes: test stale token/role, revoked access, different tenants, impersonation/service accounts, admin bypass, confused-deputy, concurrent ownership transfers. Check no owner-only hidden special cases.

## Matrix template

| Principal | Action | Resource condition | Allowed fields | Expected denial |
|---|---|---|---|---|
| Authenticated authorized subject | read/update | belongs to permitted scope | explicit allowlist | n/a |
| Same role, wrong scope | read/update | foreign record | none | 403 or 404 (consistent) |
| Same scope, missing permission | read/update | own record | none | 403 |
| Unauthenticated | any | any | none | 401 |

## Threat-oriented tests

Cross-ID reads and updates; tenant ID forged in JSON; nested relation hijack; unauthorized filter/count queries; mass assignment (`is_admin`, `owner_id`, `status`); expired/bad `aud`/bad `iss`/rotated `kid`; path-ID vs payload-ID disagreement; token replay; scope not equal to object privilege; resource ownership changed between authorization and write.

Official details: Keycloak OIDC docs and OWASP API Top 10 in source index. Auth libraries and claims mapping are project/version specific; no hardcoded integration adapter should be introduced into a generic Skill.
