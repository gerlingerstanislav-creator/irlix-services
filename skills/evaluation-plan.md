# Skill evaluations / acceptance criteria

These are synthetic, project-neutral prompts. The expected behaviors are testable by reviewing generated solutions; a test harness without Laravel, PostgreSQL or RabbitMQ cannot independently prove deployed integration.

## Scenario A: API + PostgreSQL + access boundaries

Prompt: "Add `POST /api/v1/documents` and `PATCH /api/v1/documents/{id}` for two isolated organizational scopes. Users have create/update permissions, each document has an owner scope, and titles are 1–180 chars. PostgreSQL stores data. Existing clients must continue working."

Must output: FormRequest validation, policies for function+object/tenant, SQL FK/unique where appropriate, Request->Policy->Action->Resource design, nested input allowlist, routes and OpenAPI, 401/403/404/422/409 tests incl foreign ID and permission-only denials, deploy/migration note. Must **not** assume role alone ensures record access, use request tenant as trusted, or invent repository conventions.

## Scenario B: RabbitMQ + retries + duplicates

Prompt: "Orders are committed to PostgreSQL, then an event causes a downstream update via RabbitMQ. Broker connections can fail, a worker may crash after DB commit and before ACK; duplicate events occur. Design the pipeline and tests."

Must output: transactional outbox for durable guarantee (or explicit evidence-based weaker option), confirm-aware relay with restart/reclaim, manual ACK after atomic consumer mutation+dedupe marker, stable event identity, bounded delayed retries/DLQ and poison behavior, tests for crash windows and parallel duplicates. Must **not** equate `afterCommit` with outbox or `ShouldBeUnique` with durable dedupe.

## Scenario C: concurrent updates + query performance + auth

Prompt: "Two parallel updates can decrement inventory below zero and an admin dashboard is N+1 slow. Some IDs leak across tenants. Fix without breaking existing clients."

Must output: reproduce concurrency interleaving and unauthorized query; atomic conditional update or row lock + DB check/constraints, scoped authorization at query level, eager loading only where profiling supports, query counts/EXPLAIN baseline, regression tests for race and foreign tenant, safe release. Must **not** solve race with `exists` check, Redis lock alone or slow global DB isolation.

## Common pass criteria

- Accuracy (high): official API behavior matches installed versions, no fabricated execution.
- Security (critical): object/function/field/tenant checks and invalid-token path where applicable.
- Correctness (critical): transaction boundaries, uniqueness/locking, duplicate delivery under failure.
- Maintainability (high): minimal layers, compatible contracts and tests.
- Operability (high): retries/replay/DLQ, migration/release and observability where relevant.

Each scenario scored 0=missed, 1=partial, 2=clear and correct across five dimensions; any **critical** check below 2 blocks ready status. See `evals/results.md` for executed local checks versus manual rubric.
