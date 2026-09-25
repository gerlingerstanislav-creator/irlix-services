# Employees domain events

Employees publishes domain events through RabbitMQ for other internal services. Event creation is decoupled from RabbitMQ availability with a PostgreSQL transactional outbox.

## Broker contract

- exchange: `irlix.events`;
- exchange type: durable `topic`;
- routing key: event type, for example `employee.created`;
- message delivery mode: persistent;
- message id: stable `event_id` from the outbox row;
- event contract version: `1`.

Consumer queues are owned by consuming services. A consumer should bind only the routing keys it needs and must treat `event_id` as the idempotency key.

## Envelope v1

```json
{
  "event_id": "4cbe7ed6-1b56-4f49-8e57-9966715ed384",
  "event_type": "employee.department_changed",
  "event_version": 1,
  "producer": "employees",
  "aggregate": {
    "type": "employee",
    "id": "42"
  },
  "occurred_at": "2026-09-25T10:00:00+00:00",
  "data": {
    "employee_id": 42,
    "login": "ivan.ivanov",
    "work_email": "ivan.ivanov@irlix.ru",
    "full_name": "Иванов Иван",
    "previous_department_id": 4,
    "department_id": 8,
    "position": "Developer",
    "employment_status": "Трудоустроен",
    "cooperation_type": "Штат",
    "work_format": "Удалённо"
  }
}
```

The integration payload intentionally does not contain salary data or `personal_email`. Services requiring additional protected data must obtain it through an explicitly authorized contract instead of expanding a general event payload.

## Published events

Version 1 currently defines:

- `employee.created`;
- `employee.updated`;
- `employee.deleted`;
- `employee.dismissed`;
- `employee.rehired`;
- `employee.department_changed`;
- `employee.access_changed`.

`employee.access_changed` currently describes explicit `company-admin` assignment/removal and adds `role` and `granted` to `data`.

Salary changes and onboarding delivery are audit events, not public domain events in the first contract.

## Transactional outbox

The `outbox_events` row is created by PostgreSQL triggers in the same database transaction as changes to `employees` / `employee_access_roles`. This guarantees that a committed source-of-truth change has a corresponding outbox event without making the Employees HTTP API depend on RabbitMQ availability.

The `employees-events` process reads pending outbox rows and publishes them. RabbitMQ AMQP transactions are committed before the outbox row is marked `published`.

Delivery semantics are **at least once**. A process/network failure after RabbitMQ accepts a message but before PostgreSQL is updated can result in a duplicate. Consumers must therefore deduplicate using `event_id`.

## Retry and dead-letter state

Publisher failures use exponential retry. Defaults:

- maximum attempts: `10` (`OUTBOX_MAX_ATTEMPTS`);
- retry delay starts at 10 seconds with exponential growth and is capped at one hour;
- after the maximum number of attempts the outbox row moves to `dead_lettered`;
- `last_error` remains stored for diagnosis.

`dead_lettered` is the durable application-level DLQ state for producer failures. Consumer services should define their own RabbitMQ queues and DLX policies when consumers are introduced.

## Versioning rules

- breaking payload semantics require a new `event_version`;
- additive nullable fields may be added within the same version when old consumers can safely ignore them;
- consumers must ignore unknown fields;
- routing keys remain semantic event names, independent of consumer names.
