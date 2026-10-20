# Failure analysis / incident matrix

| Phase | Failure | Outcome known? | Retry safe? | Durable recovery | Alert metric | Test |
|---|---|---|---|---|---|---|
| Request validation | invalid payload | yes | no until corrected | 4xx error | validation errors | bad nested relation |
| DB transaction | conflict/deadlock | rollback | bounded complete tx retry only when safe | consistent invariant | contention | parallel operations |
| Publisher | timeout after send | uncertain | only with stable event id | outbox replay | outbox age | confirm lost |
| Consumer | crash after commit before ACK | ambiguous | yes with durable dedupe | redelivery + ack | redeliveries | duplicate event |
| External API | POST response lost | uncertain | only idempotency or reconciliation | status polling/reconcile | unknown outcomes | timeout after commit |
