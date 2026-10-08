# Platform Core

Platform Core contains shared platform capabilities: identity integration, authentication, permission/scope evaluation, service catalog, common settings, notifications, audit and integration mechanisms.

## Iteration 1 status

The service is scaffolded as an independent Laravel backend with its own Docker image and isolated PostgreSQL credentials/schema.

Current endpoint:

- `GET /api/health` — checks service and database connectivity.

Business platform capabilities are added incrementally after the deployment foundation is stable.

## Resource monitor

Platform Core serves the admin/system-admin resource API for the Dashboard `/resources/` tab. Docker/host collection runs in an isolated `resource-monitor` container; Platform Core only reads its private metrics volume. Effective authorization comes from Employees `/access/me`. See `../../docs/RESOURCE_MONITOR.md` for API, metric semantics, runtime and retention.
