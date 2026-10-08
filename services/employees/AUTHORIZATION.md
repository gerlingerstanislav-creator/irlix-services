# Employees authorization

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
- HR/Finance subtree membership.

Special functional roles are explicit assignments stored in `employee_access_roles` and do not depend on department or position. The catalog is:

- `platform-admin` — **Администратор платформы**;
- `platform-tester` — **Тестировщик платформы**;
- `personnel-officer` — **Специалист по кадрам**;
- `system-admin` — **Системный администратор**.

There is no separate `company-admin` role. Existing assignments are migrated to `platform-admin`.

These dimensions can coexist. In particular, **HR and Специалист по кадрам are different concepts**. A directional HR remains attached to a department in the organization structure, while a personnel officer can work in any department and is selected through the special-role assignment.

## Special roles page

Platform-privileged users manage special roles on the dedicated Employees **Роли** page. `platform-tester` may manage ordinary special roles, but cannot assign or remove `platform-admin` or `platform-tester`. The page is the central UI for special-role assignments and uses:

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

`platform-tester` is an explicit Employees-owned assignment stored in `employee_access_roles`. Except for the explicitly denied Clients service, it receives the same platform-wide read/write capabilities and service visibility as `platform-admin`, including Employees mutations, salary operations, organization/staff-position changes and audit access.

Another deliberate restriction is privilege escalation: a platform tester cannot assign or remove `platform-admin` or `platform-tester`. This restriction is enforced by the Employees role-management API and mirrored in the Roles UI. A real `platform-admin` can manage both protected roles.

### Personnel officer / Специалист по кадрам

`personnel-officer` is an explicit Employees-owned functional assignment stored in `employee_access_roles`.

It is independent of department, position, HR department membership and `departments.hr_id`.

The role is intended for business workflows that require a специалист по кадрам, starting with Vacations approval stages. Multiple employees may hold the role; any active personnel officer can be a valid actor for a personnel approval stage when the consuming service allows role-based approval.

`personnel-officer` by itself does **not** grant Employees UI access, employee mutation permissions, salary access or organization-management permissions.

The absence approval context intentionally exposes both:

- `hr_approver` — directional HR resolved from the organizational department chain;
- `personnel_officers` — active employees explicitly assigned `personnel-officer`.

Consumers must not substitute one for the other.

### System administrator / Системный администратор

`system-admin` is an explicit Employees-owned functional assignment stored in `employee_access_roles`.

It is independent of department and position and identifies employees responsible for system administration and infrastructure workflows.

Like `personnel-officer`, this role is a cross-service business marker. By itself it does **not** grant Employees UI access, employee mutation permissions, salary access, organization-management permissions or management of special roles. Services that need a system administrator must explicitly consume the `system-admin` assignment and define the operations available to it in their own authorization model.

Multiple active employees may hold the role at the same time.

### Finance

An employee belongs to Finance authorization when their current department is identified by name `Finance` or alias `finance`, or is any descendant of that department.

Permissions:

- employee read: all;
- salary read: all;
- salary changes: all;
- employee/profile and organization mutations: not granted.

### HR

An employee belongs to HR authorization when their current department is `HR` or any descendant of it.

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

An ordinary employee with none of the organization-derived access roles has no Employees UI access. Special functional roles such as `personnel-officer` and `system-admin` can still be visible to other services through `/api/access/me` and integration contexts without granting the Employees UI itself.

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
- `platform-admin` and `platform-tester` see administrator-equivalent mutation controls and the **Роли** section; `platform-tester` cannot assign/remove the two protected platform roles;
- special functional roles are administered centrally on the **Роли** page rather than inferred from an employee's department.

## Deferred decisions

The first iteration does not grant employee/profile or organization write permissions to HR, Finance or managers. Finance is the exception for compensation workflows: it receives `employees.salary.manage` for salary-history changes. Other write permissions should be added independently when business rules are approved.

Clients exception (2026-10-08): `platform-tester` without `platform-admin` cannot open or use the Clients service, even with additional organizational roles or matrix grants. All other existing tester privileges remain. Actual `platform-admin` wins when both roles are assigned. Clients retains only the minimal read contracts needed by Timesheets/Vacations for this role.
