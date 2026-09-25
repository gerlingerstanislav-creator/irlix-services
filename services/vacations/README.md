# Vacations / Absences service

Vacations is the source of truth for employee absences. The service does not own employee or organization data; it resolves the current employee through the authenticated Employees `/api/self` contract.

## First vertical slice

Implemented in the first iteration:

- Keycloak bearer authentication;
- own employee profile lookup through Employees;
- isolated PostgreSQL `vacations` schema;
- `Absence` records for paid vacation, unpaid vacation, sick leave, maternity leave and day off;
- list own absences by year;
- create an own planned absence;
- overlapping active absences are rejected.

`calendar_days` is currently an inclusive calendar interval only. It is **not** an entitlement/workday calculation. Accruals, paid-day limits, approval chains, HR management, documents and RabbitMQ lifecycle events are deliberately deferred until their business rules are confirmed.
