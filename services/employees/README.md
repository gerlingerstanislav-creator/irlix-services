# Employees service

Employees is the source of truth for employees, organizational structure and company-level special functional role assignments.

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
- special functional roles stored in `employee_access_roles`;
- dedicated **Роли** UI for assigning/removing special roles;
- `company-admin` and `personnel-officer` as the initial special role catalog;
- administrator audit trail for sensitive mutations;
- transactional outbox and RabbitMQ employee domain events.

See `IMPLEMENTATION.md` for implementation details, `AUTHORIZATION.md` for the current access model, `AUDIT.md` for the audit contract and `EVENTS.md` for the RabbitMQ contract.

## Organization roles vs special roles

These concepts are intentionally separate.

- Department manager is derived from `departments.manager_id`.
- Directional HR is assigned through `departments.hr_id` and remains part of the organization model.
- Membership in the HR department is also an organization-derived access characteristic.
- **Кадровик** is the special functional role `personnel-officer`; it is independent from department, position and directional HR assignment.
- **Администратор компании** is the special functional role `company-admin`.

An employee can simultaneously belong to an ordinary department, be a directional HR or manager, and have one or more special functional roles.

Vacations integration receives both organizational `hr_approver` and the independent `personnel_officers` list in the absence approval context. The consumer must use the role appropriate to the workflow stage instead of treating HR and personnel officers as synonyms.

## API exposure

The Employees backend is available internally as `/api/*`. On the stand Nginx publishes it under `/api/employees/*`.

Core endpoints include:

- `/api/health`;
- `/api/reference-data`;
- `/api/departments`;
- `/api/employees`;
- `/api/employees/{id}` and employee lifecycle/history endpoints;
- `/api/access/me`;
- `/api/access/roles`;
- `PUT|DELETE /api/access/roles/{role}/{employee}`;
- backward-compatible `/api/access/company-admins...` aliases;
- `/api/audit` for full administrators.

## Authorization summary

Keycloak authenticates the caller. Employees determines business access.

First-iteration visibility:

- department manager — managed department plus all descendants, including salary data;
- Finance subtree — all employees and salary data;
- HR subtree — all employee data except salary data;
- ordinary employee — no Employees UI access;
- explicit `company-admin` — full access regardless of organization position;
- `personnel-officer` — functional marker used by business services; by itself it does not grant Employees UI or salary access.

Special roles are managed only by callers with `access.manage`. Assignment changes are audited.

Write access for HR, Finance and managers is deliberately not granted yet because the business rules for mutations have not been confirmed. Audit-log access is restricted to full administrators.

## Audit and events

Every supported sensitive mutation is recorded in `audit_log` with actor, target, result and before/after snapshots. Special role assignment/removal is included in the same audit trail. The Employees UI exposes an **История действий** section to callers with `audit.read`.

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
