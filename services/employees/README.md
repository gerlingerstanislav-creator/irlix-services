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
- Employees-owned permission + scope authorization.

See `IMPLEMENTATION.md` for implementation details and `AUTHORIZATION.md` for the current access model.

## API exposure

The Employees backend is available internally as `/api/*`. On the stand Nginx publishes it under `/api/employees/*`.

Core endpoints include:

- `/api/health`;
- `/api/reference-data`;
- `/api/departments`;
- `/api/employees`;
- `/api/employees/{id}` and employee lifecycle/history endpoints;
- `/api/access/me`;
- `/api/access/company-admins`.

## Authorization summary

Keycloak authenticates the caller. Employees determines business access.

First-iteration visibility:

- department manager — managed department plus all descendants, including salary data;
- Finance subtree — all employees and salary data;
- HR subtree — all employee data except salary data;
- ordinary employee — no Employees access;
- explicit `company-admin` — full access regardless of organization position.

Write access for HR, Finance and managers is deliberately not granted yet because the business rules for mutations have not been confirmed.

## Deferred

- onboarding email template and delivery transport;
- write-permission matrix for HR, Finance and managers;
- least-privilege Keycloak provisioning service account;
- production Keycloak database/configuration;
- RabbitMQ domain events and audit trail;
- compensation/FOT workflow beyond actual salary history.
