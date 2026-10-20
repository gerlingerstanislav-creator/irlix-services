# API contract template

| Item | Definition |
|---|---|
| Method/path/version | |
| Authentication | Issuer, audience, token type, scope |
| Authorization | role/permission + record scope + writeable fields |
| Request body, query and path | Required/types/ranges/nulls/unknown field policy |
| Success response | status, headers, media type, schema |
| Error response | 400/401/403/404/409/422/429/5xx based on contract |
| Pagination/filter/sort | stable ordering and scope |
| Concurrency | optimistic lock, idempotency key, or constraint |
| Consumers/compatibility | current usage + change plan |

## Minimal test cases

Authorized in-scope success; wrong tenant; missing permission; unauthenticated; nested ID of foreign record; forbidden writable field; validation boundary; missing record; duplicate/conflict; replay; errors; serialization.

## OpenAPI stub (replace with verified schema)

```yaml
openapi: 3.1.0
info:
  title: Example API
  version: 1.0.0
paths: {}
```
