# Equipment service

Backend v1 for corporate equipment accounting.

Owns PostgreSQL schema `equipment`. Employees are referenced by `employee_id` and validated through Employees API; no employee tables are read directly.

Permissions are resolved from Keycloak realm roles. Configure `EQUIPMENT_ADMIN_ROLES` and `EQUIPMENT_ACCOUNTING_ROLES` as comma-separated role names. `platform-admin` always has full access.

Main endpoints: `/api/items`, `/api/items/{id}`, `/api/items/{id}/assign`, `/return`, `/write-off`, `/api/assignments`, `/api/depreciation`.
