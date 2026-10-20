# PHP / Laravel architecture and maintainability

## Choose the minimum layers needed

- HTTP transport: controller orchestrates request/response, delegating validation to FormRequest and authorization to policies/middleware where appropriate. API Resource controls the public representation. Never pass unconstrained `$request->all()` into model writes.
- Application action/service: use when behavior spans multiple models, external boundaries or is reused; put the transaction around a coherent invariant, not the whole HTTP request. Direct model/Eloquent operations are fine for simple CRUD.
- Domain: make invariants explicit; domain exceptions convert to documented API status without leaking internal stack traces. Value objects/enums help if they prevent invalid states; avoid speculative abstractions.
- Persistence: start with built-in Eloquent, Builder, scopes and database constraints. Custom repository interfaces only when swapping persistence, complex queries or strong test seams justify them.
- Dependencies: constructor injection for IO/time/senders when testing/failures warrant it, interfaces at genuine boundaries. Avoid container/service locator everywhere.
- Multi-tenancy: derive tenant from verified auth/context, not request body; scope route binding, queries, cache keys, jobs and all output representations consistently.

## Architecture decision table

| Need | Default | Promote only when |
|---|---|---|
| Simple CRUD | Request + Policy + Controller + Model + Resource | Workflow grows or reuse appears |
| Multi-step invariant | Narrow service/action + `DB::transaction` | Multiple domains/IO require orchestration |
| Cross-service interaction | Explicit client with timeout + error taxonomy | Actual independent deployment/service boundary |
| Reads with many joins | Query Builder / Eloquent eager loading | Profiling demonstrates need for more |
| Async side effects | Laravel queue with after-commit semantics | Durable cross-boundary delivery needs outbox |

## Safe refactoring

Characterize existing status codes, payloads, ordering, pagination, error envelope, field/null semantics, event emission and transaction scope. Confirm affected consumers and deployed worker compatibility. Add behavioral tests first, then refactor in small steps. Change migrations and public API separately where possible. Preserve database defaults/constraints and authorization filters. Show comparative query counts and `EXPLAIN` evidence for performance-motivated refactors.

Anti-patterns: an all-purpose `BaseService`, repository for every model, giant controllers, controller-owned transactions across network calls, hidden event effects, indiscriminate model observers, global scopes that conceal trust-boundary behavior.
