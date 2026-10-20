# Architecture, Vue component contracts, types and state

## Default decision framework (not a mandatory directory convention)

- Feature module: route/page as orchestration; domain-aware components; feature composables; API adapter; types/validators; tests nearby. Align naming with the repository, not an imagined `features/` layout.
- Shared UI: reusable primitives and design-system building blocks; don't put feature-specific business rules in global controls.
- Local `ref` / `reactive`: modal visibility, draft form input, transient selection and local view controls.
- Route query: when state needs reload/back/forward/share/bookmark behavior (search, sort, filters, page); parse/sanitize on read, serialize canonically on change, avoid infinite route↔watch loops.
- Store (existing Pinia only if present): user-session metadata, cross-route workflow/client state; model reset/logout and persistence explicitly. Separate reactive UI flags from remotely authoritative records.
- Remote state: API is source of truth. Use project's request/caching mechanism; define freshness, invalidation, stale-response policy, optimistic mutation/rollback, server-side pagination and cache keys.

## Contracts for each component

Define: inputs/props and defaults, events, controlled vs uncontrolled state, slots, semantic markup, keyboard/focus, loading/error/empty, breakpoints, permission-dependent presentation, test identifiers if necessary. Use stable record IDs. Prefer emit-on-change over mutating props. Prefer `computed` for derivation and `watch` for effects, not interchangeable.

For TypeScript projects: strongly type exposed props/emits, route/query normalizers, service result/error unions. Type API boundary separately from presentation model. Don't use `any` or blind assertions to hide nullability. Runtime validation is needed when untrusted payload shape materially matters; TypeScript types alone are erased at runtime. Maintain JS style in JS projects without imposing migration.

## Decision checks

- Single-writer: which component/composable/store is allowed to mutate a piece of state?
- Lifecycle: what happens on route param change, refresh, logout, tab navigation, background polling, unmount, concurrent mutation?
- Consistency: should edits win over an incoming server snapshot? Is a dirty draft protected? Should list refetch preserve selected IDs, scroll position and focus?
- Navigation: what happens to unsaved edits on back/forward, page change or modal close? Confirm discard or save, don't silently drop.
- Failure: partial success in bulk operations; zero-result filter, expired auth, lost connectivity, unknown error; recoverable actions and messages.

## Avoid

Global store for one component's state; a store merely because Pinia exists; deep watchers for all fields when explicit emits/computed suffice; duplicated server data in multiple stores without invalidation; scattered `fetch` status handling; `watchEffect` with hard-to-audit dependencies for critical mutations; lifecycle hooks as substitutes for all data modeling.

Reference: https://vuejs.org/guide/typescript/composition-api ; https://vuejs.org/guide/reusability/composables ; https://router.vuejs.org/guide/advanced/data-fetching
