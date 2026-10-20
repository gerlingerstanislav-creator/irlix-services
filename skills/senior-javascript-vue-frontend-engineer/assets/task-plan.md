# Frontend task plan — [feature / bug]

**Goal / actor / expected visible result:**

**Facts vs unknowns**
- Observed repo versions, DS, components, API/auth:
- Current behavior and preserved invariants:
- Unknowns and assumptions (only genuinely blocking questions):

**Impact map:** touched feature/views → shared components/composables/stores → API contracts → tests → potential regressions.

**Design:** state ownership (local/route/shared/remote), data-flow, request concurrency, UI states, keyboard/responsiveness, caching/invalidation, security/permissions.

**Implementation steps:** narrowest diff, tests paired with each risky change, no incidental migration.

**Validation:** repo scripts and exact commands; automated scenarios; browser states + viewports when executable.

**Risk/rollback:** data consistency, auth, large data, cache/asset rollback.
