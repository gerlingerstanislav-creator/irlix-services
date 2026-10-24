# Migration — точечный вход

- Frontend: `apps/migration/` (встраивание в System).
- Backend: `services/migration/`, `services/migration-ops/`; проверять фактический текущий код.
- Реализация/операции: `docs/MIGRATION.md`.
- Выбирать **один** этап `validate / dry-run / migrate / rollback`, сущность и конкретный код ошибки, затем искать исполнителя и тесты.
- Для Timesheets: только legacy-источник, маппинги идентификаторов Employees/Clients, записи времени и связь с подключениями.
- Восстановление/откат: отдельно проверить границы идемпотентности и безопасной повторной операции.
- Не загружать документацию всех сервисов-источников и всех исторических запусков.
## Адресные маршруты

| Сценарий | Начальная точка |
|---|---|
| UI консоли | `docs/MIGRATION_UI.md`, `docs/MIGRATION_CONTROL.md`, `apps/migration/` |
| Операции/worker/rollback | `docs/MIGRATION.md`, нужные компоненты `services/migration-ops/` |
| Продуктовый порядок переноса | `ideas/company-internal-services-81-2.4/architecture/MIGRATION.md`, `architecture/MIGRATION_CONTROL.md` |
| Операционный runbook | `ideas/company-internal-services-81-2.4/operations/MIGRATION.md` |

Открывать лишь конкретный этап/ошибку, избегая пакетного чтения всех документов о миграциях.
