# CI

Pushes and pull requests to `main` use a path-aware pipeline.

## What runs

- Markdown-only changes and `docs/**` do not start the main CI workflow.
- Infrastructure changes (`docker-compose.yml`, `.env.example`, `infra/**`, `.github/workflows/**`) run a full build.
- `apps/web/**` builds Employees frontend only.
- `apps/portal/**` builds Dashboard only.
- `apps/design-system/**` builds Design System only.
- `packages/ui/**` rebuilds Employees frontend and Design System.
- `packages/auth/**` rebuilds Dashboard and Employees frontend.
- `services/employees/**` builds Employees backend only.
- `services/platform-core/**` builds Platform Core only.
- Unknown runtime paths deliberately fall back to a full build.

A manual `workflow_dispatch` always performs a full validation build.

Pushes to `main` still deploy the resulting repository state to the stand, run migrations, bootstrap Keycloak and execute stand verification. Server-side Docker cache prevents unchanged images from being rebuilt from scratch or containers from being force-recreated.

## Why

The repository follows the rule “one logical/business task — one commit”. CI must not make that rule expensive. Documentation commits now cost no CI time, and normal code changes validate only the runtime components they can affect.

We still keep conservative full validation for infrastructure changes, because routing, Compose or identity configuration can affect the complete stack.

## Dedicated workflows

`Provision Keycloak Admin` is a small independent workflow for provisioning/rotating the master-realm console administrator from `KEYCLOAK_ADMIN_PWD`. It does not rebuild or redeploy the application stack.

## Further optimization

If deploy feedback becomes a bottleneck again, the next step is to make deploy itself path-aware and/or publish prebuilt images from CI, then deploy immutable images. Persistent BuildKit/GitHub Actions cache is also a candidate. We should not remove health/authorization verification merely to improve timing.
