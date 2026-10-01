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

Migration-service никогда не выполняет schema migrations, DDL, cleanup, locks или data correction в legacy DB. Любая коррекция исходных данных выполняется отдельно владельцами legacy-системы, после чего migration запускается повторно.

## Cutover

Рекомендуемая последовательность для каждого сервиса:

1. `inspect`;
2. `dry-run`;
3. разобрать conflicts и при необходимости добавить explicit override;
4. test migration в очищенную/тестовую target среду;
5. `validate` + reconciliation;
6. production bulk migration;
7. legacy read-only window;
8. final delta (когда для конкретного сервиса будет реализован delta strategy);
9. final reconciliation;
10. отозвать target/legacy credentials migration-service и остановить его.

Код мигратора и private migration metadata не удаляются сразу после cutover: они нужны для аудита соответствий legacy_id -> target_id.
