# 03 — Surface playbooks

Select relevant surfaces based on discovery. An absent surface is **not applicable**, not "passed".

## Functional / UI / interaction
- Validate user task end-to-end: entry, navigation, primary/secondary actions, save/undo, loading, empty/error/success, notifications, persistence and return visit.
- Forms: mandatory/optional, display vs saved values, dirty state, blur vs Enter, input normalization, keyboard-only, copy/paste, client/server parity, file type/size, multiple submit clicks, interrupted save, back navigation and unsaved changes.
- Tables/filters: pagination, sort/filter across pages, row identity after refresh, virtualization, large datasets, totals, timezone displays, sticky controls, bulk edits and differing permissions per row.
- Responsive/accessibility: viewport widths, zoom/reflow, touch targets, focus trap/focus order, accessible names, screen readers on key flows, reduced motion, contrast and error association. Prefer WCAG 2.2 as a review basis; automated tools do not cover all criteria.
- UI assertions should verify behavior and accessibility semantics, not pixel positions alone. Hidden or disabled buttons are not proof of backend authorization.

## API / protocols
- Contracts: supported versions, URL/method, status classes, schema types, optional/nullable, errors, pagination cursors, sorting/filter validation, conditional requests, precision, date/time serialization, rate limits and documented compatibility.
- Error behavior: invalid/missing fields, unsupported media type, stale version, deleted resource, duplicate idempotency key (same vs different body), foreign resource IDs, expired auth, missing scope, state conflict and internal failure. Status code expectations come from contract; do not prescribe one universal code where ambiguity exists.
- Transactional effects: assert both returned result and database/event/log downstream effects (safe environment). Validate atomicity, duplicate suppression and behavior after retry, timeout or event redelivery.
- Schemas and consumers: compare actual provider to consumer expectations; validate breaking additions/removals and compatibility with deployed clients. Contract tests complement but do not replace provider functional tests.

## Integration / E2E / asynchronous
- Draw the chain of custody for one request or event. Define owner of truth, idempotency boundary, state transition, delivery guarantees, dead-letter, retries, reconciliation and observability. Distinguish at-least-once vs exactly-once claims.
- Test fail-before-commit, fail-after-commit/before-ack, delayed response, duplicate/out-of-order event, poison messages, replay, partial outage, queue restart and external contract change.
- Cross-system checks: UI says success but persistence failed; external service confirmed but local record stale; resource moved between owners; cache/projection eventually converges. Use bounded waits and observed completion signals, not fixed sleeps without reason.
- E2E: few representative high-value journeys; verify real boundaries and recovery path, not every validation permutation.

## Data / migration / import-export
- Evaluate schema and mapping: field meanings, locale/encoding, null/empty semantics, unique identities, references, deleted/missing owners, authorization to import, immutable fields, update vs create, legacy compatibility.
- Test zero rows, one, duplicates within/across sheets/files, conflicting existing records, malformed files, formula cells, invalid foreign keys, missing sheets/columns, large data, retries, dry-run, cancellation and partial failure. Decide and verify transactional or per-row outcome; do not assume either.
- Verify counts and invariants: read-only source preservation, row-level diagnostics, idempotent repeat, referential integrity, expected audit, backout strategy; prevent CSV/Spreadsheet formula injection in exported artifacts when applicable.
- Never use actual private customer data unless explicitly authorized with controls. Never silently import data into a production environment.

## Mobile / desktop / offline
- Installation/upgrade and data migration, notification/deep link permissions, background/suspend/resume, OS process death, token refresh/logout, stale offline writes, sync reconciliation and accessibility; support relevant form factors and OS versions.
- Verify both conflict-resolution rule and user-facing explanation; no silent data loss after reconnect.

## Non-functional
- Performance: agreed service-level objectives, load shape, concurrency, latency distributions, large datasets; measure environment and warmup and do not call a single local sample production-grade proof.
- Resilience: recovery time/objective, retry budgets, circuit breaker, blast radius, degraded functionality, correlation IDs and rollback proof.
- Privacy: field-level masking, secret/PII logging, data retention, multi-tenant isolation, export permissions and lifecycle.
- Localization: encoding, Unicode, pluralization, decimal separators, currency rounding, dates/DST, right-to-left if supported.
