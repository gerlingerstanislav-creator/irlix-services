# Employees service

Employees is the source of truth for employees, organizational structure, staff positions and company-level special functional role assignments.

## Iteration 1 status

The service is implemented as an independent Laravel backend with its own Docker image, PostgreSQL credentials/schema and migrations. The Vue frontend is published under `/employees/`.

Implemented areas include:

- department hierarchy and organization management;
- platform-admin-only hard delete of an empty department, protected by an irreversible-action alert and the additional confirmation code `engineer`;
- employee registry and card;
- navigation from an organization department to the employee registry with the department filter preselected;
- URL-addressable Employees pages with direct-load and browser Back/Forward support;
- staff position catalog linked to organizational departments, with optional base salary and open/closed lifecycle;
- hard delete for unused staff positions and safe closure for positions that must remain in employee history;
- employee position assignment only from open staff positions; existing text positions are migrated into the catalog;
- employee creation and Keycloak provisioning;
- employee profile changes and identity synchronization;
- employment/cooperation periods;
- dismissal and rehire lifecycle;
- department/position assignment history;
- salary history;
- OIDC login and JWT/JWKS validation;
- Employees-owned permission + scope authorization;
- special functional roles stored in `employee_access_roles`;
- dedicated **Роли** UI for assigning/removing special roles;
- `platform-admin`, `personnel-officer` and `system-admin` special roles;
- administrator audit trail for sensitive mutations;
- transactional outbox and RabbitMQ employee domain events.

See `IMPLEMENTATION.md` for implementation details, `AUTHORIZATION.md` for the current access model, `AUDIT.md` for the audit contract and `EVENTS.md` for the RabbitMQ contract.

## Frontend routes

Employees sections are real browser routes, not only local Vue state:

- `/employees/` — Сотрудники;
- `/employees/departments` — Подразделения;
- `/employees/organization` — Орг. структура (`/employees/staff-positions` remains a supported alias);
- `/employees/roles` — Роли;
- `/employees/audit` — История действий.

Opening or refreshing any route must restore the corresponding section. Sidebar navigation updates browser history, and Back/Forward restores the matching section. Query parameters such as `department_id` remain attached to the employee registry route. The mandatory implementation rule for new frontend pages is documented in `apps/web/README.md`.

## Organization roles vs special roles

These concepts are intentionally separate.

- Department manager is derived from `departments.manager_id`.
- Directional HR is assigned through `departments.hr_id` and remains part of the organization model.
- Membership in the HR department is also an organization-derived access characteristic.
- Employee position is selected from `staff_positions`; it does not grant access by itself.
- **Кадровик** is the special functional role `personnel-officer`; it is independent from department, position and directional HR assignment.
- **Системный администратор** is the special functional role `system-admin`.
- **Администратор платформы** is the special functional role `platform-admin`.

An employee can simultaneously belong to an ordinary department, be a directional HR or manager, and have one or more special functional roles.

The bootstrap account `admin` is represented by a normal Employees record with login `admin` and an explicit `platform-admin` assignment. On the first authenticated request Employees binds that record to the Keycloak `sub` by matching the unique login. This keeps `/self` and cross-service integrations identical for administrators and ordinary employees.

The bootstrap `admin` record cannot be deleted or dismissed, its login cannot be changed, and its `platform-admin` assignment cannot be removed. The API enforces these safeguards and the UI hides those actions.

Vacations integration receives both organizational `hr_approver` and the independent `personnel_officers` list in the absence approval context. The consumer must use the role appropriate to the workflow stage instead of treating HR and personnel officers as synonyms.

## Staff positions

`staff_positions` is the source of truth for assignable employee positions. Each entry has a unique name, a required organizational department (`direction_id`, retained as the compatibility field name), an optional `base_salary` in RUB and lifecycle field `closed_at`. Base salary is a staffing reference value and does not replace the employee-specific salary history.

Any existing department can own a position, including non-production departments. `direction_id` is NOT NULL and retains its existing restrictive foreign key to departments. Legacy positions are linked automatically only when current/history department references agree; unresolved positions abort migration and must be assigned explicitly. Ordinary seeded positions are preserved. The erroneous bootstrap label `Platform Administrator` / `PlatformAdministrator` is removed from the position catalog and cleared from current/history position fields. The bootstrap employee, employment records and global `platform-admin` grants remain intact. API validation and a database constraint prevent recreating that global role as a staff position.

Existing non-empty legacy `employees.position` values are migrated into the catalog and linked through `employees.position_id`. The current text `employees.position` remains as a compatibility/snapshot field for existing history and downstream contracts; new employee mutations must provide `position_id`, and Employees resolves the canonical position name from the catalog.

An open position can be assigned to employees. Closing a position sets `closed_at` and keeps current employee data and historical `employment_periods.position` values intact, but middleware rejects the position for every new assignment or rehire. The UI also excludes closed positions from employee assignment selectors.

A position may be hard-deleted only when it is not currently assigned to an employee and does not occur in employment history. If it is referenced, the API returns `409` and the administrator must close it instead. Closing and hard delete are restricted to `platform-admin` and are recorded in the audit trail.

The **Орг. структура** UI is available to `platform-admin`; creation and editing of staff positions are also protected on the backend. Salary-sensitive values are returned only to callers with salary-read permission.

## Department hard delete

A full department delete is deliberately separate from ordinary organization editing.

- Only `platform-admin` can execute it; the backend checks the role independently of UI visibility.
- The UI first shows an irreversible-action confirmation and then asks for the control code.
- The backend accepts the operation only when `confirmation_code` is exactly `engineer`.
- A department can be hard-deleted only when it has no directly assigned employees, no child departments and no staff positions linked to it as a direction. Otherwise the API returns `409`; dependencies must be moved first.
- The operation is recorded in the Employees audit trail.

## API exposure

The Employees backend is available internally as `/api/*`. On the stand Nginx publishes it under `/api/employees/*`.

Core endpoints include:

- `/api/health`;
- `/api/reference-data`;
- `/api/departments`;
- `DELETE /api/departments/{id}` for platform-admin hard delete with confirmation code;
- `/api/staff-positions` and `PUT /api/staff-positions/{id}`;
- `POST /api/staff-positions/{id}/close`;
- `DELETE /api/staff-positions/{id}` for hard delete of an unused position;
- `/api/employees`;
- `/api/employees/{id}` and employee lifecycle/history endpoints;
- `/api/access/me`;
- `/api/access/roles`;
- `PUT|DELETE /api/access/roles/{role}/{employee}`;
- `/api/audit` for full administrators.

## Authorization summary

Keycloak authenticates the caller. Employees determines business access from the linked employee and its functional/organizational roles.

First-iteration visibility:

- department manager — managed department plus all descendants, including salary data;
- Finance subtree — all employees and salary data;
- HR subtree — all employee data except salary data;
- ordinary employee — no Employees UI access;
- explicit `platform-admin` — full access regardless of organization position, including staff-position management and department hard delete;
- `personnel-officer` and `system-admin` — functional markers used by business services; by themselves they do not grant Employees UI, salary or organization mutation access.

There is no separate `company-admin` role. `platform-admin` is the single full-administrator role used by Employees and consuming services.

Special roles are managed only by callers with `access.manage`. Assignment changes are audited.

Write access for HR, Finance and managers is deliberately not granted yet because the business rules for mutations have not been confirmed. Audit-log access is restricted to full administrators.

## Audit and events

Every supported sensitive mutation is recorded in `audit_log` with actor, target, result and before/after snapshots. This includes department create/update/hard delete, staff-position create/update/close/hard delete, employee mutations and special-role assignment/removal. The Employees UI exposes an **История действий** section to callers with `audit.read`.

Cross-service events use PostgreSQL `outbox_events` plus the separate `employees-events` publisher. Source-of-truth changes and outbox events are committed transactionally; RabbitMQ outages do not make the Employees HTTP service unavailable. Events are published to durable topic exchange `irlix.events` with at-least-once semantics and stable `event_id` for consumer deduplication.

## Deferred

- final SMTP/onboarding delivery verification on the stand;
- write-permission matrix for HR, Finance and managers;
- employee self-service profile outside Employees;
- least-privilege Keycloak provisioning service account;
- production Keycloak database/configuration;
- audit retention/archival policy;
- concrete consumer queues/DLX policies as downstream services are implemented;
- compensation/FOT workflow beyond staff-position base salary and actual employee salary history.

## Organization tree and department cards

The organization page mirrors the Departments table, adding a clickable count of positions directly owned by each department. The shared +/− controls expand only child departments; positions are never inserted into the common tree. Departments remains a separate page. The table has internal scrolling and a sticky header. Seeded positions remain present, as requested.

A department name opens a compact right-side UiDrawer with shared tabs **Инфо** and **Должности**. The position count opens the same drawer directly on **Должности**. Fields on **Инфо** use compact label/value/pencil rows without horizontal separators, like the employee card, with independent save/cancel controls. Cancellation sends no mutation. Read-only callers have no mutation controls.

**Должности** lists only that department’s own positions (including closed catalog entries), with name, base salary where salary access permits, and active employee count. An empty department shows an explicit empty state; long lists scroll inside the card. The drawer header contains the entity name. Clicking a position name opens a second, smaller view/edit card above the preserved department card. Closing it restores the parent with its tab, scroll and resized width. Department/position drawers start at 40vw/30vw and remain resizable (full width on mobile). The position card has no tabs or back button. It shows editable name, department and base salary (nullable, non-negative), plus read-only status, closure date, active employee count, ID and created/updated timestamps. Department tabs remain unchanged. Both cards retain compact per-field editors. Position rows and the position card expose separate administrator-only Delete and Close buttons. Deletion uses native window.confirm. Closing calls the existing lifecycle endpoint, preserves current employees/history and prevents new assignments; the Close button is disabled once closed. Cancellation sends no request; successful deletion refreshes both the catalog and position count. A server `409` keeps the row and displays the reason (current employee or employment history references). The existing permission and audit rules remain in force. The positions table uses fixed layout and wrapping, without horizontal scrolling. **+ Должность** inside the tab creates a position with this department preselected; its form opens above the department card. Adding a position opens the required department selector and returns to this tab after creation. Counts navigate through SPA to employees filtered by department, position and `Трудоустроен`; URLs and Back/Forward retain filters. No salary/status/action columns appear in the common organization table.

Creation, inline editing and rehire enable position selection only after choosing a department, and offer only open positions directly owned by that department (not descendants). Changing the employee department resets the current position. The backend enforces department membership on assignment, uses the existing employee department for position-only PATCH, and clears the position on a department-only change; assignment history records the cleared value correctly. Position choice remains optional.



The New Employee form uses the shared searchable `UiSearchSelect` with the full department hierarchy sorted by branch and indented by depth. Every department remains selectable, including non-production parents; choosing or changing it retains the existing department-scoped position rules and resets the previous position. The submit handler validates the required department as well as disabling submit until it is selected.

All employee selectors in Employees (department manager/HR in both organization and department forms, special-role assignment) share department-grouped tree options. Department headings are not selectable. Nested group depth and ancestor-preserving search are provided by UiSearchSelect. Each screen retains its employee eligibility filter; unlinked employees appear under “Без подразделения”.
