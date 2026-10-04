# Equipment service

Backend v1 for corporate equipment accounting.

Owns PostgreSQL schema `equipment`. Employees are referenced by `employee_id` and validated through Employees API; no employee tables are read directly.

## Access

The Keycloak bearer token is verified locally. Effective Equipment roles are then enriched from Employees `/access/me` and `/self`, because Employees is the source of truth for special roles and org context. `platform-admin` always has full access. System administrators can view/manage equipment and perform issue/return/write-off operations and maintain assignment history. Accounting can view/manage equipment, financial fields and damage accounting, but cannot issue, return, edit/delete assignment history, or write off equipment. Verified Keycloak realm roles remain a fallback for platform administration/local operation. `EQUIPMENT_ADMIN_ROLES` and `EQUIPMENT_ACCOUNTING_ROLES` can extend accepted role names.

## Frontend contract

The Equipment frontend follows the Employees registry/card pattern: full-height/full-width registries, shared topbar without a separate page header, sticky table headers, and a resizable right-side equipment card at `/equipment/items/{id}`. Item attributes are edited inline through per-field actions.

The card contains four tabs:
- `Описание` — equipment attributes;
- `История выдачи` — issue/return actions plus completed assignment history; completed records can be edited or deleted by an operator;
- `Стоимость` — purchase cost, useful life, straight-line depreciation, accounting residual value, damage value loss and current management value;
- `Повреждения` — damage journal with date/description, repair cost/date, permanent value loss and comment, plus total repair spend and total value loss.

There is no planned return date in the current domain model. Assignments contain only issue date and optional actual return date.

## Financial model

Straight-line accounting depreciation is independent from damage accounting:

`monthly_depreciation = purchase_cost / useful_life_months`

Accumulated depreciation is capped at purchase cost; accounting residual value cannot become negative. Equipment may continue operating after useful life ends, while accounting residual value remains zero.

Each damage record stores repair cost separately from permanent value loss. Repair spending is operational expense and does not automatically reduce the equipment value. The management value is calculated as:

`current_value = max(0, accounting_residual_value - sum(damage.value_loss))`

The damage tab also exposes `sum(damage.repair_cost)` as total repair spend. This prevents repair expense and permanent value loss from being counted twice.

## Demo data

Migration `2026_10_02_000002_seed_equipment_demo.php` creates one explicitly synthetic laptop (`DEMO-001`) and one active synthetic assignment. The assignment uses reserved `employee_id=999999999` and never points to a real Employees record. Demo records are marked with `demo-seed` in audit/assignment metadata and can be removed safely by rolling back that migration.

Main endpoints: `/api/items`, `/api/items/{id}`, `/api/items/{id}/assign`, `/return`, `/write-off`, `/api/assignments`, `/api/assignments/{id}`, `/api/items/{id}/damages`, `/api/damages/{id}`, `/api/depreciation`.