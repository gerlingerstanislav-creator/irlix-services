# Vacations / Absences service

Vacations is the source of truth for employee absences. The service does not own employee or organization data; it resolves employee identity, HR assignment and management hierarchy through Employees.

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
- approval tasks with assigned HR and manager identities from Employees;
- manager visibility inherited through the whole managed department subtree;
- higher-level managers can act on absences from child departments;
- manager fallback at submission time: if the nearest manager has a confirmed overlapping absence, the next manager in the Employees hierarchy is selected;
- HR and managers can return an in-progress absence to `planned` within their scope;
- `confirmed` remains immutable for normal roles;
- open-ended maternity absence support;
- retroactive-only sick-leave validation;
- Russian paid-vacation entitlement calculation where statutory non-working holidays do not consume paid-vacation days;
- overlapping active absences are rejected;
- domain smoke tests executed during the Vacations image build.

## Employees integration

Employees remains the source of truth for organization data. Vacations consumes authenticated Employees contracts for:

- the current employee;
- current permission + scope information;
- the HR specialist assigned to the employee's department or nearest ancestor department;
- the management chain from the employee's department upward;
- permission checks for a manager acting on any employee in a child department subtree.

The manager selected for a paid/unpaid vacation is snapshotted into the approval task when the employee submits the absence. A manager is considered unavailable for fallback only when Vacations already has a `confirmed` absence overlapping the requested period. If the nearest manager is unavailable, the next manager from the Employees hierarchy is selected.

## Important rules

Paid and unpaid vacation workflow is:

`planned -> hr_review -> account_manager_review -> manager_review -> hr_final_review -> confirmed`.

The first and final HR tasks are assigned from Employees. The manager task is also assigned from Employees with vacation fallback. The account-manager task deliberately remains unresolved until the Clients service provides the employee's active project/account-manager assignments.

Sick leave and day off go from `planned` directly to `hr_final_review`; maternity can stay open-ended in `planned` and can be submitted for final HR confirmation after the factual end date is set.

`confirmed` is immutable for normal roles. HR/admin override of a confirmed absence is intentionally not implemented yet and must be a separate audited flow.

See `docs/VACATIONS_GAP_ANALYSIS.md` and `services/vacations/IMPLEMENTATION_PLAN.md` for the implementation sequence.
