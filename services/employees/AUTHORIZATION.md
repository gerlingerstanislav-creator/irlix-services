# Employees authorization

Employees uses a two-stage security model:

1. Keycloak authenticates the caller and provides a validated corporate identity.
2. Employees resolves business authorization as `permission + scope`.

Authentication and authorization are intentionally separate. Keycloak roles do not define normal business visibility in Employees.

## Identity binding

A normal Employees user is resolved by matching the JWT `sub` to `employees.keycloak_user_id`.

The temporary Keycloak realm role `platform-admin` remains a bootstrap/technical full-access path while the project is still being initialized. It is not the long-term business authorization model.

## First-iteration roles

### Company administrator

`company-admin` is an explicit Employees-owned assignment stored in `employee_access_roles`.

It is independent of department, position and Keycloak groups and grants:

- all employee data;
- all salary data;
- employee lifecycle and profile changes;
- organization changes;
- salary changes;
- management of company-admin assignments.

Administration endpoints:

- `GET /api/access/company-admins`;
- `PUT /api/access/company-admins/{employee}`;
- `DELETE /api/access/company-admins/{employee}`.

On the stand these are exposed through Nginx as `/api/employees/access/company-admins...`.

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

### Department manager

An employee is a manager when referenced by `departments.manager_id`.

The accessible scope is the union of every managed department and all descendants recursively.

Permissions:

- employee read: managed subtree;
- salary read: managed subtree;
- mutations: not granted in iteration 1.

### Ordinary employee

An ordinary employee with none of the roles above has no access to Employees. Personal-profile editing will be implemented later in another interface.

## Permission keys

Current authorization payload exposes:

- `employees.read`;
- `employees.manage`;
- `employees.salary.read`;
- `employees.salary.manage`;
- `organization.read`;
- `organization.manage`;
- `access.manage`.

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
- full administrators see mutation controls.

## Deferred decisions

The first iteration intentionally does not grant write permissions to HR, Finance or managers because only visibility rules are currently confirmed. Write permissions should be added independently when business rules are approved.
