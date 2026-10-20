# Code review, debugging, profiling and incident diagnosis

## Reproduction protocol

1. Capture symptom, exact route/command, status, expected vs actual, deployment version and impact. Redact credentials, tokens, payload PII.
2. Trace request IDs and data flow through guards, middleware, validation, policy, domain behavior, DB queries, queue and external IO. Separate observed evidence from hypothesis.
3. Build a smallest failing regression test **before fixing** where feasible. Check concurrency timing and transaction/isolation boundary; realistic database and worker versions matter.
4. Compare query counts, EXPLAIN plans, memory, p95/p99, lock waits and queue lag; optimize one bottleneck with before/after evidence.
5. Patch invariant, not symptom; prove negative/forbidden cases, broad regression and compatibility. Flag unresolved factors and rollback trigger.

## Review ordering

Critical: auth bypass, cross-tenant read/write, secrets disclosure, money/data corruption, unsafe migration, lost writes, unbounded retry storm. High: contract regression, race, timeout cascade, unrecoverable poison message, unbounded query. Medium: insufficient edge coverage, performance without measured impact, maintenance risk. Low: style/naming if project standard inconsistent.

Finding format: severity / file:line / concrete failing input or interleaving / violated invariant / evidence status (`verified` or `plausible`) / minimal fix / exact test to prevent recurrence. Never invent line numbers. Do not bury blocking findings under style advice.

## Typical hidden failure modes

- Model binding scoped too broadly; ability check only at route not on target object.
- `firstOrCreate`/`updateOrCreate` treated as race-free without unique index.
- Lock acquired on record absent from DB, then concurrent insert duplicates.
- `ShouldBeUnique` presumed exact-once; broker ACK before database commit; reconnection creates duplicate work.
- Jobs dispatched inside uncommitted transaction; network call inside transaction; DB deadlock retry repeats external side effect.
- Index created blindly and missing actual predicate selectivity; offset pagination under updates; aggregate exposed cross-tenant data.
