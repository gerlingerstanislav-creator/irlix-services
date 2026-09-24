# Employees service

Employees is the source of truth for employees and organizational structure.

## Iteration 1 status

The service is scaffolded as an independent Laravel backend with its own Docker image, PostgreSQL credentials/schema and health endpoint.

Current endpoints:

- `GET /api/health` — service/database health;
- `GET /api/employees` — empty collection placeholder for the first employee-list vertical slice.

The placeholder intentionally contains no invented domain fields or workflow rules. Persistence, migrations and employee/department APIs are added from the confirmed Employees specification in the next slice.
