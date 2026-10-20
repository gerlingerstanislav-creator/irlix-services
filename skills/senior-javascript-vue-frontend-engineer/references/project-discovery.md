# Project discovery and change-impact reconnaissance

Read on first use of an unfamiliar project, migrations, dependency decisions, or disputed conventions. Explore **read-only first**. Do not treat this checklist as a reason to change files.

## Inventory in order

1. Read workspace/agent instructions (`AGENTS.md`, CONTRIBUTING, README, docs) and the request; resolve conflicting conventions by instruction priority.
2. Inspect `package.json` (scripts, dependencies, engines), one existing lockfile, Node/npm version, `vite.config.*`, `tsconfig*`, `eslint*`, formatting setup, CSS build, Vue SFC conventions and Router/Pinia versions. Do not assume all dependencies are installed.
3. Inspect `src/main`, route layout, feature pages, shared components/composables/stores, data-service clients, API contract files, auth bootstrap, error and toast patterns.
4. Find design tokens, themes, Storybook, design-system documentation, shared tables/form/dialog/notification components; avoid copying a screenshot into a second design system.
5. Inspect tests (Vitest/Vue Test Utils/Playwright config, fixtures, mocks, CI status) and known browser-support matrices. Note cross-browser support and mobile/tablet constraints.
6. Inspect deployment contracts only if applicable: Dockerfile, Nginx SPA fallback, CI scripts, environment substitution, source maps, caching and cache invalidation.
7. Find all users of impacted code with bounded repo-wide symbol searches, not just its declaration. Examine telemetry/error reports only when authorized.

## Decision record

| Field | Evidence required |
| --- | --- |
| App mode | SPA / SSR / hybrid / embedded Vue with proof from repo |
| Stack | Exact installed versions; lockfile and scripts |
| State | local / route / store / remote; data lifecycle |
| Design | shared component names/tokens and exemptions |
| API/auth | existing client/interceptors, auth SDK and server contract |
| UX | accepted workflows, business invariants, unsaved edits |
| Tests | existing layers, fixtures, runnable commands |
| Risks | migration, permissions, races, large datasets, responsiveness |
| Validation | which checks can *actually* be run in available environment |

## Architecture red flags to inspect, not auto-rewrite

- Components calling fetch directly in many unrelated places; global mutable singleton stores; duplicated loading flags; side effects in `computed`; reliance on array index keys; route params cast without validation; implicit single-page focus assumptions.
- Unbounded `watch` or event listeners, fetch races, optimistic updates without rollback, deep watchers of large objects, filters mutated by a polling response, large derived arrays rendered without windowing.
- Ad-hoc `v-html`, raw `innerHTML`, dynamic user URLs, `VITE_` secrets, manually decoded JWT treated as authorization, tokens in localStorage, a one-off security SDK beside an established one.

## Guardrails

- Read-only helper: `python scripts/audit_frontend.py /path/to/project` prints dependency, convention and limited pattern hints; **heuristics are leads, not findings**. It never modifies source.
- Avoid logs of `.env`, key material, access tokens or request authorization headers. Do not transmit source to third parties without user authorization.
- Provide a small impact map and expected checks before an invasive change. For a truly blocking contract ambiguity, specify the missing fact and its impact; otherwise proceed with conservative defaults that preserve behavior.
