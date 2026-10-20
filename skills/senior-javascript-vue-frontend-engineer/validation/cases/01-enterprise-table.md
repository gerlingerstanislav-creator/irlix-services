# Independent scenario 1 — dense records table, forms, filters and persistence

## Prompt to skill (hypothetical unfamiliar Vue app)

"Build a records page with sortable/filterable large table, modal editor, persistent search/page after navigation, save and partial failure. Use the existing design system. A background refresh must not erase unsaved inputs."

## Simulated discovery

Assume (explicitly for test only): Vue 3 + Vue Router, JS not TypeScript, an existing `UiTable`, `UiModal`, `UiInput`, `UiButton`, an API client and Vitest. Pinia absent. Actual use would first inspect package versions, route setup, component interface, UI tokens and server capabilities. **No forced TS, Pinia or third-party table**.

## Skill-guided outcome

- `RecordsPage.vue`: owns URL query mapping (normalized `search/page/sort`), remote load/refresh orchestration and empty/error/loading states; route is shareable; back/forward works.
- `RecordTable.vue`: existing table wrapper, stable `row.id` keys, keyboard row actions and selection semantics; avoid index keys.
- `RecordEditor.vue`: modal draft distinct from remote snapshot; validation; focus, Escape/close; field errors; save in progress; preserve dirty edit on refresh. Emits confirmed changes rather than mutating parent props.
- Use existing API client; abort and sequence-guard requests; request B wins if A returns late. Bulk writes distinguish successful and failed IDs.
- Provide `403` feedback; backend retains actual authorization.
- Unit tests for query codec; component tests for editor validation/draft; integration tests for filter order and stale response, query restoration, bulk partial failure; Playwright keyboard modal + laptop/mobile scroll.

## Acceptance edge cases

A search response resolving after a newer search cannot reset rows or loading; a background refresh doesn't wipe an unsaved input; browser back restores filter; page number invalid query safely normalizes; duplicate save cannot result in double submit; bulk success does not repeat on retry; shared DS remains visually consistent.

## Executed evidence

`node --test validation/behavior-models.test.mjs`: tests include `case 1` last response wins, dirty draft survives, query serializes and old failure does not overwrite new UI state. **These are isolated behavioral checks, not mounted Vue tests.** Vue/Playwright tests require a real host project and browser, so browser verification is **not performed**.

## Assessment

Architecture: pass at design level; concurrency: pass at model level; UX/accessibility: accounted for in test plan but not browser verified; security: backend enforcement noted; tests: behavior models executed, production Vue tests not run.
