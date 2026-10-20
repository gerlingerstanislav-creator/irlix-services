# QA coverage checklist (select relevant only)

## Before testing
- [ ] Scoped release/change and revision
- [ ] Read project QA policy, restrictions and test commands
- [ ] Requirements, sources, ambiguities, test oracle
- [ ] User roles, resource ownership, tenant boundary and object states
- [ ] Environment permission, data privacy, cleanup and fixtures
- [ ] Interfaces, events, persistence and dependent consumers mapped

## Scenario design
- [ ] Primary, alternative and exceptional paths
- [ ] Valid/invalid equivalence classes and actual domain boundaries
- [ ] Missing/null/empty/Unicode/duplicate/malformed data
- [ ] Decision table with impossible combinations documented
- [ ] Allowed and forbidden state transitions, repeat/replay
- [ ] Permissions on both UI and server including list/export/bulk
- [ ] Cross-user, cross-tenant, temporary grant/revoke, stale tab/token
- [ ] Concurrent writes, double-submit, retries, out-of-order events, timeouts
- [ ] Side effects, referential integrity, audit logs, rollbacks
- [ ] Caches, feature flags, old clients, migrations and schema compatibility
- [ ] Accessibility, responsive states and localization where applicable
- [ ] Negative and recovery assertions, not only positive outcome

## Execution and reporting
- [ ] Real command, exit code, revision/environment and evidence
- [ ] Distinguish code/test/environment failures
- [ ] Reproduce and minimize actual issues; no fictional bug reports
- [ ] Evidence redacted, test account scope and cleanup verified
- [ ] Coverage measured against rules/risks and explicit denominators
- [ ] Regression retest for adjacent components
- [ ] Clear quality signal and unresolved blockers
