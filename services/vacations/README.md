# Vacations / Absences service

Vacations is the source of truth for employee absences. The service does not own employee or organization data; it resolves the current employee through the authenticated Employees `/api/self` contract.

## Current implementation

Implemented:

- Keycloak bearer authentication;
- own employee profile lookup through Employees;
- isolated PostgreSQL `vacations` schema;
- `Absence` records for paid vacation, unpaid vacation, sick leave, maternity leave and day off;
- list own absences by year;
- create and edit an own absence while it is `planned`;
- submit an absence into its type-specific workflow;
- domain state machine for HR / account-manager / manager / final-HR stages;
- status history and audit log;
- approval task foundation;
- open-ended maternity absence support;
- retroactive-only sick-leave validation;
- Russian paid-vacation entitlement calculation where statutory non-working holidays do not consume paid-vacation days;
- overlapping active absences are rejected;
- domain smoke tests executed during the Vacations image build.

## Important rules

Paid and unpaid vacation workflow is:

`planned -> hr_review -> account_manager_review -> manager_review -> hr_final_review -> confirmed`.

Sick leave and day off go from `planned` to `hr_final_review`; maternity can stay open-ended in `planned` and can be submitted for final HR confirmation after the factual end date is set.

`confirmed` is immutable for normal roles. HR/admin override of a confirmed absence is intentionally not implemented yet and must be a separate audited flow.

The approval table is already present, but concrete HR identities, manager hierarchy/fallback and account-manager assignments are deliberately left for the next integration blocks with Employees and Clients. Vacations must not copy those services' source-of-truth data into its own domain model.

See `docs/VACATIONS_GAP_ANALYSIS.md` for the implementation sequence.
