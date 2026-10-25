# Employees — архитектурный рефакторинг Laravel (Eloquent + сервисный слой)

Дата начала: 2026-10-24. **Реализовано и объединено через PR #601**. Архитектура служит одним из примеров обязательного Laravel-стандарта; история релиза определяется фактическими Git/CI, а не датой этого документа.

## Цель и ограничения

- Маршруты содержат только привязку к контроллерам; контроллеры отвечают за HTTP-контракт, валидацию и авторизацию, сервисы — за сценарии и транзакции.
- Доступ к таблицам собственного сервиса — через отдельные модели Eloquent и Eloquent Query Builder; `DB::transaction()` допускается для границ атомарности. В миграциях DDL и диагностических SQL применяются отдельно.
- Контракты API, коды ошибок, модель прав, видимость зарплат, правила аудита, структура БД и ownership сервисов остаются прежними.
- Изменения в Keycloak выполняются через выделенный сервис; частичные отказы и повторные попытки обрабатываются как до рефакторинга. Не включать запись синхронизации ролей без предварительных проверок и отдельного разрешения.
- Взаимодействие с Platform Core/Finance/прочими сервисами — через внутренние HTTP API, не через их таблицы.
- Доступ к Brandbook при каждом запросе проверяется сервером, с fail-closed поведением при отказе БД и при просроченной/несоответствующей учётной записи.

## Переведённые подсистемы

| Область | Основные компоненты |
|---|---|
| Должности и оргструктура | `OrganizationController`, `DepartmentController`, `StaffPositionService`, `DepartmentService`, `StaffPositionImporter`, модели `Department` и `StaffPosition` |
| Кадровый реестр и жизненный цикл | `EmployeeController`, `EmployeeReadController`, `EmployeeLifecycleService`, `EmployeeHistoryService`, `EmployeeReadService`, исторические Eloquent-модели |
| Отдельные справочники и scope | `EmployeeDirectoryController`, `EmployeeDirectoryService` |
| Зарплаты, ФОТ и согласования | `SalaryReviewController`, `SalaryReviewService`, `PayrollOverviewService`, `EmployeeTransferService`, Eloquent-модели пересмотров/согласований |
| Системная авторизация | `AccessRoleController`, `PermissionRuleController`, `SpecialRoleService`, `PermissionRuleService`, `EmployeeIdentityService`, `KeycloakAuthorizationSync` |
| Интеграции и удаление | `FinanceDirectoryController`, `FinanceDirectoryService`, `EmployeeDeletionController`, `EmployeeDeletionService` |
| Аудит, onboarding и Brandbook | `AuditController`, `AuditQueryService`, `OnboardingController`, `EmployeeOnboardingService`, `BrandbookGateController`, `BrandbookAccessService` |
| Фоновые обработчики | `OutboxDeliveryService`, `AuthorizationSyncQueueService` и CLI-скрипты в `bin/` |

Новые модели Eloquent не означают, что API должен выдавать все поля: в сервисах сохраняются существующие allowlist-проекции, особенно для приватных карточек, Equipment, Finance и зарплат.

## Проверки для приёмки

1. Архитектурный gate: `php services/employees/tests/eloquent_architecture.php`, отсутствие `DB::table()` в HTTP/Service/Support/worker коде и route closures для бизнес-эндпоинтов.
2. Docker-сборка Employees должна завершиться успешно, включая `position_lifecycle.php`, `position_catalog.php`, `equipment_directory.php`, `layered_workflows.php`, `staff_positions_import.php`, `eloquent_services.php`.
3. Полный CI, QA, Keycloak authorization integration, Finance validation, Brandbook validation — **зелёные на одном и том же HEAD SHA**.
4. Перед объединением отдельно проверить реальный PostgreSQL + Keycloak: создание заявки/найм, увольнение/возврат, перевод, последовательность согласований ФОТ, финальный approval, requeue/outbox, удаление, доступ сотрудников с разными scope и скрытие зарплат.
5. Проверить ошибки внешних API, повторы, гонки, rollback и сохранность JSON-контрактов; нельзя считать один статический gate доказательством полного функционального соответствия.

## Остаточные ограничения

- Рефакторинг PR #601 был объединён и проверен автоматическими CI/QA; реальные HR end-to-end операции с отдельным TEST/PostgreSQL/Keycloak и матрицей ролей не считать завершёнными без фактического протокола испытаний.
- Внешние side-effects Keycloak, очередь/outbox, конфликты параллельных изменений и исторические API-contracts остаются предметом адресной регрессии при дальнейшем изменении.
- Нормативные требования для всех Laravel-сервисов — [BACKEND](../../docs/horizontal/BACKEND.md#архитектура-laravel--обязательный-контракт), а не статус этого примера.
