# Clients — Laravel Eloquent / Application refactor

Дата начала: 2026-10-26. **Статус WIP: разделение HTTP/application/persistence реализовано; полная интеграционная приёмка ещё не подтверждена.** PR #632 — feature-ветка, не production. Не объединять в `development` до полной приёмки согласованного рефакторинга.

## Цель

Стандарт из `AGENTS.md` / `docs/horizontal/BACKEND.md`: Routes → HTTP Controllers → Application/Services → Eloquent по собственным бизнес-таблицам. Не менять DDL, данные, historic mappings, авторизацию и API. Canonical product decisions хранятся в приватном `ideas/company-internal-services-81-2.4/services/clients/CLIENT_MEMBER_TIMESHEETS_ARCHITECTURE.md`. Не копировать приватную спецификацию сюда.

## Выполненные завершённые срезы в feature-ветке

- `AbsenceApproversController` → `AbsenceApproversService`, который читает `ClientMember + MemberTerms` вместо старых проектных связей: корректно работает без проекта.
- `TimesheetMembershipsController` → `TimesheetMembershipDirectory`: полный, versioned, защищённый токеном каталог, корректный partner-id/null employee-id и revision; сохранён `Support/TimesheetMemberships` compat adapter.
- `FinanceClientsController` → `FinanceClientsService`: директория Clients/Projects, проверка direct-cost client ownership, интервалы коммерческих условий, snapshot/hash, повторная платёжная операция в транзакции.
- `EmployeePurgeController` → `EmployeePurgeService`: существующий token, history guard (409), транзакционное снятие legacy references.
- `PartnersController` → `PartnersService`: registry, catalog, create/update, сохранение permission+scope.
- `MemberTermsController` → `LegacyMemberTermsService`: коммерческие условия и блокировка клиента/членства; порядок проверки исторического участника перед валидацией сохранён.
- `ProjectMemberFeedbackController` / `ProjectMemberCardController`: безопасные legacy adapters поверх canonical models/read services.
- `ClientAccountHandoffsController` → `ClientAccountHandoffService`: owner/head checks, delegated AM intervals, state and audit in transaction.

- `ClientLogoController` → `ClientLogoService`: управление файлами, блокировки и компенсация ошибок.
- `ClientContourPermissionsController` → `ClientContourPermissionService`: матрица прав и audit под транзакцией.
- `ClientMembersController` → `ClientMembershipService`: подключение ClientMember, MemberTerms, ProjectAssignments и feedback.
- `ContactPeopleController` → `ContactPeopleService`: контакты, способы связи и клиентские связи.

В `app/Models` добавлены только необходимые Eloquent-сущности; переименование или перенос таблиц не проводится.

## Дополнительно реализовано в feature-ветке

- `ClientsController` переведён на `ClientsOverviewService`, `ClientRegistryService`, `LeadManagementService`, `LegacyContactRelationService`, `LegacyProjectMembershipService`, `LegacyReportingPeriodService` и `RequestWorkflowService`. Старые HTTP маршруты сохраняются как совместимые адаптеры.
- `ClientCardController`, `ReportingPeriodsController`, `RequestWorkflowController`, `AttemptFunnelController` и `TimesheetAssignmentsController` не выполняют SQL напрямую. Используются application/read services и собственные Eloquent-модели.
- Обработчики health и production calendar вынесены из route closures: SQL проверки доступности находится в инфраструктурном сервисе, календарь доступен через API Platform Core.
- `ClientHistory`, `MemberIdentity`, `RequestLifecycle`, `MemberTermHistory`, `ClientContourAccess`, `CommercialSnapshot` используют собственные Eloquent-модели.
- Архитектурный gate автоматически проверяет все Clients HTTP controllers, api/internal routes и запрещает `DB::table()` в `app/Services`. Добавлены smoke-сценарии реестра, legacy-подключений и конвертации лида.

## Что ещё нужно перед готовностью всего Clients

**НЕ ЗАВЕРШЕНО — финальная приёмка и delivery.** На SHA `808142c4` штатные CI, QA, Finance validation и UI checks завершились успешно, включая Clients Docker smoke. После этого ветка синхронизирована с изменениями development, внесён Eloquent-переход `CommercialSnapshot`; новые SHA требуют повторной проверки. Пройденные SQLite/synthetic smoke и статический gate нельзя считать проверкой production-like PostgreSQL/Keycloak и реальных межсервисных отказов.

Открытый контрольный перечень: отрицательные permission+scope сценарии по всем Sales/AM/RN, точная сериализация публичных и trusted API, конкурентные переходы и деньги, freezing/unfreezing отчётных периодов, интеграции Employees/Timesheets/Finance, реальные PostgreSQL/Keycloak E2E и проверка версий API. Исключения для низкоуровневого SQL допустимы только в обоснованных инфраструктурных адаптерах.

После рефакторинга всех затронутых срезов: обновить описание архитектуры, получить зелёный CI/QA на точном HEAD SHA, PR → `development`, проверить целевой CI SHA, и **лишь затем** по явной команде владельца выпустить накопительный `development → main` и подтвердить deploy + smoke. Владелец уже разрешил production **после завершения полного Clients**, не отдельного WIP.
