# CI

Pushes and pull requests to `main` use a path-aware, multi-job pipeline.

## Pipeline jobs

The main workflow is split into visible execution stages:

1. `Detect changes` — determines which runtime areas are affected.
2. `Build frontend` — builds affected frontend applications.
3. `Build backend` — builds affected backend services.
4. `Validate infrastructure` — validates Docker Compose and reports infrastructure/auth scope.
5. `Deploy affected services` — deploys only affected containers when possible; full infrastructure changes fall back to the complete stack.
6. `Bootstrap authentication` — runs Keycloak bootstrap only when auth/Keycloak/infrastructure requires it.
7. `Verify stand` — smoke-tests frontends, API health, OIDC discovery and anonymous protection of Employees endpoints.

Frontend and backend builds run in parallel after change detection.

## Change mapping

- Markdown-only changes and `docs/**` do not start the main CI workflow.
- `apps/web/**` affects Employees frontend.
- `apps/portal/**` affects Dashboard.
- `apps/design-system/**` affects Design System.
- `packages/ui/**` affects Employees frontend and Design System.
- `packages/auth/**` affects Dashboard and Employees frontend and marks auth as affected.
- `services/employees/**` affects Employees backend.
- `services/platform-core/**` affects Platform Core.
- `infra/keycloak/**` affects authentication infrastructure.
- `docker-compose.yml`, `.env.example`, general `infra/**`, and `.github/workflows/**` use the conservative full-pipeline scope.
- Unknown runtime paths deliberately fall back to full scope.

A manual `workflow_dispatch` also uses full scope.

## Deploy behavior

For scoped application changes the server runs `docker compose up -d --build` only for affected services. Employees migrations run only when Employees backend or the full stack is affected. Keycloak bootstrap is a separate job and is skipped when authentication is unchanged.

Infrastructure/full-scope changes still deploy the complete Compose stack. Containers are not force-recreated, so unchanged persistent infrastructure remains stable whenever Compose can reuse it.

## Verification

The stand verification remains mandatory after deployment. In addition to page and API health checks it verifies that the built Dashboard JavaScript asset is actually reachable; this prevents a static HTML shell from passing CI while its Vite bundle is inaccessible.

## Dedicated workflows

`Provision Keycloak Admin` is a small independent workflow for provisioning/rotating the master-realm console administrator from `KEYCLOAK_ADMIN_PWD`. It does not rebuild or redeploy the application stack.

## Further optimization

The next material optimization, if needed, is persistent BuildKit/GitHub Actions layer caching or publishing immutable images once in CI and deploying those images. We should not weaken smoke/auth/health verification merely to improve timing.
