# Equipment service

Backend v1 for corporate equipment accounting.

Owns PostgreSQL schema `equipment`. Employees are referenced by `employee_id` and validated through Employees API; no employee tables are read directly.

## Access

The Keycloak bearer token is verified locally. Effective Equipment roles are then enriched from Employees `/access/me` and `/self`, because Employees is the source of truth for special roles and org context. `platform-admin` always has full access. System administrators can view/manage equipment and perform issue/return/write-off operations and maintain assignment history. Accounting can view/manage equipment, financial fields, damage accounting and Equipment settings, but cannot issue, return, edit/delete assignment history, or write off equipment. Verified Keycloak realm roles remain a fallback for platform administration/local operation. `EQUIPMENT_ADMIN_ROLES` and `EQUIPMENT_ACCOUNTING_ROLES` can extend accepted role names.

## Employees integration

Directory loading uses Employees `GET /api/equipment-directory`; assignment and history validation use `GET /api/equipment-directory/{employee}` with the caller's verified bearer token. These endpoints authorize Equipment roles independently of HR card access and return only ID, name, position, department name and employment status. The list contains employed employees; individual lookup also supports former employees for history edits. No salary or HR card access is granted.

## Frontend contract

The Equipment frontend follows the Employees registry/card pattern: full-height/full-width registries, shared topbar without a separate page header, sticky table headers, and a resizable right-side equipment card at `/equipment/items/{id}`. Item attributes are edited inline through per-field actions.

Service navigation:
- `Техника` — `/equipment/`;
- `Выдачи` — `/equipment/assignments`;
- `Оценка стоимости` — `/equipment/valuation`;
- `Списанная техника` — `/equipment/written-off`;
- `Настройки` — `/equipment/settings`.

The legacy `/equipment/depreciation` route remains recognized by the frontend and maps to `Оценка стоимости` for compatibility.

The card contains four tabs:
- `Описание` — equipment attributes;
- `История выдачи` — issue/return actions plus completed assignment history; completed records can be edited or deleted by an operator;
- `Стоимость` — purchase cost, useful life, straight-line depreciation, accounting residual value, damage value loss and current management value;
- `Повреждения` — damage journal with date/description, repair cost/date and permanent value loss, plus total repair spend and total value loss.

There is no planned return date in the current domain model. Assignments contain only issue date and optional actual return date.

## Valuation settings

Default useful life is stored in `equipment_settings` per equipment type and is editable from `/equipment/settings`:

- PC: `48` months;
- Laptop: `36` months;
- Smartphone: `36` months;
- Tablet: `36` months.

These defaults are copied into `equipment_items.useful_life_months` when a new item is created. Changing settings affects only subsequently created equipment and does not retroactively change existing items. Per-item useful life remains editable in the equipment card.

## Financial model

Straight-line accounting depreciation is independent from damage accounting:

`monthly_depreciation = purchase_cost / useful_life_months`

Accumulated depreciation is capped at purchase cost; accounting residual value cannot become negative. Equipment may continue operating after useful life ends, while accounting residual value remains zero.

Each damage record stores repair cost separately from permanent value loss. Repair spending is operational expense and does not automatically reduce the equipment value. A damage record contains the damage date, description, repair cost, optional repair date and permanent value loss; there is no separate comment field.

The management value is calculated as:

`current_value = max(0, accounting_residual_value - sum(damage.value_loss))`

The damage tab also exposes `sum(damage.repair_cost)` as total repair spend. This prevents repair expense and permanent value loss from being counted twice.

## Demo data

Migration `2026_10_02_000002_seed_equipment_demo.php` creates one explicitly synthetic laptop (`DEMO-001`) and one active synthetic assignment. The assignment uses reserved `employee_id=999999999` and never points to a real Employees record. Demo records are marked with `demo-seed` in audit/assignment metadata and can be removed safely by rolling back that migration.

Main endpoints: `/api/items`, `/api/items/{id}`, `/api/items/{id}/assign`, `/return`, `/write-off`, `/api/assignments`, `/api/assignments/{id}`, `/api/items/{id}/damages`, `/api/damages/{id}`, `/api/settings`, `/api/valuation`. `/api/depreciation` remains as a compatibility alias for the valuation response.

## Import from legacy Excel (three sheets)

The top-bar **Импорт XLSX** action is available to Equipment operators (system administrators and platform administrators), not accounting-only editors. It accepts an XLSX workbook up to 10 MiB containing `Словарь`, `Техника`, and `Выдача`. First choose **Проверить**, inspect the aggregate preview and per-row issues, then explicitly choose **Загрузить данные**. Uploaded bytes exist only as temporary request data; the workbook and its personal/commercial values are never included in source control, application logs, or audit payloads.

Column mapping: `Техника`: A inventory number; B type; C manufacturer; D model; E serial; F CPU; G RAM; H storage; I GPU; J purpose; L OS; M version; Q purchase date; R purchase price; T equipment condition/notes; W/X retirement metadata. `Выдача`: A inventory; B serial; C employee full name; D issue date; E return date; G current holder flag; H comment. `Словарь` verifies the expected reference-list layout; calculated counters and their contents are not persisted. Legacy `M`, derived usage days, original estimated price and other computed columns are intentionally ignored.

Employees is queried through the authenticated Equipment directory with `include_former=1` so historical assignments can resolve former employees by exact normalized full name. Names resolving to none or multiple employees are reported and skipped. Devices are deduplicated by inventory number and unambiguous serial number; existing items are retained without overwriting attributes. Duplicate inventory numbers in a file, mismatched serials, overlapping assignment periods, invalid dates, and contradictory return flags are reported. Import is additive and idempotent for exact repeated records. Blank legacy purpose becomes `unknown` / «Не указано» rather than an invented category.

Legacy retirement dates are flagged for separate operator review rather than performing a write-off with an invented reason; no historical record is destructively replaced. No data is imported until the operator confirms the second action. Import errors are shown as an aggregate report and row numbers without exposing workbook cell content.
