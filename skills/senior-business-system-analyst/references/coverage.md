# Scenario coverage matrix

For each requirement mark Applicable / Not applicable (with reason) / Covered with linked AC / Unknown. Prioritize by risk, not exhaustive boilerplate.

- Inputs: empty, null, absent, whitespace, case, encodings, min/max and just beyond, invalid formats, localization, large payloads.
- Workflow: happy path, abort, timeout, error, partial success, repeat, restart, re-entry, out-of-order messages, concurrent edits, stale client, undo, history.
- Access: anonymous/authenticated, role, tenant, resource owner, delegated/substitute roles, cross-tenant object IDs, direct URL/API access, field-level visibility, bulk operations, expired rights, least privilege, deny-by-default.
- Data: unique IDs, referential integrity, duplicates, deletion and archive, nullability, transaction boundaries, migration mapping, historical immutability, import/export, retention, audit.
- Integrations: schema and version compatibility, authentication, pagination, rate limit, retry/backoff, idempotency key, webhook duplicate/out-of-order, partial outage, timeouts, reconciliation.
- Time: timezone/DST, clock boundaries, business calendar, dates across month/year, race around deadlines.
- Non-functional: capacity, response time metric under specified load, accessibility, availability, recoverability, telemetry, privacy, localization.
- UI: loading/empty/error/success, keyboard, focus, responsiveness, destructive confirmation, optimistic updates, consistency with backend.
- Operations: feature flags, staged rollout, rollback/data reversibility, alerts, support guidance, existing clients/contracts.
