# 02 — Requirements, risk and advanced test design

## Requirements decomposition
For each business rule record: ID; source/version; actor; preconditions; trigger/action; inputs; expected outputs and persistent effects; exceptional behavior; audit event; state transitions; data owners/tenants; oracle status (`verified`, `assumed`, `missing`, `conflict`). Separate **requested behavior** from **currently implemented behavior** and **observed behavior**.

Use Given/When/Then for single observable examples but supplement it with tables for logic-heavy rules. A business process may need a sequence diagram, state transition table, ownership matrix or temporal invariant. Label suppositions.

## Risk-based selection
Risk register columns: ID, asset/journey, failure mode, user/business harm, likelihood indicators, impact, detection difficulty, existing controls, verification level, release significance. Prioritize by business impact and uncertainty; do not assign arbitrary mathematical risk scores unless the team has approved scales. Treat cross-tenant leakage, irreversible data loss, silent corruption, duplicate payments, authorization bypass and unintended production mutation as high by default until evidence reduces risk.

Time-boxed prioritization: P0 security/data irreversibility; P1 primary domain path and integration failures; P2 common UX/error states; P3 cosmetic/rare low-impact cases. These are heuristics, not a project's defect severity convention.

## Techniques, application and completeness

### Equivalence partitions (EP)
For inputs/outputs/configurations, partition by behavior, not merely by data type. Valid and invalid classes must be mutually distinguishable. For `1..10` inclusive integral quantity: <=0, 1..10, >=11, noninteger, nonnumeric, omitted/null (if relevant). Verify encoding, trimming and Unicode normalization where meaning changes. Avoid claiming a representative tests all combinations of independent dimensions.

### Boundary value analysis (BVA)
For every applicable boundary L/U consider L−δ, L, L+δ, U−δ, U, U+δ. Choose δ based on the domain (integer step, monetary currency precision, time precision), not a generic 1 for decimals/dates. Watch inclusive/exclusive, min/max length in bytes vs characters, timezone transitions, overflow, and multi-field constraints. `scripts/generate_boundaries.py` can generate exact numeric candidates. Provide expected result or a justified undecided oracle for each.

### Decision tables
List conditions and outcomes; enumerate feasible combinations, mark impossible combinations with explicit reason, and identify conflicting or missing outcomes. Do not silently assume that a hidden UI action equals denied backend operation. Use constraints when applying pairwise generation; confirm high-risk multi-way interactions separately.

### State machines
Represent state as persisted business state plus relevant actor/token/temporal state. Map allowed edge + guard + effect and forbidden edge + expected rejection/no mutation. Test: direct invalid jumps, repeated transitions, replay with old version, state changed in another session, cancel during processing, double approval, restoration/rollback, audit trail. Coverage denominator = eligible transitions and high-risk invalid edges, **not just number of states**.

### Cause-effect and combinatorial
Model independent factors such as role, tenant, feature flag, object status, network, locale, time and channel. Pairwise helps ordinary feature interactions; enforce constraints and explicitly add high-risk 3-way, 4-way or concurrency cases. Avoid exploding unbounded Cartesian products and disguising risk gaps with pairwise percentages.

### Property / metamorphic
If exact oracle is difficult, specify invariant/relation first: import(export(x)) preserves defined fields, a repeated idempotent request creates at most one mutation, sorting changes order but not membership, permissions can only narrow when scope removed, round-trip conversions remain within allowed tolerance. State the tolerance and excluded nondeterminism.

### Exploratory testing
Write charters with mission, risk/target, time box, hypotheses, data and observed notes. E.g. "Attempt to bypass expense approval with stale tabs and delegated account; preserve timestamps, request IDs and outcomes." Record actual paths explored, not just impressions.

### Mutation / fault injection
Use where tooling and authorization allow: mutate conditional boundaries; drop/delay dependency responses, duplicate events, reorder messages, abort midway through transaction. Confirm rollback/idempotency/reconciliation rather than simply expecting success. Do not inject faults into production without explicit approval.

## High-risk scenario generator (mental checklist)
1. **Actors and scope:** anonymous/authenticated; least/most privilege; actor A vs B; foreign tenant, temporary access, revoked role, stale token.
2. **Object lifecycle:** create, draft, submit, review, approve/reject, archive/delete/recover; illegal/replayed transitions.
3. **Data:** missing/null/empty, malformed, duplicates, min/max, mixed encodings, roundoff, out-of-range, large imports, legacy fixtures.
4. **Order and time:** network delay, retry, duplicates, ordering, optimistic/pessimistic locking, idempotency keys, DST, close-of-day, leap date.
5. **Failure/recovery:** partial write, failed downstream call, crash before acknowledgment, rerun/resume, rollback, audit trail.
6. **Change impact:** backwards compatibility, old clients, migration, caches, feature flags, rollbacks, dependent projections.

## Minimal traceability matrix
| Rule / risk | Test ID | Level | Actor/tenant/state | Oracle | Execution | Evidence |
|---|---|---|---|---|---|---|
| REQ-01 / unauthorized edits | TC-003 | API | outsider / foreign tenant / approved | 403 and no mutation | not_run | none |

Identify holes explicitly: risk with no test, test without trace or oracle, test without evidence, and unverified high-risk path.
