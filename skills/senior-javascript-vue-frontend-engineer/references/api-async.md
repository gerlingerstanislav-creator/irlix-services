# REST/API, async operations and remote-state correctness

## API adapter contract

Use the established API client. Centralize base URL, authorization transport, URL encoding, timeouts/AbortSignals, standardized errors, request tracing (no secret data) and response normalization. Keep UI components unaware of raw transport internals when the codebase allows it. Check exact endpoint shape, status codes, pagination, rate limits, validation payloads, time zones, and 204 responses with the backend contract. Never assume every 2xx has JSON. `fetch()` does **not** reject on HTTP 4xx/5xx: inspect `response.ok`.

## Error taxonomy

| Case | UI behavior | Recovery |
| --- | --- | --- |
| Abort/stale | usually suppress obsolete result/error | new request owns state |
| 401 | established login/re-auth flow; don't loop | user/session renewal |
| 403 | permission denied; no fake success | explain access limits |
| 404/410 | deleted/not found state | return/list refresh |
| 409/412 | conflict/precondition | show conflict, refetch, reconcile draft |
| 422 | server-side field errors | map safely to controls |
| 429 | respect Retry-After and backoff | retry if safe |
| 5xx/network | safe error/try again | retry reads/idempotent writes as allowed |
| Partial bulk success | per-record status/results | targeted retry, never repeat successful destructive work |

Never expose stack traces, raw backend HTML or tokens to users. Log only redacted operation metadata as permitted.

## Races and lifecycle

- Define state machine: idle, loading, refreshing, ready, empty, error, mutation-pending; transitions, whether old data stays visible, and cancellation.
- For filters/route IDs, use AbortSignal plus latest-request generation guarding state assignments. Abort support varies by adapter and does not guarantee no stale completion; sequence ID/cache key ensures correctness.
- For writes, preserve user intent ordering, disable/reconcile duplicate actions, and account for 409. Optimistic writes require rollback/revalidation; never assume a rejected write succeeded.
- Protect unsaved draft against background polling/refetch. Keep normalized remote snapshot and draft separate, tracking dirty fields or a version when needed.
- Cache only with explicit key dimensions (identity, filters, sort, page, permissions, locale) and invalidation rules. Reset user-specific cache on logout/role change.
- Limit retry loops, distinguish retryable idempotent requests from destructive side effects. Avoid polling on hidden tabs when not necessary and clean timers on unmount.

## Testing matrix

Slow network; response B resolves before A; abort ignored; component unmount; route change during write; empty result; malformed payload; 204 No Content; 401 and 403; 409 conflict; 422 field errors; 429; 500; offline; double-click; partial bulk update; optimistic rollback. Assert final *visible* state and data, not only function invocation.

References: https://developer.mozilla.org/en-US/docs/Web/API/AbortController ; https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API ; https://router.vuejs.org/guide/advanced/data-fetching
