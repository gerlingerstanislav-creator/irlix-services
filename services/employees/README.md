# Employees service

Employees is the source of truth for employees and organizational structure.

## Iteration 1 status

The service is implemented as an independent Laravel backend with its own Docker image, PostgreSQL credentials/schema and migrations. The Vue frontend is published under `/employees/`.

Implemented areas include:

- department hierarchy and organization management;
- employee registry and card;
- employee creation and Keycloak provisioning;
- employee profile changes and identity synchronization;
- employment/cooperation periods;
- dismissal and rehire lifecycle;
- department/position assignment history;
- salary history;
- OIDC login and JWT/JWKS validation;
- Employees-owned permission + scope authorization;
- explicit `company-admin` management;
- administrator audit trail for sensitive mutations;
- transactional outbox and RabbitMQ employee domain events.

See `IMPLEMENTATION.md` for implementation details, `AUTHORIZATION.md` for the current access model, `AUDIT.md` for the audit contract and `EVENTS.md` for the RabbitMQ contract.

## API exposure

The Employees backend is available internally as `/api/*`. On the stand Nginx publishes it under `/api/employees/*`.

Core endpoints include:

- `/api/health`;
- `/api/reference-data`;
- `/api/departments`;
- `/api/employees`;
- `/api/employees/{id}` and employee lifecycle/history endpoints;
- `/api/access/me`;
- `/api/access/company-admins`;
- `/api/audit` for full administrators.

## Authorization summary

Keycloak authenticates the caller. Employees determines business access.

First-iteration visibility:

- department manager — managed department plus all descendants, including salary data;
- Finance subtree — all employees and salary data;
- HR subtree — all employee data except salary data;
- ordinary employee — no Employees access;
- explicit `company-admin` — full access regardless of organization position.

Write access for HR, Finance and managers is deliberately not granted yet because the business rules for mutations have not been confirmed. Audit-log access is restricted to full administrators.

## Audit and events

Every supported sensitive mutation is recorded in `audit_log` with actor, target, result and before/after snapshots. The Employees UI exposes an **История действий** section to callers with `audit.read`.

Cross-service events use PostgreSQL `outbox_events` plus the separate `employees-events` publisher. Source-of-truth changes and outbox events are committed transactionally; RabbitMQ outages do not make the Employees HTTP service unavailable. Events are published to durable topic exchange `irlix.events` with at-least-once semantics and stable `event_id` for consumer deduplication.

## Deferred

- final SMTP/onboarding delivery verification on the stand;
- write-permission matrix for HR, Finance and managers;
- employee self-service profile outside Employees;
- least-privilege Keycloak provisioning service account;
- production Keycloak database/configuration;
- audit retention/archival policy;
- concrete consumer queues/DLX policies as downstream services are implemented;
- compensation/FOT workflow beyond actual salary history.
