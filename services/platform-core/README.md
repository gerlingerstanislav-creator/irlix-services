# Platform Core

Platform Core contains shared platform capabilities: identity integration, authentication, permission/scope evaluation, service catalog, common settings, notifications, audit and integration mechanisms.

## Iteration 1 status

The service is scaffolded as an independent Laravel backend with its own Docker image and isolated PostgreSQL credentials/schema.

Current endpoint:

- `GET /api/health` — checks service and database connectivity.

Business platform capabilities are added incrementally after the deployment foundation is stable.
