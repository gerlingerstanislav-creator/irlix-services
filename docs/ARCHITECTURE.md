# Architecture

## Repository layout

```text
apps/
  web/                  # Vue platform shell
services/
  platform-core/        # shared platform capabilities
  employees/            # first business service
infra/
  postgres/init/        # shared PostgreSQL bootstrap with isolated schemas
.github/workflows/      # CI/CD
```

## Runtime

The first iteration runs as one Docker Compose project while preserving service boundaries.

```text
Internet
   |
Host nginx :80
   |-- / --------------------> web :80
   |-- /api/platform/* ------> platform-core :8000
   `-- /api/employees/* -----> employees :8000

platform-core ----┐
employees ---------+--> PostgreSQL (one physical DB, isolated schemas/users)
                   +--> Redis
                   `--> RabbitMQ (asynchronous integration in subsequent slices)
```

Backend services are independently buildable containers. They never read another service's tables directly.

## Data ownership

- `platform_core` schema → Platform Core, DB user `platform_core_app`;
- `employees` schema → Employees, DB user `employees_app`.

The shared PostgreSQL instance is an implementation simplification for the first stage, not shared domain ownership. A service can later be moved to a separate PostgreSQL instance by changing its connection configuration.

## Frontend

`apps/web` is a common Vue shell responsible for global navigation, platform-level UX and composition of business services. Business logic and source-of-truth data remain in backend services.

## Current iteration

Iteration 1 establishes the runtime platform and Employees as the reference service. The current UI is intentionally a technical shell with live backend health. Employee domain screens and persistence are the next vertical slices on this foundation.
