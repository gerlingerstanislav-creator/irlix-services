# Frontend test strategy and reliable test design

**Testing pyramid by risk, not framework quota.** Inspect available frameworks and versions first; no new dependency solely for stylistic preference. Tests must verify observable contracts and isolate data, not private implementation details.

## Choose layers

- Pure unit (Vitest if present): normalizers, query codec, state transitions, sorting, error mapping, permission *display logic* (not backend enforcement), data reconciliation.
- Component (Vue Test Utils): controlled prop/event contracts, validation, async loading, error, refetch while editing, focus, keyboard semantics. Prefer real shared components for integration-critical behavior; stub truly external boundaries only.
- Integration: view + router/store/API client with controlled responses, auth bootstrap/guards, cache invalidation; mock at the network/service boundary to keep contracts honest.
- E2E (Playwright): critical user journeys including login callback/expired session, filters/back nav, submit/bulk actions, keyboard, responsive states; run on realistic environment, not just a component fixture.
- Visual regression: snapshots of **rendered browser** at pinned viewport/browser/font conditions. Review intentional diffs. Snapshot alone cannot ensure accessibility or behavior.

## Anti-flake rules

- Use Playwright `getByRole`, `getByLabel`, scoped locators and web-first `await expect(locator).to...`. Avoid fixed sleeps and brittle CSS selectors; deterministic fixtures and isolated contexts.
- In Vue Test Utils, `await wrapper.trigger()/setValue()` for DOM tick, `flushPromises()` for externally scheduled promises. Await actual event/response. Mock time only with cleanup; mocks should be reset/restored between tests.
- Exercise delayed requests with controlled deferred promises: issue A, issue B, resolve B, then resolve A; expect B. Also test rejection/abort and correct `loading` completion.
- Explicit test data IDs and cleanup, no accidental cross-test shared store or cache. Mock forbidden response and assert UI receives it; never interpret hidden button as proof of auth security.
- Test representative boundary/negative cases: double submit, 204, 401/403/409/422/429/500, dirty draft, filtered selection, empty datasets, long text, unmount, route changes, multiple simultaneous operations.

## Test review questions

1. Would test fail if the bug reappeared? Is assertion about an end-user effect rather than merely calling a mock?
2. Could all tests pass while stale data overwrites new data or a draft? Add concurrency/dirty-draft checks if relevant.
3. Is the mocking layer so broad that important integration code is bypassed?
4. Was `pnpm` vs `npm` vs `yarn` derived from the lockfile? Did typecheck and build actually run?
5. What was not runnable and why? Do not claim E2E passed unless Playwright ran against a browser.

## Case-study minimum acceptance

Every feature: positive + negative + boundary + relevant race/permission + UX/a11y check. Every bug: reproducer first, regression against observable outcome, nearby consumer impact. See [assets/test-matrix.md](../assets/test-matrix.md).

Sources: https://test-utils.vuejs.org/guide/advanced/async-suspense ; https://playwright.dev/docs/best-practices ; https://vitest.dev/guide/
