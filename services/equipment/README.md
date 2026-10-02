# Equipment service

Backend v1 for corporate equipment accounting.

Owns PostgreSQL schema `equipment`. Employees are referenced by `employee_id` and validated through Employees API; no employee tables are read directly.

## Access

The Keycloak bearer token is verified locally. Effective Equipment roles are then enriched from Employees `/access/me` and `/self`, because Employees is the source of truth for special roles and org context. `platform-admin` always has full access. System administrators can view/manage equipment and perform issue/return/write-off operations. Accounting can view/manage equipment and financial fields but cannot issue, return, or write off equipment. Verified Keycloak realm roles remain a fallback for platform administration/local operation. `EQUIPMENT_ADMIN_ROLES` and `EQUIPMENT_ACCOUNTING_ROLES` can extend accepted role names.

## Demo data

Migration `2026_10_02_000002_seed_equipment_demo.php` creates one explicitly synthetic laptop (`DEMO-001`) and one active synthetic assignment. The assignment uses reserved `employee_id=999999999` and never points to a real Employees record. Demo records are marked with `demo-seed` in audit/assignment metadata and can be removed safely by rolling back that migration.

Main endpoints: `/api/items`, `/api/items/{id}`, `/api/items/{id}/assign`, `/return`, `/write-off`, `/api/assignments`, `/api/depreciation`.
