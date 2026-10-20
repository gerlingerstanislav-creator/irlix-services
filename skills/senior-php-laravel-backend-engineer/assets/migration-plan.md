# PostgreSQL migration safety plan

**Reason/behavior contract:**

**Postgres version + schema and data volume evidence:**

**Lock modes and maximum lock wait:**

**Step 1 expand (old and new apps compatible):**

**Step 2 backfill: batching, limits, checkpoints, idempotency:**

**Step 3 consistency verification: counts, duplicates, FK/null constraints:**

**Step 4 contract only after readers/writers upgraded:**

**Indexes:** query shape, `CONCURRENTLY` outside transaction, invalid-index cleanup.

**Migrate/revert strategy:** backward-compatible release, recovery of post-deploy writes, data restore/forward fix.

**Monitoring:** blocked transactions, CPU, disk, replication lag, errors, expected progress.

**Explicit approvals required:** production write, irreversible DDL, runtime/capacity risks.
