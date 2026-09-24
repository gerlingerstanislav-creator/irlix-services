# Employees service

Employees is the source of truth for employees and organizational structure.

## Iteration 1 status

The first working vertical slice is implemented as an independent Laravel backend with its own Docker image, PostgreSQL credentials/schema, migrations and UI in the shared web shell.

Implemented persistence:

- `departments` — name, alias, optional parent department;
- `employees` — full name, department, position, employment status, work format and hire date.

Implemented endpoints:

- `GET /api/health` — service/database health;
- `GET /api/departments` — department list;
- `POST /api/departments` — create department;
- `PUT /api/departments/{id}` — edit department;
- `GET /api/employees` — employee list with optional `search` and `department_id` filters;
- `POST /api/employees` — create employee;
- `PUT /api/employees/{id}` — edit employee.

On the stand these endpoints are exposed through Nginx under `/api/employees/*`.

## UI

The shared Vue shell contains an Employees workspace with:

- employee registry;
- search and department filter;
- department creation;
- employee creation and editing;
- service/API status indicators.

## Deliberately not implemented yet

The following remain separate slices because the product rules are not completely fixed yet:

- LDAP/Keycloak provisioning;
- salary/FOT and salary review;
- final employment status dictionary;
- final work-format dictionary;
- permissions and organizational scopes;
- dismissal workflow and domain events.
