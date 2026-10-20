# Component and complex interface playbooks

## Form design
1. Group by user task, not table schema; align common mental sequence; use one meaningful heading per group.
2. Permanent visible labels; mark required consistently; do not use placeholder as sole label.
3. Correct control type, sensible default only when safe, input mask tolerant of paste, autocomplete when applicable, unit/date/currency clarity and locale.
4. Validate without interrupting typing: inline on blur/submit or safe progressive rules; show both summary and field-local correction for multiple errors, move focus appropriately.
5. Preserve dirty state on errors/navigation; save progress for long/high-cost forms; warn before loss where warranted.
6. Duplicate submission protection and pending state for async save; distinguish request sent from confirmed server success.
7. Specify keyboard, screen reader, accessible help and error linking; mobile virtual keyboard type, sticky actions and safe area.

## Tables vs interactive grids
First ask what must be compared, scanned, edited and exported. Use native semantic table for reading/tabular data; interactive grid **only** if spreadsheet-like focus model/arrow-key interaction is intentionally designed. Table with buttons does not automatically require `role=grid`.

For dense data:
- Leftmost identity column, clear headers and visible scope; stable units and dates.
- Long labels: truncation + accessible expansion; never silently lose decisive context.
- Sticky headers and frozen columns only where they prevent loss of meaning; watch overlap, focus and scroll synchronization.
- Sorting: explicit direction, stable deterministic tie-break; filters visible and removable, count of matches and reset.
- Pagination/infinite load: explain count/scope and maintain selection, focus, filters and return-to-row; virtualize carefully for accessibility.
- Row/column edit: display editable state, cancel/commit, validation, optimistic vs pessimistic policy, conflict and undo.
- Bulk actions: scope clarity (“visible page” vs “all matching”), confirmation proportional to loss and partial-failure report.
- Status: text/icon + color; provide legend only if vocabulary is unfamiliar.
- Narrow screens: evaluate preserved table with horizontal scroll, progressive columns, detail drill-down, or pivoted layout based on primary comparison task; never blindly stack into cards.
- Compact rows: check actual interactive target dimensions, keyboard/focus, zoom and touch alternatives separately from text row height.

## Navigation and search
Separate global menu from contextual entity tabs. Predictable naming, current state, keyboard focus, back behavior, direct URLs. Search supports no result, keyboard, loading, clear, typos when practical. Filters should not disappear after navigation; show active chips/count and saved presets if repeatability matters.

## Dialogs, drawers and notifications
Choose dialog for short focused interruption; drawer for adjacent contextual edit where original context matters; full page for long/complex forms. On modal open move focus to suitable element, trap focus *while open* and restore logically after close; Esc policy explicit; error/submit behavior unambiguous. Do not stack dialogs by default. Confirmations are not a substitute for undo when reversible.

Toast: ephemeral confirmation for low-risk completed actions, with persistent record where necessary. Banner/inline: current critical contextual state or recoverable error. Skeleton: when structure is predictable; never fake a success response.

## Feedback for async and collaborative flows
Distinct: idle, saving, saved, queued, rejected, retrying, conflict. Tell user whether edits are local, submitted, or approved. Avoid polling updates wiping dirty forms; reconcile server data selectively and expose competing edits. Explain partially applied bulk updates; ensure retry idempotence is designed with backend owners.

## Native mobile and responsive web
Respect system navigation, input conventions, physical keyboard support where relevant, dynamic type and touch accessibility. For tablet operations, think landscape, gloves, intermittently offline workflow. Do not reuse desktop hover-only disclosure in touch experiences. More guidance: `05-accessibility-responsive.md`.

## Design component contract
For each new or extended component document: purpose, use/don't use, variants, tokens, labels, states, role/ARIA/native semantics, keyboard interactions, sizes/density, responsive transformations, content limits, analytics hooks (if authorized), permissions and examples. New component requires migration/compatibility notes for existing consumers.
