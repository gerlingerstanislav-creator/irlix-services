# Project discovery and impact analysis

Goal: infer behavior from the checkout instead of projecting a preferred architecture.

## Read-only inventory

1. Root instructions and architecture diagrams; branch status, package manifests/locks and CI workflows. Note conflicts and their precedence.
2. PHP version (`php -v` if runnable), composer platform PHP/extensions, exact Laravel major (`composer show laravel/framework` if installed), third-party queue/OIDC drivers, PostgreSQL extension assumptions and supported environments.
3. Trace concrete entrypoint to endpoint middleware, Request validator, policy/gate, controller, service/action, model/query, listener/job, persistence and outbound API. Search only affected feature.
4. Inspect `routes`, `database/migrations`, model casts/relationships/scopes, factories, feature tests, service providers, configuration defaults and failure handling. Check whether code supports old clients/older workers.
5. Inspect DB size/change windows **only if facts available**; do not infer data counts, migrations run or deployed state from file names.
6. Inventory release rules, branch protections, migration order, feature flags, rollout monitoring and test commands. Do not run destructive scripts, schema migrations or deploy as part of discovery.

## Discovery record

- Verified facts: `item / evidence (path + line or command) / version`.
- Assumptions needing validation, ranked by severity.
- Change surface: ingress; permissions; data; queue; external integrations; consumers; tests; observability.
- Risk: irreversible data effects, privilege/contract changes, lock exposure, heavy queries, retry duplication.
- Candidate implementation: smallest change compatible with existing idioms and rollback.

Do not read `.env`, private keys, secrets directories or credentials; redact any accidentally observed sensitive information.
