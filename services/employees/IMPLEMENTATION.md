# Employees implementation notes

The working Employees slice is deployed to the internal stand and evolves together with the business specification in `ideas`.

## Implemented

- department and employee persistence in the `employees` PostgreSQL schema;
- organization structure with seeded baseline departments and collapsible tree UI;
- compact employee registry with search/filtering;
- HR-oriented new employee flow;
- automatic login generation from Russian first/last name with uniqueness checking;
- employee side drawer with `Инфо`, `ТУ`, `Зарплаты` tabs;
- per-attribute inline editing for full administrators;
- historical employment/cooperation periods;
- explicit lifecycle actions: dismiss, rehire, change cooperation type;
- employee status history;
- department/position assignment history;
- salary history with a single-active-salary invariant;
- Keycloak realm for corporate identity;
- employee → Keycloak identity provisioning on hire;
- temporary password with required password change on first login;
- Keycloak account disable on dismissal and re-enable on rehire;
- Keycloak group synchronization on department changes;
- Keycloak username/email synchronization when employee login changes;
- OIDC Authorization Code + PKCE login for Employees frontend;
- JWT/JWKS bearer validation;
- Employees-owned authorization using permissions and organizational scopes;
- explicit `company-admin` assignment independent of department/position;
- automatic database migrations during stand deployment;
- CI verification through the stand Nginx.

## Identity / Keycloak

The stand runs a dedicated Keycloak container and realm `irlix`. Employee username is the Employees `login`; employee Keycloak email is derived from `<login>@irlix.ru`.

Departments with `ldap_group` map to Keycloak groups. Provisioning assigns the current department group and department changes synchronize it.

Dismissal disables the Keycloak user instead of deleting it. Rehire re-enables the same identity.

Employees frontend uses OIDC Authorization Code + PKCE. API requests carry the current Keycloak access token. `KeycloakBearer` validates token structure, RS256 signature via JWKS, issuer, client, expiration and activation timestamps, then exposes the identity to the business authorization layer.

Authentication no longer implies business authorization. Normal Employees access is calculated by Employees itself. The temporary Keycloak `platform-admin` realm role remains only as a technical/bootstrap full-access path while the platform is being initialized.

Stand provisioning still authenticates to Keycloak through bootstrap admin credentials supplied by environment variables. Production must replace this with a least-privilege service account. Secrets are not committed.

Keycloak currently persists to its mounted dev-mode data volume. Before production, move Keycloak persistence to PostgreSQL and switch from `start-dev` to production configuration.

## Authorization

Detailed rules are documented in `AUTHORIZATION.md`.

Current first-iteration model:

- manager of a department: read employees and salary data for that department and every descendant recursively;
- Finance subtree: read all employees and all salary data;
- HR subtree: read all employees but no salary data;
- ordinary employee: no Employees access;
- explicit `company-admin`: full access independent of organizational position;
- temporary technical `platform-admin`: full bootstrap access.

Write permissions for managers, Finance and HR are intentionally not granted yet because only visibility rules are confirmed. Full administrators can currently perform employee, organization and salary mutations.

The backend is the security boundary. Lists are scope-filtered server-side, direct employee access outside the caller scope is rejected, and salary history is removed server-side when salary permission is absent. The frontend mirrors permissions only to improve UX.

## Employee creation

The current creation flow requires first name, last name, gender, login, personal email, hire date, cooperation type and department.

The UI transliterates Russian first/last name and proposes `firstname.lastname`. Employees API checks uniqueness and returns alternatives. The work email is derived as `<login>@irlix.ru`.

After the database record is created, Employees provisions a Keycloak user. Successful provisioning stores `keycloak_user_id`, sets `identity_status=active`, creates a temporary password and configures the required `UPDATE_PASSWORD` action. The temporary password is returned only in the immediate create response and is not persisted.

If provisioning fails, the employee remains stored with a failed identity status so the business entity is not lost.

## Employee card and lifecycle

The card opens as a right-side drawer over the employee list. Current tabs are `Инфо`, `ТУ`, `Зарплаты`; the salary tab is absent when the current caller does not have salary permission.

Lifecycle-owned values are changed through explicit actions rather than generic field editing.

`employment_periods` stores cooperation periods. A partial unique PostgreSQL index guarantees at most one open period per employee.

Dismissal closes the open employment period, changes status to `Уволен`, closes the open assignment and disables Keycloak identity.

Rehire reuses the employee entity, creates a new open employment period and assignment, restores status `Трудоустроен` and re-enables the identity.

Changing cooperation type closes the previous period one day before the effective date and opens a new period. Department/position changes use assignment history without breaking employment continuity.

## Salary history

`salary_history` stores dated gross salary and bonus records. Only one record may be active (`effective_to IS NULL`) for an employee. Creating a new salary closes the previous active record the day before the new effective date.

Salary visibility is a separate permission from general employee visibility.

## Organization structure

Department fields include hierarchy, name/alias, manager, HR, direct employee count, Yandex infrastructure ID, LDAP/Keycloak mapping and production flag.

Managers are identified from `departments.manager_id`. Their authorization scope is the recursive subtree of each department they manage.

Finance and HR access is derived from current membership in the corresponding root department or any descendant. This keeps access aligned with the organization tree without duplicating those memberships into a separate business-role table.

## Explicit company administrators

`employee_access_roles` stores explicit Employees-owned system roles. The first supported role is `company-admin`.

Company-admin assignment endpoints are available under `/api/access/company-admins` internally and `/api/employees/access/company-admins` on the stand. Only callers with `access.manage` may change assignments.

## Shared UI

Employees uses `@irlix/ui`. The shell follows the internal platform visual reference and is deployed under `/employees/`. Dashboard lives at `/`; Design System is deployed separately at `/design-system/`.

The frontend loads `/api/employees/access/me` before business data. Users without Employees access receive a no-access state rather than attempting protected business requests.

## Deferred

- onboarding email template and mail transport;
- write-permission matrix for HR, Finance and managers;
- least-privilege Keycloak provisioning service account;
- production Keycloak database/configuration;
- RabbitMQ domain events and audit trail;
- compensation/FOT workflow beyond actual salary history.
