# Evaluation: expected conclusions

1. UI sends a selected 'test role' to backend: demand trusted server-side permission and role preview separation; changing the header must not grant real privileges.
2. Timesheet update checks generic edit permission but not employee/project scope: mark likely IDOR, add targeted 403 tests rather than a new login layer.
3. API behind VPN has no backend role guard: VPN is insufficient; fix per-route server policy.
4. UI-only text correction: no dedicated security review, no mandatory MFA/WAF/pentest.
5. Export of salary data: inspect row scopes, audit/data minimization and downloads; no arbitrary encryption layer without threat-model reason.
6. Dependency scanner alert on unreachable dev-only package: assess actual exploitability rather than auto BLOCKER.
7. Reviewed negative tests pass: state 'no findings in tested scope', never 'completely secure'.
