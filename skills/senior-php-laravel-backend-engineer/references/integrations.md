# External HTTP and interservice integrations

## Contract and trust boundaries

Discover authoritative source, data schema, auth (OAuth2 client credentials/mTLS etc), timeouts, quotas, redirect behavior, rate limits, pagination, 429/Retry-After and error semantics. Build an adapter only when needed for repeat use/testability. Validate response schema, identifiers, freshness and ownership before side effects.

- Timeout each connection/overall request; never wait indefinitely. Retry only network/transient 5xx/429 and only safe/idempotent calls or calls protected with idempotency key. Use exponential backoff + jitter and cap retry budget. Honor Retry-After when feasible.
- Distinguish transport failure, timeout/unknown outcome, upstream rejected request, malformed response and business conflict. Never silently translate every failure into empty data.
- Circuit breakers/bulkheads are optional when failure profiles justify complexity. Bound concurrency; avoid holding DB transaction during HTTP requests.
- For POST with ambiguous response and possible committed upstream side effect, use the upstream's idempotency key or status/reconciliation API before retrying.
- Treat external identifiers and token claims as untrusted; avoid SSRF by allowlisting destinations, rejecting unexpected redirects/schemes/hosts, using safe DNS/IP policies where needed.
- Log request IDs, latency, response class and attempt counters, but strip Authorization/Cookie/Payload secrets.
- Contract tests: success, 401/403, 404, 409, 422, 429, timeout, 5xx, malformed JSON, non-JSON, empty body, partial data, duplicate callbacks, delayed/reordered notifications.

Documentation: integration mapping, failure matrix, timeouts, retry budgets, backfill/reconciliation procedures, owners and rollout order.
