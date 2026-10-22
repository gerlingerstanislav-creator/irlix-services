# Targeted AppSec review checklist

Apply **only** relevant controls:
- Trust boundary: who is the actor, what data is sensitive, is the endpoint public or internal? Internal VPN does not replace ownership authorization.
- Authorization: 401/403, permission and scope/ownership on list/detail/update/export/bulk actions, negative tests for other owner's record. Can a client header or query field assert a privileged role?
- Identity: token signature, issuer/audience/expiry, OIDC client types, logout and redirects where code has changed.
- Inputs: SQL parameterization, server validation and mass assignment, output HTML escaping, command injection, file path/type/size, SSRF and webhooks.
- Browser: CSRF based on cookie authentication, CORS and security headers according to deployment, not generic rituals.
- Secrets and data: safe logs, access to HR/finance, export limits, storage of tokens and credentials, CI permissions.
- Runtime: public entry point, TLS, least privilege, library risk based on reachable use.
- Evidence: identify exact endpoint/files, safe reproduction steps, proven vs suspected controls; use synthetic data.
- Escalate to deeper audit only for meaningful exposure, high impact or known incidents. Prefer two to five decisive negative tests.
