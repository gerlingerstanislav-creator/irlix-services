# Employees implementation notes

The working Employees slice is deployed to the internal stand and evolves together with the business specification in `ideas`.

## Implemented

- department and employee persistence in the `employees` PostgreSQL schema;
- employee reference-data API and backend validation;
- organization structure with seeded baseline departments and collapsible tree UI;
- compact employee registry with search/filtering;
- HR-oriented new employee flow;
- employee side drawer with `Инфо`, `ТУ`, `Зарплаты` tabs;
- per-attribute inline editing in employee info: pencil appears on row hover and edits one field at a time;
- historical employment/cooperation periods;
- explicit lifecycle actions: dismiss, rehire, change cooperation type;
- employee status history;
- department/position assignment history;
- salary history as Employees-owned source data with a single-active-salary invariant;
- five seeded test employees in different departments with different cooperation types and salary histories;
- automatic database migrations during stand deployment;
- CI verification through the stand Nginx.

## Employee creation

The current HR creation flow requires first name, last name, gender, login, personal email, hire date, cooperation type and department. The work email is derived as `<login>@irlix.ru` and the employee is created with status `Трудоустроен` together with the first employment-period record, first status-history record and first assignment-history record.

The employee row includes `onboarding_email_status`. Until the onboarding email template/transport is configured, a newly created or rehired employee is marked `pending_template`; the application must not claim that an email was actually delivered.

## Employee card

The card opens as a right-side drawer over the employee list. Current tabs are `Инфо`, `ТУ`, `Зарплаты`; placeholder Roles/Notes tabs were removed until their business rules are defined.

Information attributes are edited individually. The read-only row reveals a pencil only on hover. Clicking it turns that single value into an input/select/date control; the PATCH endpoint updates only the supplied editable attribute.

Lifecycle-owned values (`employment_status`, `cooperation_type`, `hired_at`, `fired_at`) are not changed through generic attribute editing.

## Lifecycle

`employment_periods` stores cooperation periods. A partial unique PostgreSQL index guarantees at most one open period (`ended_at IS NULL`) per employee.

Dismissal requires a date, closes the open employment period, changes the current status to `Уволен`, appends status history and closes the open assignment record.

Rehire reuses the existing employee entity, creates a new open employment period and status record, opens a new assignment and restores status `Трудоустроен`.

Changing cooperation type requires an effective date. The previous employment period closes the day before that date and a new open period starts on the effective date.

Changing department or position does not create a new employment period. Instead, `employment_assignment_history` closes the current assignment and opens a new one while employment remains continuous.

## Salary history

`salary_history` stores dated gross salary/bonus records and is the source of truth for compensation history. It now has `effective_to`.

Only one salary record may be active (`effective_to IS NULL`) for an employee. This is enforced by a partial unique PostgreSQL index. Creating a new salary automatically closes the previous active record on the day before the new salary starts and marks the new record active.

## Organization structure

Department fields include hierarchy, name/alias, manager, HR, direct employee count, Yandex infrastructure ID, LDAP/Keycloak mapping and production flag. LDAP mapping remains data only until provisioning rules are confirmed.

The department table uses compact row spacing. Parent nodes with children can be collapsed/expanded from the chevron at the left.

## Shared UI

Employees uses `@irlix/ui`. The application shell follows the legacy-service visual reference with a compact icon-only sidebar. Hover labels are rendered as a separate overlay aligned to menu icons, and hovering the company logo does not reveal the section labels. Clicking the company logo opens the service launcher; clicking outside closes it.

## Deferred

- actual onboarding email template and mail transport;
- LDAP/Keycloak provisioning/group synchronization;
- permissions/scopes;
- RabbitMQ domain events and audit trail;
- compensation/FOT workflow beyond actual salary history;
- future Roles/Notes business logic, if these sections are reintroduced.
