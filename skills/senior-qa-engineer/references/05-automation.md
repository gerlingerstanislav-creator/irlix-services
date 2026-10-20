# 05 — Automation engineering, CI and test reliability

## Choose project-native tools
Read existing framework/build scripts/test conventions first. Follow project patterns and lockfiles. Do not create a new testing stack when existing test infrastructure suffices. If none exists, compare candidate levels/tools based on execution target, language, maintenance, CI constraints and needed browser/device/protocol capabilities; request permission before adding packages.

## Automation decision
Automate stable, important, frequently exercised and deterministic behaviors first. Prefer fast component or integration tests to cover complex domain permutations; use API tests for authorization, contracts and persistence; reserve E2E for cross-component proof. Prefer explicit coverage of rare high-impact failures over shallow happy-path quantity. When a scenario requires human perception (e.g., visual quality), schedule a documented manual review with evidence instead of asserting automated coverage.

## Test structure
- Arrange (isolated fixture & required state); Act (single meaningful trigger); Assert (observable output and side effects); Cleanup (idempotent and scoped).
- Stable selectors/IDs or accessible semantics; avoid relying on CSS coordinates, random labels or sleeps. Use bounded polling for eventually consistent behavior.
- Ensure repeatability: control time/timezone/locale, deterministic random seed, dedicated unique test objects, isolated identities/tenants, reset external dependency state, avoid shared mutable fixtures.
- Test denied actions at protocol/backend layer; assert no mutation (DB, events, files) when possible.
- Never mock the very integration you're claiming to verify. Use contract tests against provider implementation or narrow integration with real test dependency as appropriate.
- Don't turn failures green with broad catch blocks, retries hiding deterministic errors, vague assertions or excessive snapshot updates.

## Test suite stratification
1. Fast pre-commit/unit for deterministic domain invariants and boundary partitions.
2. Per-merge targeted integration/API/contract; stable service dependencies.
3. Smoke E2E on controlled deployed test environment and a small number of critical journeys.
4. Periodic broad regression, cross-browser/device, a11y, non-functional and authorized security checks, suited to risk.
5. Flaky test quarantine requires recorded reason/owner/deadline and coverage substitution; never quietly delete a critical test.

## Review checks
- Does every test have a behavioral reason and traceability to requirement/risk?
- Is an assertion falsifiable? Would the test fail if the relevant condition were inverted? Consider mutation testing where practical.
- Does rerunning in parallel pass? Does cleanup touch only its own data? Does the test use authorized/test endpoints?
- Are network/error cases deterministic using controlled stubs or a real test integration where contract fidelity matters?
- Are transient failures reproducible with logs, correlation IDs and build revision?
- Are tests compatible with project's CI time budget, secrets handling and environment requirements?

## Evidence from automation
Record command, exit status, test IDs, started time, duration when available, report artifact, environment, revision, fixture strategy, skipped/flaky status and exact failed assertions. A generated test file is not evidence of successful execution. Differentiate compiled/parsed, run, and validated-in-CI.

## Safe change policy
Writing tests may create files or invoke fixtures. Check the destination and rules first. Do not install tools, change product code, push branches, deploy or mutate persistent shared databases without clear authorization.
