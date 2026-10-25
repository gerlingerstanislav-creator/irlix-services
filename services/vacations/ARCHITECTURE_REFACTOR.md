# Vacations — Laravel Eloquent / Controller / Application refactor

Дата: 2026-10-25. Ветка: `feature/vacations-eloquent-layered-backend-20261025` (PR #605). Статус: **в процессе приёмки**, не является production-релизом.

## Исходное состояние

Vacations уже имел отдельные HTTP-контроллеры, `Application/AbsenceService`, доменные перечисления и workflow. Основной технический долг: разрозненные `DB::table()` в контроллерах, маршрутах, application service и служебных задачах. Полное переписывание domain/workflow не требуется.

## Решение

- Eloquent-модели `Absence`, `AbsenceApproval`, `AbsenceAttachment`, `AbsenceStatusHistory`, `AbsenceAuditLog`, `ProductionCalendarYear`, `ProductionCalendarDay` для собственных таблиц. Отношения существуют **только** в границе Vacations; Employees и Clients остаются внешними источниками.
- `AbsenceService` управляет созданием, редактированием, отправкой, согласованием, изменением статусов и аудитом. Межсервисные согласующие от Clients добавляются вместе с отправкой **в одной БД-транзакции**.
- `AbsenceReadService`, `ApprovalQueryService` инкапсулируют доступные выборки, сортировки и scope-запросы. Сервисный слой не отменяет серверную проверку прав в HTTP-границе.
- `AttachmentService` управляет метаданными, именованием файлов, физическим хранилищем и аудитом загрузки/удаления. Контроллер продолжает проверять доступ и тип файла; без разрешения он не раскрывает storage path или содержимое.
- `EmployeePurgeService` обрабатывает межсервисный purge с существующим секретным заголовком и явно квалифицированными `vacations.*` таблицами. Файловая операция выполняется до транзакции, как раньше; неполный purge должен оставаться повторяемым.
- `SystemController` и `InternalPurgeController` убирают business route closures. `/calendar-absences` сохраняет контракт для Timesheets.

**Совместимость:** API/HTTP path, статусы, форма ответов, permission + organizational scope, список согласующих, делегирование кадровику, запрет self-approval, правила документов и авторство событий не меняются. При преобразовании Eloquent-результатов используется явная сериализация `getRawOriginal()`: автоматическая дата-сериализация Eloquent отличается от старого `DB::table()`.

**Без DDL и миграций.** Любые изменения схемы обсуждаются отдельно.

## QA / условия приёмки

1. `php services/vacations/tests/domain_smoke.php` — доменные transitions и права.
2. `php services/vacations/tests/workflow_sequence_smoke.php` — группа согласующих, очередность, возврат в `planned`, комментарий отказа.
3. `php services/vacations/tests/eloquent_contract_smoke.php` — synthetic SQLite, scope, API fields, raw time representations, approval queue.
4. `php services/vacations/tests/eloquent_architecture.php` — отсутствие `DB::table()` в маршрутах, контроллерах, application; транзакции только в сервисах.
5. Dockerfile выполняет PHP lint и все перечисленные smoke. Требуются реальные успешные CI/QA на точном HEAD SHA; обычный `check queued` не означает прохождение.
6. Перед production нужны отдельные функциональные E2E под PostgreSQL и Keycloak: собственная заявка, manager/personnel создание, оплачиваемый отпуск с 0/1/N AM, отсутствие manager fallback, назначенные согласующие, подтверждение делегирования, reject→planned→submit, open-ended sick leave, документ скачиваемый только владельцем/кадровиком, повторный purge, Timesheets calendar. Не отмечать E2E как passed без выполнения.

## Риски, не относящиеся к смене архитектуры

- Существующие операции согласования и проверка наложения периодов не везде сериализуют конкурентные изменения на уровне row lock/уникальных ограничений; это требует отдельного concurrency review, а не молчаливого изменения бизнес-правил.
- Удаление бинарного файла и SQL не являются общей атомарной транзакцией; поведение пока сохранено, перед изменением нужен отдельный план ошибок/компенсаций.
- Не добавлять привилегии менеджера на скачивание документов: они могут видеть metadata, а содержимое — только владелец/кадровик/admin.

## Связанные документы

Общий инженерный стандарт: `docs/horizontal/BACKEND.md`, `docs/horizontal/DATABASE.md`; продуктовый контракт: `ideas/company-internal-services-81-2.4/services/vacations/ТЗ.md`.
