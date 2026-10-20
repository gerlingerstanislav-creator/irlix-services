# Operations, CI/CD, Docker/Nginx/Linux and observability

## Safety by default

Follow project runbooks, deployment topology, branch policy and existing release process. Before any action affecting production or persistent data, require explicit approval and verified target environment. Never assume a working deployment because a command returned an exit code: verify health, logs and targeted smoke checks.

- Docker: pin/track base images, non-root execution where possible, multi-stage builds when useful, `.dockerignore`, explicit immutable artifact, sane PHP-FPM worker memory limits. Compose `depends_on: service_healthy` only proves dependency's healthcheck, not application readiness.
- Nginx: proxy/PHP-FPM correct `try_files`, deny dotfiles/secret endpoints, request size/timeouts, headers and access logs with safe redaction, trusted proxy headers where appropriate.
- Secrets: no secrets in images, git, CI logs; prefer managed secrets/Compose secrets where supported, least privilege and rotation. Distinct staging/test/prod config and secure backups.
- CI: minimum required permissions; lock and verify dependencies; pin actions or use verified dependency lockfiles; avoid running untrusted fork code with write tokens; gate format, PHP lint, static analysis, unit/feature/contract, migration dry-run or staging tests, security dependency scans. Build once, deploy identical artifact, capture traceable version.
- Zero-downtime: coordinate Nginx/PHP-FPM workers, queue worker restart after code release, scheduler leader, backwards-compatible schema and queued payloads during mixed-version window.
- Monitoring: API rate/error/p95, memory/CPU, DB locks and lag, queue age/retry/DLQ, outbox lag, auth denies, integration timeouts and deploy change marker. Alerts require actionability.

## Release record

Target/environment, reviewed commit/artifact checksum, migrations and order, feature flags, test evidence, backup/recovery, success criteria, smoke endpoints, rollback/forward-fix decision, owners and observed monitoring. If no access, return a release checklist rather than claiming success.
