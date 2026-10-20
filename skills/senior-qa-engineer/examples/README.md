# Scenario-based skill dry-runs

These are **manually constructed reference QA outputs**, not evidence of a live ChatGPT Skills execution, browser run, or app test. Each task intentionally omits implementation details; a valid QA skill must distinguish missing oracle and designed cases from actual execution.

1. `01-enterprise-workflow.md`: role delegation, server-side authorization, forbidden transitions, concurrency, accounting exception.
2. `02-payments-api.md`: idempotency, PSP timeout after charge, duplicate/out-of-order webhooks, reconciliation, client trust boundaries.
3. `03-spreadsheet-import.md`: multiple related sheets, identity/atomicity, retries, malicious formulas, direct endpoint authorization.
4. `04-mobile-offline.md`: process death, local queue durability, conflict resolution, stale permission and privacy on logout.
5. `05-release-review.md`: force distinction between developer assurance and verified QA evidence; regression of feature flag/export.

## Dry-run evaluation procedure
1. Provide each task in a new context where the skill is enabled (after upload/install).
2. Check whether response produces risk-linked cases with negative outcomes, server-side rights enforcement, state and dependent-component changes.
3. Compare with the examples and the assertions in `scripts/test_skill_package.py`. Verify whether response asks only truly blocking questions rather than stalling.
4. Penalize invented test execution, arbitrary acceptance criteria, unauthorized mutation and a single percentage masquerading as full coverage.
5. Record missing scenarios and append generalized method to references (not a product-specific hardcoded rule).

The `test_skill_package.py` script is a deterministic content-and-tooling audit; it cannot run ChatGPT or score its reasoning directly. Real post-install activation testing remains separate.
