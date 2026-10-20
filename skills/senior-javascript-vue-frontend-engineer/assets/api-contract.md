# Frontend ↔ API contract — [operation]

**Method/path and version:**
**Authentication & backend permission:**
**Request:** params, headers (names, no secrets), query normalization, body, optional/null semantics, idempotency.
**Response:** 200/201/202/204 payloads and runtime schema; pagination, ETag/version; timestamps/timezone.
**Failure cases:** 400/401/403/404/409/412/422/429/5xx, network abort, timeout, partial results.
**UI mapping:** loading/ready/empty/error/forbidden/conflict, field errors, toast/no-toast, retry policy.
**Concurrency:** stale request control, mutation lock/reconciliation, cache key/invalidation.
**Contract test ownership:** frontend assertions and backend compatibility check, no unapproved backend modifications.
