# Historical data migration

Исторические данные старых рабочих сервисов переносятся отдельным временным `services/migration`. Это контролируемое исключение из обычного запрета межсервисной записи: migration-service существует только на период cutover, получает target credentials отдельно по каждому новому сервису и после окончания миграции отключается.

## Архитектура

```text
legacy Employees DB --read-only--\
legacy Vacations DB --read-only---+--> migration-service --> Employees target schema
legacy Clients DB ----later-------+                    \--> Vacations target schema
legacy Timesheets DB --later------/                     \-> future target schemas

migration-service private SQLite
  migration_runs
  migration_mappings
  migration_overrides
  migration_conflicts
```

Модули независимы и запускаются отдельно. Общий runner знает только контракт `ServiceMigration` и registry классов. Добавление следующего сервиса не должно менять существующие importers.

## Legacy connection diagnostics

Admin UI разделяет сетевую диагностику и полную проверку legacy PostgreSQL:

1. **Проверить доступность сервера** — выполняет TCP-проверку текущих `host:port` из формы. Она не требует сохранения формы и не проверяет логин, пароль, database или read-only права.
2. **Проверить подключение** — работает по сохранённому connection profile, подключается к PostgreSQL и выполняет полный safety-check роли через `LegacyReader`.

Для legacy-подключений SSL mode по умолчанию — `disable`. Оператор может явно выбрать другой режим, если конкретный legacy PostgreSQL требует TLS.

Поля connection form считаются пользовательским draft. Фоновый polling состояния migration-service не должен заменять введённые `host`, `port`, `database`, `username`, `password`, `sslmode` или read-only checkbox значениями с сервера. Draft очищается после успешного сохранения либо удаления подключения.

## Legacy DB safety invariant

Ни один migration module не получает raw Laravel connection к legacy DB. Единственная точка чтения — `LegacyReader`.

Обязательные invariants:

- только выделенный DB user без effective write privileges;
- явный operator acknowledgement read-only credentials;
- `default_transaction_read_only=on` на client session;
- каждый запрос в отдельной `READ ONLY` transaction;
- только fixed `SELECT` queries;
- hard statement timeout;
- elevated/superuser legacy role блокируется;
- отсутствие скрытого `force`/unsafe режима.

Полная safety-проверка учитывает не только прямые `GRANT` роли, но и эффективные PostgreSQL privileges, включая унаследованные через другие роли или `PUBLIC`. Поэтому пользователь, которому напрямую выдан только `SELECT`, всё равно может быть отклонён, если фактически имеет write/create/sequence privileges.

Migration-service никогда не выполняет schema migrations, DDL, cleanup, locks или data correction в legacy DB. Любая коррекция исходных данных выполняется отдельно владельцами legacy-системы, после чего migration запускается повторно.

## Cutover

Рекомендуемая последовательность для каждого сервиса:

1. проверить доступность legacy server;
2. проверить PostgreSQL connection и read-only safety;
3. `inspect`;
4. `dry-run`;
5. разобрать conflicts и при необходимости добавить explicit override;
6. test migration в очищенную/тестовую target среду;
7. `validate` + reconciliation;
8. production bulk migration;
9. legacy read-only window;
10. final delta (когда для конкретного сервиса будет реализован delta strategy);
11. final reconciliation;
12. отозвать target/legacy credentials migration-service и остановить его.

Код мигратора и private migration metadata не удаляются сразу после cutover: они нужны для аудита соответствий legacy_id -> target_id.
## Статусы фоновых операций

Маршрут `/migration/` при обновлении страницы сначала показывает состояние авторизации, затем после проверки `platform-admin` открывает страницу миграции. Общий дашборд не должен появляться промежуточным кадром. Начально скрытые страницы и карточки остаются скрытыми независимо от CSS layout.

Кнопки и поля модуля блокируются от отправки запроса до финального ответа по конкретному run ID. HTTP 202 означает только очередь. Активная кнопка показывает loading, карточка — операцию, номер запуска и статус; при сбое polling блокировка сохраняется. Активные задачи обновляются каждую секунду, остальные — каждые 5 секунд. Сервер исключает гонку двух запусков одного сервиса с помощью SQLite write-lock.

`Validate` в UI называется «Проверить результат»: сравнивает количество legacy employees/departments либо vacations с количеством migration mappings; не изменяет данные и не сверяет значения полей или зарплаты.
