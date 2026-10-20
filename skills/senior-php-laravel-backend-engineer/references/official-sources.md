# Primary references (checked 2026-10-20)

Prefer the appropriate installed-version docs. Current docs may describe APIs not in older Laravel/framework/client drivers. Official material supports decisions; don't paste entire manuals into code review.

- Agent Skills open specification: https://agentskills.io/specification
- OpenAI ChatGPT Skills install: https://help.openai.com/en/articles/20001066-skills-in-chatgpt
- PHP supported versions: https://www.php.net/supported-versions.php ; PHP language/manual: https://www.php.net/manual/en/
- Laravel documentation: https://laravel.com/docs ; e.g. https://laravel.com/docs/13.x/authorization , https://laravel.com/docs/13.x/queues , https://laravel.com/docs/13.x/database
- PostgreSQL current: https://www.postgresql.org/docs/current/ ; indexes https://www.postgresql.org/docs/current/sql-createindex.html ; isolation https://www.postgresql.org/docs/current/transaction-iso.html ; EXPLAIN https://www.postgresql.org/docs/current/using-explain.html
- RabbitMQ confirms/acknowledgements: https://www.rabbitmq.com/docs/confirms ; quorum: https://www.rabbitmq.com/docs/quorum-queues ; DLX: https://www.rabbitmq.com/docs/dlx
- Redis docs: https://redis.io/docs/latest/ ; locks: https://redis.io/docs/latest/develop/use/patterns/distributed-locks/
- Keycloak OIDC: https://www.keycloak.org/securing-apps/oidc-layers ; server docs https://www.keycloak.org/documentation
- OAuth2 RFC 6749, OAuth2 Security BCP RFC 9700, OIDC Core: https://openid.net/specs/openid-connect-core-1_0.html ; JWT RFC 7519 / JWT BCP RFC 8725
- OWASP API Top 10: https://owasp.org/API-Security/ ; API1/BOLA: https://api-security.owasp.org/editions/2023/en/0xa1-broken-object-level-authorization/
- OpenAPI Specification: https://spec.openapis.org/oas/latest.html ; HTTP Problem Details: https://www.rfc-editor.org/rfc/rfc9457.html
- Docker Compose dependencies https://docs.docker.com/compose/how-tos/startup-order/ ; secrets https://docs.docker.com/compose/how-tos/use-secrets/
- GitHub Actions secure use https://docs.github.com/en/actions/reference/security/secure-use

## Critical appraisal of published skills

External collections of 'senior engineer' instructions may be useful for routing and checklists; do not trust them as normative technology behavior, and never inherit their permission to run commands. Keep orchestration in SKILL.md and topic detail on demand. Version claims and security guarantees must be reconciled with primary docs and working project evidence. Examples are illustrative until tested in the target stack.
