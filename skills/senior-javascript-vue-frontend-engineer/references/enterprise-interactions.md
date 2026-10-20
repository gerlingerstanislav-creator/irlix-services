# Complex business UI interactions

Use existing design-system primitives first. For dense tables, forms, filters, dialogs, selection, drag/drop and bulk actions, make user-visible invariants explicit before coding.

## Large table checklist

- Stable row ID, deterministic sorting, server/client ownership of filtering and pagination, total counts and filter-specific empties.
- Search debouncing **when appropriate**; cancel or ignore stale requests. Keep query parameters, pagination and back/forward semantics aligned. Reset page only when changing filters requires it; preserve user selections where compatible.
- Loading strategy that avoids layout jumps and losing scroll; preserve column widths / compact density of established UI. Skeleton and errors should be usable; no transient blank table on minor refetch unless expected.
- Selection across pages: explicit all-visible/all-matching semantics and server bulk API contract; confirm destructive bulk actions and display per-item outcomes.
- Editable cells: controlled draft, validation, save/cancel, optimistic state policy, concurrent modifications and row-level pending indicator; never allow a later fetch to overwrite dirty cells silently.
- Large data: server-side fetch and/or measured windowing; if virtualized, verify variable heights, sticky headers, focus, screen-reader strategy and keyboard row navigation.

## Form checklist

- Required/optional/null semantics from API contract; inline errors; per-field and submit-time validation; async uniqueness checks need stale-request handling.
- Input type, locale/date/number/time zone handling; debounce avoids accidental double-submit but server idempotency is needed for important writes.
- `disabled` vs `readonly`, unsaved changes/navigation guard, focus first invalid field, error summary for long forms, return focus after modal close.
- Preserve input and cursor during background refresh. Separate server snapshot from mutable draft. Reset only upon intentional navigation or confirmed successful mutation.

## Dialog and drawer checklist

Use shared modal component with semantic dialog, correct label, initial focus, focus trap where modal, Escape, close policy when saving, focus restoration and body scroll management. Test nested overlays and route changes while open.

## Kanban / drag-and-drop checklist

Use an explicit transition matrix (source status, target status, allowed roles, optimistic update, rollback). Guard illegal changes on client for UX and rely on backend enforcement. Support keyboard alternative, accessible announcement, per-column scrolling without page jumps, and simultaneous/failed updates. Stable IDs, not index keys. Test a drag canceled midway or API failure.

## Cross-cutting edge cases

- Boundary values; long/RTL labels where supported; slow network/401/403/409/422/429/500, offline; resize/zoom; keyboard-only; touch vs pointer; concurrent edits; partial failures; empty datasets; stale filter responses.
- Confirm persistence location (query string, session state, server preferences) instead of assuming. Never add persistence without checking security/privacy and existing behavior.

Use [assets/component-contract.md](../assets/component-contract.md) and [assets/test-matrix.md](../assets/test-matrix.md) as output starting points.
