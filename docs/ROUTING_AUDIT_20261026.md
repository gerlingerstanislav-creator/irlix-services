# Frontend routing verification — 2026-10-26

This is an implementation audit, not production acceptance. Baseline: PR #638
(`f8df7944c2f3e6d0ee95c93654ef18f32b9666bf` on `development`).

## Architecture checked

Each frontend under `apps/{web,vacations,clients,timesheets,specialists,recruitment,cv,portal,system,migration,brandbook,design-system,finance,equipment}`
has its own `src/routes.js` and `src/router.js`. Each uses Vue Router 4
with `createWebHistory(import.meta.env.BASE_URL)`. Vite bases and host
`infra/nginx/irlix-services.conf` were reviewed for matching service
prefixes; Portal uses the root `/`. The UI package exports components but
does not export the previous global section-routing mechanism.

## Regressions addressed

- Clients: `/clients/clients/` and `/clients/reporting-periods/` are the
  canonical URLs; legacy `/clients/` and `/clients/reports/` redirect without
  dropping query/hash. Unknown routes are still 404.
- Clients: direct `/clients/attempt-funnel/`, back/forward and its view toggle
  agree on the selected view. This deep link requires its own
  `attempts.analytics.view` permission even if the user can view ordinary attempts.
- Timesheets: historical partner page URLs (including the `section=partners`
  query form) lead to `/timesheets/management/` and preserve filter context.
- System: `/system/migration/console/` (the destination of the old
  `/migration/console/` host redirect) remains navigable and canonicalizes
  to the embedded control page.

## Automated regression scopes

- `apps/clients/tests/navigation.test.js`: canonical paths, redirects, view
  permissions, and route-driven tab behavior.
- `apps/clients/tests/routing.browser.cjs`: synthetic Chromium with mocked
  identity and APIs; direct URLs, refresh, browser history, query/hash and 403/404.
- `apps/timesheets/tests/routing.test.mjs` and
  `apps/timesheets/tests/partner-hours.browser.cjs`: partner alias and context.
- `packages/ui/tests/navigation.test.js`: standalone Router ownership and
  System Migration legacy redirects.

Run via `node scripts/qa/run.mjs --scope all` and
`node scripts/qa/browser.mjs --scope all` or the GitHub CI/QA jobs.
The browser runner uses local Vite previews and fixtures; a green synthetic
test is **not** equivalent to successful authenticated deployment smoke.

## Deployment evidence still required

After explicit release authorization, smoke-test host-nginx paths for all 14
apps, including direct open, refresh, Back/Forward, OIDC callback, expired
session, 403/404 and cross-app navigation with real effective permissions and
scope. No production action is approved by this audit.

## Reconciliation: one Migration Control page (PR #509)

The 20 October System product document still mentions two Migration pages.
That description was superseded on 21 October by approved implementation
PR #509 (`refactor(migration): one Control page with per-service connection
settings`). The former management console was **intentionally retired**.
`/system/migration/` and other legacy URLs redirect to the single
`/system/migration/control/` page, preserving query and hash.

The active Control interface includes operations, status/history, error
diagnostics, protected connection administration and snapshots/restore
with confirmation. Server permissions, archived records and underlying
Migration APIs remain unchanged. Recreating a second console would conflict
with the adopted single-page architecture. Issue #642 was based on the
outdated two-page description and is not a routing release blocker.

Synthetic Chromium checks and GitHub CI were green on development merge
`39a01fc6118b267390bb4854e130b3c33d21e292`; live authenticated
production navigation and effective scope are **NOT TESTED** until post-deploy
smoke. Production rollout requires separate owner authorization.
