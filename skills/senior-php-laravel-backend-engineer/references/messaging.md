# Reliable RabbitMQ, Redis queues, events and schedulers

## Explicit delivery model

Default assumption: at-least-once. Messages can be duplicated, reordered and delivered after a long delay. Publisher confirms, durable exchange/queue, persistent messages, manual consumer acknowledgements and retry/DLX are distinct controls. Acknowledge **after** durable processing. On channel/connection loss, delivery may repeat even if work succeeded. Never claim exactly-once delivery.

## Producer checklist

- Event identity, schema version, tenant/subject correlation, timestamp and stable domain IDs; no secrets/PII without necessity.
- Store domain state and **outbox record in one DB transaction** when losing a message after commit is unacceptable. Dispatch after commit avoids early visibility but can still be lost if process dies before broker accepts it.
- Separate relay publishes pending outbox rows with publisher confirms, marks delivered after confirmation; relay can duplicate, so consumers must deduplicate. Claim outbox batches with database locking such as `FOR UPDATE SKIP LOCKED` where appropriate, lease/reclaim stuck work, and never hold DB transaction open during network publish.

## Consumer checklist

- Validate event schema and expected source, stable event ID; reject poison/unknown versions with deliberate DLQ policy.
- Claim/process idempotently: durable unique key `(consumer_name, event_id)` in DB, with **business mutation and processed marker committed atomically**. A bare 'seen' check is racy, and marking success before the transaction can lose work. For long workflows use an explicit state machine/lease and downstream idempotency keys.
- Bounded retries with exponential backoff and jitter (broker/worker capabilities differ), classify transient vs permanent, cap attempts/time, route poison events to DLQ and alert. Avoid infinite immediate `requeue=true` hot loops.
- AMQP: manual ack after commit, nack/dead-letter or delayed retry policy on failure; consumer prefetch matched to memory/latency; quorum queues and DLX guarantees depend on configuration and broker version.
- Use producer correlation/causation IDs, structured logs, lag/age, attempt count, retry, DLQ and replay metrics. DLQ replay must preserve dedupe identity and authorization context.

## Laravel nuance

`ShouldBeUnique` and `WithoutOverlapping` only coordinate scheduling/execution; they are not durable proof of processing. `afterCommit()` avoids dispatch before transaction commit but does not make DB + RabbitMQ atomic. RabbitMQ support generally requires verifying a third-party Laravel driver/library against installed framework version. Don't write Redis queue API assumptions for RabbitMQ.

## Redis and scheduling

Use cache TTLs, namespaced keys, avoid cache-as-source-of-truth for financial/data invariants, defend against stampedes where measured. Locks need TTL and safe release; consider fencing/version checks when losing a lock would violate correctness. Scheduler jobs must tolerate repeated firing, worker restarts and partial completion; avoid multiple schedulers without coordination.

## Example pseudo-handler (transaction and broker adapter vary)

```text
receive(event):
  validate schema, tenant/source, event_id
  begin DB transaction
    INSERT INTO consumed_events (consumer, event_id) ... ON CONFLICT DO NOTHING
    if insert did not happen: commit; ACK; return
    apply business mutation (DB-enforced invariants)
  commit
  ACK
on transient exception: ROLLBACK, bounded delayed retry / NACK per configured topology
on permanent exception: ROLLBACK, quarantine/DLQ + alert
```

If business work includes external IO, do not do it inside the DB transaction; persist a state/outbox and use idempotent downstream calls.
