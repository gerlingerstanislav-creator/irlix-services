# Employees authorization

> **Keycloak-first authorization, draft PR #484 (21.10.2026):** Employees remains the source of org structure, `department.alias`, direct department managers and explicit special-role assignments. Effective tags are synchronized asynchronously as IRLIX-owned Keycloak realm roles (`accounting`, `accounting-head`, `personnel-officer`, `service:finance`, `scope:department-<id>` and related), so validating business services can evaluate signed JWT claims locally instead of querying Employees for authorization every request. Department managers receive only their own managed department labels, not descendants' labels; managed subtree IDs are carried as scope grants. Existing service-specific permissions and business checks remain authoritative. JWT-only mode is gated behind `IRLIX_AUTHZ_JWT_ENABLED=false` pending complete backfill and real Keycloak integration acceptance. Current access tokens live about 10 minutes; urgent revocation of already issued JWT requires an additional control. Full signed-off architecture: `ideas/company-internal-services-81-2.4/services/employees/KEYCLOAK_ROLE_MODEL.md`.


Employees uses a two-stage security model:

1. Keycloak authenticates the caller and provides a validated corporate identity.
2. Employees resolves business authorization as `permission + scope` from the linked employee record and its roles.

Authentication and authorization are intentionally separate. Keycloak authenticates; Employees is the source of truth for application authorization.

## Identity binding

An Employees user is resolved by matching JWT `sub` to `employees.keycloak_user_id`.

For an existing employee that has a unique `login` equal to Keycloak `preferred_username` but does not yet have a `keycloak_user_id`, Employees binds the current JWT `sub` on the first authenticated request. This is used for the bootstrap `admin` employee and is also a safe recovery path for pre-existing employee records provisioned before identity synchronization.

The bootstrap account `admin` is represented by a normal Employees record. It is not a special actor outside the employee model.

## Two independent role dimensions

Employees distinguishes organizational relationships from special functional roles.

Organizational relationships are derived from the current organization structure:

- department membership;
- department manager via `departments.manager_id`;
- directional HR via `departments.hr_id`;
- HR/Accounting subtree membership, identified by stable English department aliases.

Special functional roles are explicit assignments stored in `employee_access_roles` and do not depend on department or position. The catalog is:

- `platform-admin` — **Администратор платформы**;
- `platform-tester` — **Тестировщик платформы**;
- `personnel-officer` — **Специалист по кадрам**;
- `system-admin` — **Системный администратор**.

There is no separate `company-admin` role. Existing assignments are migrated to `platform-admin`.

These dimensions can coexist. In particular, **HR and Специалист по кадрам are different concepts**. A directional HR remains attached to a department in the organization structure, while a personnel officer can work in any department and is selected through the special-role assignment.

## Special roles page

Only a `platform-admin` manages special roles on the dedicated Employees **Роли** page. A `platform-tester` cannot assign or remove any special role. The page is the central UI for special-role assignments and uses:

- `GET /api/access/roles`;
- `PUT /api/access/roles/{role}/{employee}`;
- `DELETE /api/access/roles/{role}/{employee}`.

Only callers with `access.manage` may use these endpoints. Role assignment/removal is written to the Employees audit trail.

## First-iteration roles

### Platform administrator

`platform-admin` is an explicit Employees-owned assignment stored in `employee_access_roles`.

It is independent of department and position and grants:

- all employee data;
- all salary data;
- employee lifecycle and profile changes;
- organization changes;
- salary changes;
- management of special role assignments;
- administrator audit access.

The bootstrap `admin` employee receives `platform-admin` during the migration that introduces the unified role model.

### Platform tester / Тестировщик платформы

`platform-tester` is a special Employees-owned flag for **role-preview
operation only**. It is not privileged, grants no Employees UI, service
entitlements, mutations, salary access, organization changes, role/permission
management, System or Migration permissions. Personal organizational roles
continue to apply as though this marker were absent. In Keycloak it is
represented by non-administrative `role-preview-operator`, not a grant of
`platform-tester` business privileges. See role-only sandbox below.

### Personnel officer / Специалист по кадрам

`personnel-officer` is an explicit Employees-owned functional assignment stored in `employee_access_roles`.

It is independent of department, position, HR department membership and `departments.hr_id`.

The role is intended for business workflows that require a специалист по кадрам, starting with Vacations approval stages. Multiple employees may hold the role; any active personnel officer can be a valid actor for a personnel approval stage when the consuming service allows role-based approval.

`personnel-officer` now grants Employees UI access, all-employee viewing, salary viewing, application creation and hiring; it does not grant general editing or organization management. Each operation is exposed in the editable Employees permission matrix. See [EMPLOYMENT_APPLICATIONS.md](EMPLOYMENT_APPLICATIONS.md).

The absence approval context intentionally exposes both:

- `hr_approver` — directional HR resolved from the organizational department chain;
- `personnel_officers` — active employees explicitly assigned `personnel-officer`.

Consumers must not substitute one for the other.

### System administrator / Системный администратор

`system-admin` is an explicit Employees-owned functional assignment stored in `employee_access_roles`.

It is independent of department and position and identifies employees responsible for system administration and infrastructure workflows.

Like `personnel-officer`, this role is a cross-service business marker. By itself it does **not** grant Employees UI access, employee mutation permissions, salary access, organization-management permissions or management of special roles. Services that need a system administrator must explicitly consume the `system-admin` assignment and define the operations available to it in their own authorization model.

Multiple active employees may hold the role at the same time.

### Accounting and Finance service

The organizational source of Finance access is **Бухгалтерия**, identified exclusively by exact department alias `accounting`. Employees of this department and its child departments, plus the employee assigned as `departments.manager_id` of `accounting`, have Finance access. Client account management (`account-management`) is a different department and does **not** inherit accounting rights. No free-text department name or job title is an authorization key.

The accounting head is detected via `manager_id`, not via the obsolete `finance-director` special role. Existing historical `finance-director` assignment rows are no longer effective and must be reviewed before cleanup.

Accounting staff can read all Employees/salary data and update salary histories, but cannot change the organizational structure. Finance business-operation permissions remain distinct. Only the accounting head and `platform-admin` can approve Finance budgets; ordinary accounting employees cannot.

Department creation, edits (including alias changes) and deletion are strictly `platform-admin` operations. New/edited aliases accept only lowercase ASCII letters, digits and internal hyphens. UI and API normalize spaces to hyphens; invalid or duplicate aliases are rejected. UI warns before changing an alias, because it is an access key. The global Employees service entitlement is checked before service-specific Finance permissions.

### HR

An employee belongs to HR authorization when their current department has exact alias `hr` or is a descendant of that department.

Permissions:

- employee read: all;
- salary read: denied;
- mutations: not granted in iteration 1.

This organization-derived role is separate from `personnel-officer`.

### Directional HR

A department may explicitly reference an HR employee through `departments.hr_id`. This relation belongs to the organization structure and is inherited through the department chain by consumers that need the nearest assigned HR.

Directional HR is not automatically a personnel officer and does not replace `personnel-officer` in кадровые approval stages.

### Department manager

An employee is a manager when referenced by `departments.manager_id`.

The accessible scope is the union of every managed department and all descendants recursively.

Permissions:

- employee read: managed subtree;
- salary read: managed subtree;
- mutations: not granted in iteration 1.

### Ordinary employee

An ordinary employee with none of the organization-derived access roles has no Employees UI access. The `personnel-officer` functional role grants Employees access under the October 2026 hiring workflow. `system-admin` remains a functional marker without default Employees access.

## Permission keys

Current authorization payload exposes:

- `employees.read`;
- `employees.manage`;
- `employees.salary.read`;
- `employees.salary.manage`;
- `organization.read`;
- `organization.manage`;
- `staff_positions.read`;
- `staff_positions.manage`;
- `access.manage`;
- `audit.read`.

`employees.salary.read` is intentionally independent of `employees.read` so HR can inspect employee records without receiving salary data.

## Scope enforcement

Authorization is enforced on the backend. UI hiding is only a convenience and is not treated as a security boundary.

For `subtree` scope the backend filters employees and organization rows to accessible department IDs and rejects direct access outside the scope. For `all` scope no department filter is applied.

## Frontend behavior

The frontend loads `/api/employees/access/me` before loading business data.

- users without Employees access receive an explicit no-access screen;
- HR does not see the salary tab;
- managers can read permitted data without mutation controls; Finance can also change salary history but cannot mutate employee/profile or organization data;
- Only `platform-admin` sees administrative mutation controls and the **Роли** section. A tester sees these only when independently granted a corresponding non-administrative business permission, and preview itself never permits mutations;
- special functional roles are administered centrally on the **Роли** page rather than inferred from an employee's department.

## Deferred decisions

The first iteration does not grant employee/profile or organization write permissions to HR, Finance or managers. Finance is the exception for compensation workflows: it receives `employees.salary.manage` for salary-history changes. Other write permissions should be added independently when business rules are approved.

Clients exception (2026-10-08): `platform-tester` without `platform-admin` cannot open or use the Clients service, even with additional organizational roles or matrix grants. All other existing tester privileges remain. Actual `platform-admin` wins when both roles are assigned. Clients retains only the minimal read contracts needed by Timesheets/Vacations for this role.


## Employment applications (October 2026)

The two-stage employee hiring contract and the editable role/permission matrix supersede the original onboarding-on-create description above. Pending employees have no Keycloak identity and are not active workforce entries. Read [EMPLOYMENT_APPLICATIONS.md](EMPLOYMENT_APPLICATIONS.md) for the current rules.


## ФОТ и кадровые переводы (19.10.2026)

- `employees.fot.view` — видеть помесячные суммы ФОТ и пересмотры только в своём salary organizational scope; дополнительно требуется `employees.salary.read`.
- `employees.fot.plan` — руководители направлений создают и редактируют планы внутри доступного поддерева; сумма плана не становится фактической зарплатой без отдельной операции.
- `employees.fot.approve` — согласование возможно только для назначенного сотрудника в его очередной момент текущего цикла; доступные по роли approve-права сами по себе не дают право одобрить чужой шаг.
- `employees.transfer` — фактический кадровый перевод со сменой оклада доступен только `personnel-officer` и `platform-admin`. `platform-tester` без `platform-admin` не получает этого права через wildcard. Обычный PATCH профиля не меняет подразделение; используется отдельный маршрут.
- Планирование и согласование пересмотров не являются основанием изменять зарплатную историю.

## Role-only sandbox for platform tester (22.10.2026)

`platform-tester` is **not** a platform administrator. It contributes no
administrative, Employees mutation, Finance, Clients, System, Migration, CV
or other service grants by itself. A tester's personal account retains only
permissions from its ordinary organization/functional assignments. The
Keycloak synchronizer removes the legacy `platform-tester` realm grant and
issues `role-preview-operator` as a non-administrative marker. This role is
never considered an API permission to mutate data.

The sidebar account popover contains a role selector with **Личный аккаунт**
selected by default. Only platform testers and platform administrators see
role scenarios. No employee account ID or impersonated user can be selected.
Roles include personnel, HR, sales, account-management, accounting, system
administration, production direction heads and a composite commercial
director (sales + account-management + client-service heads).

Selected scenario is attached to API calls as `X-Irlix-Role-Preview`;
the server never trusts this header as a grant. Each service first verifies
the original Keycloak JWT, then asks Employees `GET /api/access/preview`
to validate the actor and build effective permissions, service entry and
department scope for this *role*, while preserving the real employee identity.
Preview mode is limited to GET/HEAD; mutations return 403. Privileged
Platform Core/Migration and CV converter endpoints reject preview entirely.
Changing scenarios reloads the service to avoid mixing request contexts.
The selection is tab-scoped and cleared on logout. Role preview requests
add a request-time Employees dependency intentionally, unlike normal JWT mode.

Role preview simulates role grants and direction scope, **not** the project
assignments, customers or approvals of a particular account. It must never
claim to test another employee's specific data. No permanent Keycloak role
change takes place.

Rollout is gated on regression/security tests, particularly legacy
`platform-tester` removal, disabled writes, fake role headers, head scopes,
negative access cases, and real production JWT claims. Do not assume an
unreviewed branch or green unit test is a safe production cutover.
