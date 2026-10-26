# Timesheets Laravel backend — architectural refactor (2026-10-26)

Status: implementation in feature branch; acceptance requires green CI and integration into `development`. Production requires separate authorization.

## Contract and layer ownership

- `routes/api.php`, `routes/partner-hours.php`, `routes/attention.php`, `routes/integrations.php` and `routes/system.php` are endpoint registrations only. Laravel API middleware and existing internal token checks remain in effect.
- `app/Http/Controllers`: validation, authentication boundary, HTTP status and exact JSON envelope.
- `app/Services/TimesheetsContext.php`: authoritative Clients MemberTerms projection; Employees organizational/permission scope; Vacations calendar; fail-closed dependency access; audit persistence. No cross-service SQL joins.
- `PersonalTimesheetsService`: workspace, employee daily entries and common daily preliminary confirmations.
- `ManagementTimesheetsService`: permission and organizational scope, manager writes, atomic bulk hours, final approval/exceptions.
- `PartnerAccessService` and `PartnerHoursService`: Clients-authorized operator scope, partner identity without fake employee ID, partner-specific confirmation and final-approval workflow.
- `AnalyticsTimesheetsService`, `AuditTrailService`, `AttentionService`: read projections and permission-safe aggregation; no business SQL in routes.
- `ReportingFreezeService`, `CashFlowFactsReader`, `CommercialData`: durable reporting freezes, revision consistency, immutable commercial snapshots. Finance has distinct trusted token.
- `EmployeePurgeService`: fail-closed protected financial facts and approval attribution before any purge.
- Eloquent models: `TimesheetEntry`, `EmployeeConfirmation`, `PartnerDailyConfirmation`, `FinalApproval`, `FinalApprovalException`, `TimesheetAudit`, `PeriodLock`, `ReportingFreezeRecord`. The latter has primary key `reporting_period_id` (not `id`). SQL-level advisory locks remain infrastructure helpers.

## Compatibility invariants

- ClientMember is the time owner, not project; Clients MemberTerms determine valid commercial periods.
- Employee preliminary confirmation is common across clients/day; final approval is per membership/month.
- Partner identity and scope are separate; AM/delegates see their own clients, team-manager scope cannot grant unrelated partner access.
- Upsert operations and audit belong to the same transaction; employee and partner daily advisory locks serialize the cross-client 24-hour limit.
- Historical numeric(5,2) hours are not rounded to quarters when untouched or descriptions change; zero with description persists.
- Client-sent reporting periods and local freeze block mutation, including backdated changes and purge.
- Raw Eloquent attributes are explicitly mapped for API arrays to avoid unexpected date/decimal/cast JSON changes.
- No DDL, data reset, legacy import, or migration cutover is executed in this refactor.

## Verification

`tests/architecture_smoke.php` fails on route closures carrying business endpoints, raw persistence in route files, direct SQL in HTTP controllers, and missing mandatory service models.

`tests/runtime_contract_smoke.php` dispatches Laravel routes against synthetic SQLite with fake external services, checking permission/scope, partner workflow, freeze/revision, 24-hour limit, and precision. `tests/project_conversion_smoke.php` verifies backwards-compatible conversion without running a production conversion.

For release readiness still require PostgreSQL integration/upgrade and concurrency checks, negative Keycloak auth, full API/JSON compatibility, Clients ↔ Timesheets ↔ Finance and deployment-specific read-only checks on the exact merge SHA. A green SQLite smoke **does not** imply those scenarios passed; label missing coverage `NOT TESTED`.

Source business decisions are maintained in private ideas; do not copy them into this public repository.
