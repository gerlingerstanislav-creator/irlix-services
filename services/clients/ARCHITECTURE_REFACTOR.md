# Clients — Laravel Eloquent / Application refactor

Дата начала: 2026-10-26. **Статус WIP: сервис в целом ещё не переведён.** PR #632 — feature-ветка, не production. Не объединять в `development` до полной приёмки согласованного рефакторинга.

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

В `app/Models` добавлены только необходимые Eloquent-сущности; переименование или перенос таблиц не проводится.

## Что ещё нужно перед готовностью всего Clients

**НЕ ЗАВЕРШЕНО**: вынести в сервисный слой SQL/транзакции из `ClientsController`, `ClientCardController`, `ClientMembersController`, `ContactPeopleController`, `ReportingPeriodsController`, `RequestWorkflowController`, `ClientContourPermissionsController` и других контроллеров по фактической инвентаризации. Особое внимание: client/reporting freeze, money precision, Sales/AM/RN scopes, locks, time-bounded connection history, attempts, feedback, cash-flow, request/position workflow.

Требуются отрицательные permission+scope тесты, совместимость статусов/JSON, конкурентные сценарии, SQL/transaction boundaries, отказы Employees/Timesheets/Finance, production-like PostgreSQL/Keycloak E2E. SQLite и статический gate — не эквивалент реальных E2E. Исключения legacy по SQL/aggregate допустимы в инфраструктурном адаптере при письменном обосновании.

После рефакторинга всех затронутых срезов: обновить описание архитектуры, получить зелёный CI/QA на точном HEAD SHA, PR → `development`, проверить целевой CI SHA, и **лишь затем** по явной команде владельца выпустить накопительный `development → main` и подтвердить deploy + smoke. Владелец уже разрешил production **после завершения полного Clients**, не отдельного WIP.
