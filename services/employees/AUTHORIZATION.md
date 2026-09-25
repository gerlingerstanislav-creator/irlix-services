# Employees authorization

Employees uses a two-stage security model:

1. Keycloak authenticates the caller and provides a validated corporate identity.
2. Employees resolves business authorization as `permission + scope`.

Authentication and authorization are intentionally separate. Keycloak roles do not define normal business visibility in Employees.

## Identity binding

A normal Employees user is resolved by matching the JWT `sub` to `employees.keycloak_user_id`.

The temporary Keycloak realm role `platform-admin` remains a bootstrap/technical full-access path while the project is still being initialized. The bootstrap account username is `admin` by default (configurable with `IRLIX_BOOTSTRAP_ADMIN_USERNAME`) and is always treated as a technical platform administrator after successful Keycloak authentication, even if a particular token does not contain the `platform-admin` realm-role claim. This bootstrap invariant is separate from Employees-owned business roles and is not the long-term business authorization model.

## Two independent role dimensions

Employees distinguishes organizational relationships from special functional roles.

Organizational relationships are derived from the current organization structure:

- department membership;
- department manager via `departments.manager_id`;
- directional HR via `departments.hr_id`;
- HR/Finance subtree membership.

Special functional roles are explicit assignments stored in `employee_access_roles` and do not depend on department or position. The initial catalog is:

- `company-admin` — **Администратор компании**;
- `personnel-officer` — **Кадровик**.

These dimensions can coexist. In particular, **HR and Кадровик are different concepts**. A directional HR remains attached to a department in the organization structure, while a personnel officer can work in any department and is selected through the special-role assignment.

## Special roles page

Full administrators manage special roles on the dedicated Employees **Роли** page. The page is the central UI for special-role assignments and uses:

- `GET /api/access/roles`;
- `PUT /api/access/roles/{role}/{employee}`;
- `DELETE /api/access/roles/{role}/{employee}`.

Only callers with `access.manage` may use these endpoints. Role assignment/removal is written to the Employees audit trail.

The former `/api/access/company-admins...` endpoints remain as backward-compatible aliases while integrations migrate to the generic role contract.

## First-iteration roles

### Company administrator

`company-admin` is an explicit Employees-owned assignment stored in `employee_access_roles`.

It is independent of department, position and Keycloak groups and grants:

- all employee data;
- all salary data;
- employee lifecycle and profile changes;
- organization changes;
- salary changes;
- management of special role assignments.

### Personnel officer / Кадровик

`personnel-officer` is an explicit Employees-owned functional assignment stored in `employee_access_roles`.

It is independent of department, position, HR department membership and `departments.hr_id`.

The role is intended for business workflows that require a кадровик, starting with Vacations approval stages. Multiple employees may hold the role; any active personnel officer can be a valid actor for a personnel approval stage when the consuming service allows role-based approval.

`personnel-officer` by itself does **not** grant Employees UI access, employee mutation permissions, salary access or organization-management permissions.

The absence approval context intentionally exposes both:

- `hr_approver` — directional HR resolved from the organizational department chain;
- `personnel_officers` — active employees explicitly assigned `personnel-officer`.

Consumers must not substitute one for the other.

### Finance

An employee belongs to Finance authorization when their current department is `Finance` or any descendant of it.

Permissions:

- employee read: all;
- salary read: all;
- mutations: not granted in iteration 1.

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

An ordinary employee with none of the organization-derived access roles has no Employees UI access. A special functional role such as `personnel-officer` can still be visible to other services through `/api/access/me` and integration contexts without granting the Employees UI itself.

## Permission keys

Current authorization payload exposes:

- `employees.read`;
- `employees.manage`;
- `employees.salary.read`;
- `employees.salary.manage`;
- `organization.read`;
- `organization.manage`;
- `access.manage`;
- `audit.read`.

`employees.salary.read` is intentionally independent of `employees.read` so HR can inspect employee records without receiving salary data.

## Scope enforcement

Authorization is enforced on the backend. UI hiding is only a convenience and is not treated as a security boundary.

For `subtree` scope the backend:

- filters employee collections by accessible department IDs;
- rejects direct access to employees outside the scope;
- filters organization rows to accessible departments;
- includes salary history only when salary permission is present.

For `all` scope no department filter is applied.

## Frontend behavior

The frontend loads `/api/employees/access/me` before loading business data.

- users without Employees access receive an explicit no-access screen;
- HR does not see the salary tab;
- managers and Finance can read permitted data but do not see mutation controls;
- full administrators see mutation controls and the **Роли** section;
- special functional roles are administered centrally on the **Роли** page rather than inferred from an employee's department.

## Deferred decisions

The first iteration intentionally does not grant write permissions to HR, Finance or managers because only visibility rules are currently confirmed. Write permissions should be added independently when business rules are approved.
