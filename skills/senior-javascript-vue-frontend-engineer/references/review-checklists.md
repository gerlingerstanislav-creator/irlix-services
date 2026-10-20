# Code review, debugging and delivery checklist

## Review method

1. Determine intent and changed contract from issue/PR, prior behavior and tests; inspect diff + affected call sites.
2. Rank findings: **P0** exploitable data/security impact or broad production outage, **P1** high-impact functional loss/data corruption, **P2** meaningful regression/maintainability, **P3** localized polish. Calibrate to evidence; label uncertainty.
3. Every finding: file:line, trigger/reproduction, expected vs actual, impacted users, why this diff causes it, minimal repair, regression test. Avoid vague "might be better" items.
4. Review privacy/security: permissions, untrusted content, token storage, URL and OAuth callback, CSRF contract, dependencies, error/log output.
5. Review async/state: stale response, unmount cleanup, double writes, cache invalidation, draft preservation, watcher loops, keys, route history.
6. Review UX: empty/error/loading, keyboard, focus, responsive/long texts, consistent DS, visual screenshots if available. Don't claim unobserved pixel correctness.
7. Verify if backend changes are required by contract; don't implement server logic as frontend workaround without explicit ownership.

## Change-specific preflight

- Table: sort/filter/pagination changes, row identity, bulk selection semantics, large-data performance.
- Form: validations, dirty state, focus/errors, submission deduplication, server validation, cancellation.
- Auth: login callback, renewal, logout/cache, forbidden operation and API enforcement.
- Refactor: exact behavior invariants captured by tests, wide call-site search, minimal diff.
- Bugfix: reproduced failing behavior, test first when feasible, demonstrated resolution.
- Production: release permissions, exact scripts, rollback, asset cache, browser verification status.

## Evidence and report

Prefer concise findings to ungrounded praise. State test command, exit status, test counts when available; manual steps and viewport/browser. Separate proof of static checks from proof of interactive behavior. Use [assets/review-report.md](../assets/review-report.md).
