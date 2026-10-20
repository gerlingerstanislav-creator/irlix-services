# Production adaptation: outbox and RabbitMQ

PostgreSQL tables:

```sql
CREATE TABLE event_outbox (
  event_id uuid PRIMARY KEY,
  event_type text NOT NULL,
  payload jsonb NOT NULL,
  published_at timestamptz NULL,
  attempts integer NOT NULL DEFAULT 0,
  locked_by text NULL,
  locked_until timestamptz NULL,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE consumer_inbox (
  consumer_name text NOT NULL,
  event_id uuid NOT NULL,
  consumed_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (consumer_name, event_id)
);
```

- Producer inserts domain row + outbox in **same** PG transaction. Index unpublished events; consider partition/retention.
- Relay claims pending/expired rows in a short database transaction (`FOR UPDATE SKIP LOCKED`, then sets a fresh lease owner and deadline); releases PG locks **before** publishing. A claimed row is selected only when `published_at IS NULL` and `locked_until IS NULL OR locked_until < now()`. Relay uses broker confirms plus mandatory/unroutable handling; after positive confirmation, marks published with a `WHERE event_id = ? AND locked_by = ?` guard. Lost confirm or lease expiry can republish. Monitor lease expiry and outbox oldest age.
- AMQP consumer validates schema+identity; in one PG transaction inserts inbox key `ON CONFLICT DO NOTHING` and changes business state only if inserted. On DB commit → manual ACK. Exceptions: rollback, bounded delayed retry, DLQ after max attempt; use broker-specific safe retry topology and `x-death`/attempt metadata carefully.
- High contention: use **unique constraint** to serialize duplicate inserts; consumers racing on same event wait/retry, not an unprotected `exists` pre-check.
- Validate consumer inbox dedupe retention >= expected redelivery/replay horizon; replay identity must not change.
- Tests with **real** RabbitMQ: crash before commit, after commit before ACK, reconnection, lost confirms, DLX routing, malformed message, retry budget, concurrent duplicate; measure DLQ depth/outbox oldest age.

This is a checklist/schema sketch, not a certified broker/driver implementation.
