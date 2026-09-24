# Architecture

## Repository layout

```text
apps/
  web/                  # Vue platform shell / Employees frontend for current iteration
  design-system/        # standalone UI catalogue for all services
packages/
  ui/                   # shared design tokens and Vue components
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
   |-- /design-system/* -----> design-system :80
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

`apps/web` currently contains the common shell and Employees UI. Global reusable visual primitives live in `packages/ui`.

`apps/design-system` is a separate static frontend application. It imports `packages/ui` and acts as the live catalogue/reference for the entire platform. It is not part of Employees and has no backend/domain ownership. Navigation to it is provided by the global service launcher.

As additional business frontends appear, the shell and shared patterns can be extracted further without moving business data ownership.

## Current iteration

Iteration 1 establishes the runtime platform and Employees as the reference service. Employees already includes the employee registry, organization structure, employee drawer, lifecycle/history and salary history. Design System is deployed independently at `/design-system/`.
