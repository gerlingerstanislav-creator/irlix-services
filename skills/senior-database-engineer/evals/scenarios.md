# Evaluation: expected conclusions

1. Daily hours per employee/project/date with mutable employee name/account manager duplicate fields: retain one day-fact row and optional actual day comment; remove redundant mutable authority fields, use appropriate composite uniqueness. Don't split trivial hours into a table of attributes.
2. Large import fails during evidence export, before write: root cause is not proven to be sheer row count. Diagnose export/worker separately; prefer bounded resumable batches with reconciliation if warranted; avoid claiming write happened.
3. Financial invoice stores exchange rate at posting: preserving immutable historical snapshot is correct, not a normalization bug.
4. Single small enum: use CHECK/enum or simple table according to change needs; reject gratuitous entity/service layers.
5. Index proposed with no query or plan: identify predicate and existing keys; no fictional benchmark.
6. Populated table gets non-NULL field: require staged backfill and upgrade-path tests; avoid destructive one-shot migration.
