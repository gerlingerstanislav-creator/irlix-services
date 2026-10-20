# Final quality gates and working checklists

## Universal

- [ ] Repo rules, runtime versions and target environment actually observed; unknowns marked.
- [ ] Behavior and compatibility documented; no out-of-scope changes.
- [ ] New contracts: validation, authorization at function+object+field+scope, error responses and output serializer.
- [ ] Data: hard invariants constrained in DB, existing data preserved, rollback/forward fix considered, query plans and concurrency checked where relevant.
- [ ] IO: timeouts, retries, ambiguous outcome, dedupe, DLQ/outbox/ack where relevant.
- [ ] Security: secrets/PII redacted, least privilege and threat scenarios covered.
- [ ] Tests: positive, negative, edge, duplicate, failure, replay, regression; real runtime status honestly stated.
- [ ] CI/static analysis/docs/monitoring evaluated according to the task.
- [ ] No production action without permission; deploy status based on evidence only.
- [ ] Report distinguishes done, not done and assumptions. Do not invent per-agent duration.

## Database migration gate

- [ ] Existing rows and index/cardinality measured or explicitly unknown.
- [ ] Expand/contract order compatible with running apps and workers.
- [ ] Locking/DDL transaction constraints assessed; backfill bounded/restartable.
- [ ] Constraint validation and uniqueness duplicates evaluated.
- [ ] Recoverability, cleanup and monitor thresholds defined.

## Code review gate

- [ ] Findings ranked by impact with exact observed evidence and reproduction.
- [ ] No fabricated assertions about successful tests/deployment.
- [ ] Contract, permissions, concurrency and negative cases prioritized over style.
