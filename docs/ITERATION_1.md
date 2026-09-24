# Iteration 1

Goal: establish the deployable platform foundation and implement Employees as the first reference business service.

## Foundation

- monorepo structure;
- Docker Compose runtime;
- PostgreSQL, Redis and RabbitMQ;
- separate PostgreSQL schema/user per service;
- Vue platform shell;
- independent Laravel containers;
- CI build and automatic stand deployment.

## Employees slices

After foundation deployment:

1. Employee and Department persistence/migrations;
2. employee list API and UI;
3. department hierarchy API and UI;
4. employee create/edit baseline fields confirmed by specification;
5. event contracts and audit hooks;
6. identity/permissions integration only after open business rules are resolved.
