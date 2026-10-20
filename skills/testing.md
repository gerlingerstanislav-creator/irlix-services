# Test strategy: risk-based and reproducible

## Test pyramid (not quotas)

- Unit tests: deterministic value objects, policy predicates, business rules, failure classifier; mock external IO, not the behavior under test.
- Laravel feature: HTTP with middleware/auth/request/policy/resource, validation, 401/403/404, field restrictions, pagination and serialization. Avoid blanket `withoutMiddleware()` in authorization tests.
- Database integration: run on PostgreSQL when using PG-specific constraints, isolation, `ON CONFLICT`, partial indexes or `SKIP LOCKED`. SQLite can conceal Postgres differences.
- Messaging integration: real RabbitMQ broker for manual ack/redelivery/confirm/DLQ and Redis where used; unit tests of adapter alone cannot establish broker semantics.
- Contract: check OpenAPI against actual responses; include consumer version compatibility and serialization of event schemas.
- E2E: a few high-risk flows, not replacement for focused tests. PHPUnit or Pest per repository convention. Seed/factory cleanup and isolation.

## Minimum matrix per HTTP write

Authorized & in scope; unauthenticated; authorized role with wrong object scope; in scope but insufficient role/permission; invalid body & nested relation; duplicate/conflict; concurrent retry; forbidden writable field; error format; protected fields absent in response; unchanged legacy API contracts.

## Minimum matrix per event

New event; exact duplicate; concurrent duplicate; crash before DB commit; crash after DB commit but before ACK; broker reconnect; malformed/unsupported schema; transient retries with bounded limit; permanent DLQ; replay; event ordering changes; side effect count exactly once **at DB business state level**.

## Minimum matrix for DB changes

Old and new schemas during rollout, backfill partial resume, millions-row strategy when applicable, concurrent writer cases, rollback/forward fix, invalid historical rows, missing FK, isolation differences. Confirm zero rows updated vs expected, and preserve data.

## Run/evidence report

For each command document cwd/environment/command/exit code/key counters. Typical commands only if available: `composer validate --strict`, `vendor/bin/phpunit`, `php artisan test`, `vendor/bin/pest`, `vendor/bin/phpstan analyse`, `vendor/bin/pint --test`, `php -l <file>`, `php artisan route:list`. Do not confuse installed CLI with real integration availability. Never claim test pass based solely on code inspection. Use risk-driven targeted commands followed by broader gates when feasible.
