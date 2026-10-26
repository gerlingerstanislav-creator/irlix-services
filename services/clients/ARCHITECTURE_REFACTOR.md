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

**НЕ ЗАВЕРШЕНО — интеграционная приёмка и delivery.** Первая версия полного рефакторинга успешно прошла CI/QA, Finance, PostgreSQL Laravel migrations и локальные синтетические сценарии на `651dddab71d32baba8b612c9fe826c74ed38e38c`. Изолированный Keycloak 26 проверил реальные подписанные JWT, отказ по отсутствующим сервисным правам и некорректной подписи. Дополнительно в feature-ветке настроена настоящая HTTP-интеграция Clients → Timesheets на отдельных экземплярах Laravel поверх изолированного PostgreSQL (включая партнёра без проекта, неверный token, отказ зависимого сервиса и CommercialData); проверка итогового merge SHA обязательна. Это **не** равнозначно полному E2E с общим Keycloak, Employees, Timesheets и Finance на отдельном TEST-стенде; такой независимый стенд пока не настроен.

Открытый контрольный перечень: отрицательные permission+scope сценарии по всем Sales/AM/RN, точная сериализация публичных и trusted API, конкурентные переходы и деньги, freezing/unfreezing отчётных периодов, интеграции Employees/Timesheets/Finance, реальные PostgreSQL/Keycloak E2E и проверка версий API. Исключения для низкоуровневого SQL допустимы только в обоснованных инфраструктурных адаптерах.

После завершения приёмки полного рефакторинга: обновить описание архитектуры, получить зелёный CI/QA на точном HEAD SHA, PR → `development`, проверить целевой CI SHA, и **лишь затем** по явной команде владельца выпустить накопительный `development → main` и подтвердить deploy + smoke. 26.10.2026 владелец прямо распорядился: **доделать Clients и выпустить в production**. Это разрешение относится к накопительному проверенному `development`, а не к промежуточному WIP; перед выпуском обязательны CI/release candidate точного SHA, проверка совокупного diff, затем production deploy и smoke.
