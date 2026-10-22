# Data quality review checklist

Use selectively, based on changed facts and expected load.

- **Entity:** what does one row mean? Keys, unique composite key, nulls, valid ranges, deletes, optional relations.
- **Normalization:** are there repeating sets, multiple values per column, mutable copies of foreign information or hidden derived states? Prefer 3NF where it removes anomalies. Do not decompose fixed simple rows into many tables for theory alone.
- **Temporal and historical:** DATE vs timestamp, timezone, overlaps, immutable captured amounts/rates and auditability. Do not delete necessary snapshots merely because they duplicate current reference data.
- **Read paths:** exact predicate, join and order; current index; selectivity, cardinality, write overhead; measured EXPLAIN only when environment permits.
- **Write paths:** transactions, optimistic locking, duplicate prevention, idempotent import, outbox, retries, partial failures.
- **Evolution:** expand/backfill/check/contract; batching and resumability for large sets; validation against existing dirty data; explicit downgrade vs forward-fix choice.
- **Scope:** never make services read each other's tables directly; don't copy private production data to issue comments or logs.

Decision table:

| Condition | Preferred response |
|---|---|
| Single fact, straightforward keys and 3–5 columns | Keep one table; avoid artificial splits |
| Mutable data duplicated from another service | Store a reference/identifier or define explicit snapshot ownership |
| Immutable financial value captured historically | Historical snapshot can be necessary and correct |
| Millions of rows and partial failure risk | Chunk/checkpoint/reconcile before tuning schema |
| Index proposed without query or load information | Explain hypothesis; no invented speed claim |
| Migration drops populated data | Block until safe expand/verify/contract strategy |
