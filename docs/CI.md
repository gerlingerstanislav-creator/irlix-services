# CI

Pull requests to `main` validate Docker Compose and build the complete stack. Pushes to `main` repeat validation and then deploy the stack to the stand over SSH, followed by HTTP health checks.

## Current performance

At the current project size a full run is roughly 1.5–2 minutes. This is not yet a bottleneck worth trading reliability for.

The main avoidable deployment cost was unconditional recreation of every container on every push. Deploy should therefore use `docker compose up -d --build --remove-orphans` without `--force-recreate`: unchanged infrastructure containers (PostgreSQL, Redis, RabbitMQ, Keycloak) should stay running unless their configuration/image actually changes.

We intentionally keep the full stack build in the validation job for now. Removing it would make CI faster but move build failures into deployment, which conflicts with the requirement that `main` remain deployable.

Revisit CI caching/parallel build optimization when either:

- normal CI regularly exceeds ~3–5 minutes;
- the number of application images grows enough that duplicate build time materially slows feedback;
- GitHub Actions cost becomes relevant.

Likely next optimizations at that point: BuildKit/buildx layer cache persisted in GitHub Actions, separate frontend/backend image build jobs, and deploying prebuilt images instead of rebuilding them on the stand.
