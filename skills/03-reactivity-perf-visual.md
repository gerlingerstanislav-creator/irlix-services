# Independent scenario 3 — reactive table regression and visual shift

## Prompt to skill (hypothetical Vue app)

"After sorting a 12k-row table, selections attach to the wrong rows, filters lag and the modal appears clipped on a laptop. Fix without breaking dense UI or changing its design system."

## Simulated reproduction and diagnosis

Assume source uses `v-for=(row,index)` with `:key=index`, selection map by index and a deep watcher triggering full table sort. Modal uses a fixed height and no scroll container. This is explicitly **hypothetical test input**, not an observed repository.

Failure mechanism: recycled DOM and selection by positional index break identity after sort; deep watcher may repeat expensive work; clipping requires viewport verification, not only source-level assumptions.

## Skill-guided outcome

- Replace index-based key and selection with `row.id`; test sorting, filtering, pagination and updates, including preserving the selected record and focus.
- Keep filtering/sorting in a `computed` or existing selector with stable inputs; consider server paging or windowing based on profiling rather than guessing. Avoid adding a virtualization package without benchmark, a11y and compatibility review.
- Reuse existing dialog; inspect its scroll-body, max-height and overlay stacking tokens; avoid global CSS overrides. Test laptop/narrow mobile/zoom; show long labels and error states.
- Confirm before/after interaction trace in production build and browser screenshots **before claiming performance or visual fix**.

## Executed evidence

`node --test validation/behavior-models.test.mjs`: `case 3` uses 12k synthetic stable-ID records, verifies selection is attached to ID across row reorder, derived view is referentially memoized for identical filters and invalidates when records change. **No real Vue render benchmark, mounted component, screenshot or browser comparison was performed**, so clipped-modal and frame-rate claims remain unverified.

## Assessment

Root cause isolation and safe state logic: pass at model level. Performance evidence: absent; visual regression: cannot pass without browser screenshot. The skill correctly forbids asserting those results and routes tasks to performance/visual references.
