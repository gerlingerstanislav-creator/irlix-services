# 04 — Access control and security-oriented verification

Do only authorized, low-impact checks. This is QA assurance, not permission to attack a system. No brute-force, account enumeration campaigns, data extraction or production fuzzing. Use dedicated test accounts and synthetic objects. Preserve confidential artifacts via redaction.

## Identify the policy shape
- Subjects: anonymous, user, admin, team lead, temporary delegate, suspended/revoked account, service accounts.
- Resources: entity, file, report, aggregate, nested object, bulk operations, exported data, queued job.
- Relationship: creator, owner, member, approver, specific team/tenant, object-specific sharing or temporary delegation.
- Action: list, get, create, update, delete, approve/reject, transition, export, bulk edit, impersonate, download and indirect effects.
- Context: object state, tenant, feature flag, time/delegation expiration, token age, approval version, security policy.

## Build a constrained access matrix
For every high-value tuple `subject × action × resource × relationship × state`, derive `allow/deny/unknown` from approved policy. Add positive controls: an allowed request in same environment must succeed for the denial test to be meaningful. A denial must prevent **all** side effects, not just return a status.

| Actor | Resource relationship | State | Operation | Expected | Probe (safe) |
|---|---|---|---|---|---|
| owner | own, active | draft | edit | allow if policy says so | normal UI and direct API |
| non-owner | foreign tenant | draft | read/edit | deny | change synthetic ID, confirm no data leak |
| former delegate | previously shared | submitted | approve | deny after revocation | re-use existing test session/token |
| approver | in-scope | approved | re-approve | deny or idempotent no-op per spec | re-submit same transition |

Unknown policy values need clarification; do not label an observed deny as a bug merely because one expected allow.

## Test surfaces
1. Server-side enforcement: directly invoke authorized test endpoint for a denied operation; do not rely on menu visibility. Verify row/tenant predicates and downstream mutations.
2. Vertical privilege escalation: lower role to privileged action; horizontal: user A to user B; tenant boundary: team A to B; nested resource mismatches: `/projects/A/tasks/B` where B belongs elsewhere.
3. List, search, report, export and count endpoints, not just details. Check unauthorized data in cache, events, metadata, error messages and attachment URLs.
4. Delegation and lifecycle: grant/revoke, expired grant, concurrent revoke, stale tab, stale cached role, token refresh/logout, deleted or reassigned object.
5. TOCTOU: permission valid during read but invalid before write; version/concurrency and server policy rechecked at point of mutation. Audit actor vs effective identity.
6. Mass assignment: immutable/privileged fields changed via extra payload attributes (in authorized test environment), object ownership chosen by client, insecure default values.
7. Error hygiene: no unauthorized existence disclosure or sensitive response fields; don't assert 403 vs 404 without an approved contract.

## Reference basis
Use OWASP WSTG authorization guidance and OWASP ASVS access-control requirements as taxonomies, adapting to the specific threat model. An OWASP category alone is not evidence of a vulnerability. See `references/07-methodology-sources.md`.

## Valid security finding needs
- Exact affected entrypoint and version, authorized test identity/scope, synthetic object ID, minimal steps, actual response and persisted downstream effect, expected policy source, safe evidence (redacted), severity reasoning.
- If only code review indicates a possible defect, label **potential/needs runtime verification**, not confirmed breach.
- If a test would require production or unauthorized access, stop that test and specify a safe substitute.
