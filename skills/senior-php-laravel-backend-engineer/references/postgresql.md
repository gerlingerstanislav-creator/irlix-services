# PostgreSQL, migrations, Eloquent, performance and concurrency

## Integrity first

- Model relations, FK with intentional `ON DELETE`, unique constraints (including partial/expression indexes when necessary), check constraints, appropriate nullability and default values. Prefer DB-enforced invariants to `if (!exists)` checks under concurrency.
- Specify numeric precision/timezone assumptions; money usually exact numeric/integer minor units; avoid unbounded `jsonb` for relational data without rationale.
- Scope all queries to authorization context. Avoid dynamic raw SQL. Bind values; whitelisted identifiers when sort columns are dynamic. Eloquent scopes must be reviewed for tenant leaks and privilege escalation.
- Avoid N+1 via explicit `with`, aggregates/`withCount`, constrained selections, pagination and batch processing. Verify with query counts; don't eager-load everything or hide performance with caching alone.

## Transaction decisions

- Choose isolation for the invariant, usually PostgreSQL `READ COMMITTED` default; understand that read-then-write is not atomic. `SELECT ... FOR UPDATE` on **existing rows** or atomic conditional `UPDATE ... WHERE ... RETURNING` may serialize transitions; locks on absent rows do not protect insertion races. Unique constraints and upsert/ON CONFLICT handle duplicate creation.
- For contention distinguish lock waits, serialization errors (SQLSTATE `40001`), deadlocks (`40P01`) and unique violations (`23505`). Retrying **entire transactions** needs bounded backoff and idempotent side effects. Never blanket-retry every exception.
- Keep transactions short; avoid external HTTP/broker/slow IO inside them. Commit before observable side effects, or persist an outbox entry in the same transaction.
- Concurrency review: lost updates, write skew under READ COMMITTED, delete vs update, competing worker claims, phantom assumptions, partial commits.

## Safe migrations

1. Assess data volume, lock mode/timeout, existing bad data, availability window, backfill and dual-read/write compatibility.
2. Expand: add nullable columns or new tables, indexes/constraints designed for online application; ensure old code still works.
3. Backfill in bounded, resumable, idempotent batches with checkpoints. Monitor locks, WAL/replication lag, CPU/disk and error rate.
4. Validate new behavior, add constraints only when data satisfies them, release compatible code, contract obsolete schema **after** old processes are gone.
5. `CREATE INDEX CONCURRENTLY` cannot execute inside a PostgreSQL transaction block; a failed build can leave an invalid index. Use appropriate non-transactional migration and inspect `pg_index`, plan cleanup/retry.
6. Rollback might be data-lossy after writes. Prefer forward fix and a tested recovery plan instead of assuming `down()` safely reverses migrations.

## Evidence-driven profiling

Capture `EXPLAIN (ANALYZE, BUFFERS)` in safe representative environment (ANALYZE **executes** the query; be cautious for writes); check estimated vs actual rows, seq scans, join choice, sort spills and filter selectivity; assess indexes with real query predicates. `pg_stat_statements` and application traces when available. Measure before/after p95 and query counts; don't promise perf improvements from code shape alone.

## Example atomic update

```sql
UPDATE stock_items
SET available = available - :quantity
WHERE id = :item_id AND available >= :quantity
RETURNING id, available;
```

0 returned rows means absent or insufficient capacity; distinguish by authorized subsequent lookup if API requires it. For logical balances/additional invariant across rows, consider separate locking/transaction design. Do not use this SQL verbatim where project schema differs.
