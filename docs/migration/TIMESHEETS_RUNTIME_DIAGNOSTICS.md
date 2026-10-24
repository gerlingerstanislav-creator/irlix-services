# Timesheets dry-run: memory diagnosis (24 October 2026)

## Observed failure
Coordinator operation 1792833696185068 terminated while Timesheets dry-run #278 remained marked running; its report was not archived. Prior read-only diagnostic found 987 processed rows, no heartbeat and a running worker container. A container being alive does not prove that the job is alive.

## Specific scalability defect
Source rows are already fetched in keyset pages of 100–2000, but `TimesheetsMigration::import()` collects all source rows into `$groups` and `$daily` before writing groups. This defeats bounded-memory reads and repeats during migrate preflight. A two-hour coordinator deadline can then expire while the job still reports running.

## This patch (safety instrumentation, not partitioning)
- At each 250 eligible facts in grouping, report fact count, group count and PHP allocated MiB.
- Detect memory budget pressure before PHP memory exhaustion / container OOM, emit `TIMESHEETS_MEMORY_PRESSURE` with safe counters.
- Check operator cancellation during grouping, rather than only after group write.
- Set `MIGRATION_TIMESHEETS_MEMORY_GUARD_MB` (default 384 MiB; effective guard also <= 80% PHP memory_limit).
- Do not clear mappings, worker runs, snapshots or source data.

## Remaining prerequisite for complete fix
Replace the all-at-once `$groups` accumulation with disk-backed, deterministic staging partitioned by ClientMember/day (e.g. staging table), with per-partition checkpoint, resumability, and full-source dry-run hash verification. Do not truncate a dry-run to simulate success: the source inventory/hash and write guard would be invalid. Establish worker process liveness and active SQL before marking run #278 stale. Then run a read-only bounded pilot and separately a full dry-run before writes.

## Operational acceptance
1. No new run while #278 may still be active.
2. Confirm worker/DB liveness and classify old failed jobs.
3. For any restart, report runtime memory/CPU, page progress and archive proof.
4. CI PHP lint and Timesheets migration regression tests required before merging.
5. This patch has not been deployed and is not proof the import can complete.
