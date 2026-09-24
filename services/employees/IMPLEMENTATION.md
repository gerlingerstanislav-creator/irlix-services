# Employees implementation notes

The working Employees slice is deployed to the internal stand and evolves together with the business specification in `ideas`.

## Implemented

- department and employee persistence in the `employees` PostgreSQL schema;
- employee reference-data API and backend validation;
- organization structure with seeded baseline departments and collapsible tree UI;
- compact employee registry with search/filtering;
- HR-oriented new employee flow;
- automatic login generation from Russian first/last name with live uniqueness checking and alternatives;
- employee side drawer with `Инфо`, `ТУ`, `Зарплаты` tabs;
- per-attribute inline editing in employee info: pencil appears on row hover and edits one field at a time;
- historical employment/cooperation periods;
- explicit lifecycle actions: dismiss, rehire, change cooperation type;
- employee status history;
- department/position assignment history;
- salary history as Employees-owned source data with a single-active-salary invariant;
- Keycloak realm for corporate identity;
- employee → Keycloak identity provisioning on hire;
- temporary password with required password change on first login;
- Keycloak account disable on dismissal and re-enable on rehire;
- Keycloak group synchronization on department changes;
- Keycloak username/email synchronization when employee login changes;
- five seeded test employees in different departments with different cooperation types and salary histories;
- automatic database migrations during stand deployment;
- CI verification through the stand Nginx.

## Employee creation

The current HR creation flow requires first name, last name, gender, login, personal email, hire date, cooperation type and department.

The UI transliterates Russian first/last name and proposes `firstname.lastname`. Until HR edits the login manually, changing the name recalculates the proposal. Employees API checks uniqueness and returns alternative forms such as `lastname.firstname` and `f.lastname`; it never appends numeric suffixes automatically.

The work email is derived as `<login>@irlix.ru`. It is not an independent source field. The employee is created with status `Трудоустроен` together with the first employment-period record, first status-history record and first assignment-history record.

After the database employee record is created, Employees provisions a Keycloak user. Successful provisioning stores `keycloak_user_id`, sets `identity_status=active`, creates a temporary password and configures the required `UPDATE_PASSWORD` action. The temporary password is returned only in the immediate create response and is not persisted in Employees.

The employee row includes `onboarding_email_status`. Until the onboarding email template/transport is configured, a newly created or rehired employee is marked `pending_template` only after identity provisioning. If provisioning fails, the employee remains stored with `identity_status=provisioning_failed` and onboarding remains pending identity recovery.

## Employee card

The card opens as a right-side drawer over the employee list. Current tabs are `Инфо`, `ТУ`, `Зарплаты`; placeholder Roles/Notes tabs were removed until their business rules are defined.

Information attributes are edited individually. The read-only row reveals a pencil only on hover. Clicking it turns that single value into an input/select/date control; the PATCH endpoint updates only the supplied editable attribute.

`login` is editable. When it changes, Employees also changes the derived work email and synchronizes username/email in Keycloak. `work_email` and `identity_status` are displayed as technical/current values rather than independently editable fields.

Lifecycle-owned values (`employment_status`, `cooperation_type`, `hired_at`, `fired_at`) are not changed through generic attribute editing.

## Identity / Keycloak

The stand runs a dedicated Keycloak container and imports realm `irlix`. Realm configuration enables login by either username or email. Employee username is the Employees `login`; employee Keycloak email is always `<login>@irlix.ru`.

Departments with `ldap_group` map to Keycloak groups of the same technical name. Provisioning assigns the current department group. Moving an employee removes known department groups and assigns the new mapped group. Departments without `ldap_group` do not assign a dedicated group.

Dismissal disables the Keycloak user rather than deleting it. Rehire enables the same identity and synchronizes its department group.

Current stand provisioning authenticates to Keycloak through bootstrap admin credentials supplied by environment variables. This is an implementation-stage mechanism only; production must replace it with a least-privilege service account. Secrets are not committed.

Keycloak currently persists to its mounted dev-mode data volume. Before production, move Keycloak persistence to PostgreSQL and switch from `start-dev` to production configuration.

## Lifecycle

`employment_periods` stores cooperation periods. A partial unique PostgreSQL index guarantees at most one open period (`ended_at IS NULL`) per employee.

Dismissal requires a date, closes the open employment period, changes the current status to `Уволен`, appends status history, closes the open assignment record and disables the Keycloak account.

Rehire reuses the existing employee entity, creates a new open employment period and status record, opens a new assignment, restores status `Трудоустроен` and re-enables the existing Keycloak account.

Changing cooperation type requires an effective date. The previous employment period closes the day before that date and a new open period starts on the effective date.

Changing department or position does not create a new employment period. Instead, `employment_assignment_history` closes the current assignment and opens a new one while employment remains continuous. Department changes also synchronize the mapped Keycloak group.

## Salary history

`salary_history` stores dated gross salary/bonus records and is the source of truth for compensation history. It has `effective_to`.

Only one salary record may be active (`effective_to IS NULL`) for an employee. This is enforced by a partial unique PostgreSQL index. Creating a new salary automatically closes the previous active record on the day before the new salary starts and marks the new record active.

## Organization structure

Department fields include hierarchy, name/alias, manager, HR, direct employee count, Yandex infrastructure ID, LDAP/Keycloak mapping and production flag.

The department table uses compact row spacing. Parent nodes with children can be collapsed/expanded from the chevron at the left.

## Shared UI

Employees uses `@irlix/ui`. The application shell follows the legacy-service visual reference with a compact icon-only sidebar. Hover labels are rendered as a separate overlay aligned to menu icons, and hovering the company logo does not reveal the section labels. Clicking the company logo opens the service launcher; clicking outside closes it.

Employees frontend is deployed under `/employees/`; the root `/` is the services portal. Design System is deployed separately at `/design-system/`.

## Deferred

- actual onboarding email template and mail transport;
- least-privilege Keycloak provisioning service account;
- production Keycloak database/configuration;
- application OIDC session/login integration and permissions/scopes;
- RabbitMQ domain events and audit trail;
- compensation/FOT workflow beyond actual salary history;
- future Roles/Notes business logic, if these sections are reintroduced.
