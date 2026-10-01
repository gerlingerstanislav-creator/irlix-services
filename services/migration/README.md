# Legacy Migration Service

Временный сервис переноса исторических данных из старых рабочих сервисов в IRLIX Services. Сервис не является частью постоянного runtime: запускается вручную, по завершении миграции отключается, а код и migration metadata сохраняются для аудита и воспроизводимости.

## Принципы

- один migration-service, но независимый модуль на каждый бизнес-сервис;
- `employees` и `vacations` уже зарегистрированы; следующие модули добавляются без изменения ядра runner;
- каждый модуль можно `inspect`, `dry-run`, `migrate` и `validate` отдельно;
- mapping/conflicts/overrides/runs хранятся в приватной SQLite БД migration-service, а не в бизнес-схемах;
- обычные API и доменные side effects не вызываются при bulk import;
- неизвестные значения не угадываются: строка попадает в conflict report и пропускается.

## Критическое правило безопасности legacy DB

Migration-service никогда не должен иметь write-доступ к старым БД.

Для каждого legacy-сервиса DBA должен выдать отдельные credentials роли, которая имеет только минимально необходимое чтение. Migration-service сам не создаёт пользователей, не выполняет `GRANT/REVOKE` и не меняет настройки/схему старой БД.

Даже при корректной роли приложение дополнительно применяет четыре защиты:

1. до подключения оператор обязан явно установить `LEGACY_<SERVICE>_DB_READ_ONLY_CONFIRMED=true`;
2. соединение переводится в `default_transaction_read_only=on` только на уровне клиентской сессии;
3. каждый extraction query выполняется в отдельной PostgreSQL transaction с `SET TRANSACTION READ ONLY` и `statement_timeout`;
4. `LegacyReader` принимает только SQL, начинающийся с `SELECT`, и блокирует mutating keywords; перед запуском также проверяется, что роль не superuser/elevated и не имеет эффективных `INSERT/UPDATE/DELETE/TRUNCATE/TRIGGER` прав ни на одну пользовательскую таблицу.

Если любая из проверок не проходит, migration блокируется до исправления credentials. Обхода этой защиты через флаг `force` нет.

## Настройка

Скопировать `services/migration/.env.example` в отдельный секретный env-файл вне git и заполнить только те legacy/target подключения, которые нужны текущему модулю. Пароли не коммитятся.

Target credentials относятся только к новой системе. Для Employees используется DB user Employees, для Vacations — DB user Vacations. Legacy credentials должны быть отдельными read-only credentials и не должны совпадать с рабочими application-owner пользователями старых сервисов.

## Запуск

```bash
docker compose \
  --env-file services/migration/.env \
  -f docker-compose.yml \
  -f docker-compose.migration.yml \
  run --rm migration php artisan legacy:list
```

Проверка подключения и фактических справочников старого Employees:

```bash
docker compose --env-file services/migration/.env -f docker-compose.yml -f docker-compose.migration.yml \
  run --rm migration php artisan legacy:inspect employees
```

Dry-run не пишет бизнес-данные в новую систему; он может писать только runs/conflicts в приватную SQLite migration-service:

```bash
docker compose --env-file services/migration/.env -f docker-compose.yml -f docker-compose.migration.yml \
  run --rm migration php artisan legacy:migrate employees --dry-run
```

Реальный перенос и проверка:

```bash
docker compose --env-file services/migration/.env -f docker-compose.yml -f docker-compose.migration.yml \
  run --rm migration php artisan legacy:migrate employees

docker compose --env-file services/migration/.env -f docker-compose.yml -f docker-compose.migration.yml \
  run --rm migration php artisan legacy:validate employees
```

`vacations` запускается теми же командами независимо. На уровне данных Vacations ожидает, что соответствующие сотрудники уже существуют в новом Employees; сам запуск Employees автоматически не выполняется.

## Employees v1

Переносятся/сопоставляются:

- departments (`yandex_id` -> alias -> normalized name);
- employees (login/email identity);
- department parent/manager/hr links после employee mapping;
- employment periods;
- employee access roles;
- numeric salary history;
- current status/assignment history там, где её ещё нет.

`legal_entity`, employee-level `yandex_id` и salary `author_id` сохраняются в migration metadata, пока для них нет целевого доменного поля. Нечисловые salary/bonus считаются зашифрованными/неразрешёнными, не расшифровываются догадками и создают conflict.

## Vacations v1

- legacy `employee` сопоставляется с Employees сначала по явному override, затем по точному work email, затем только по уникальному точному ФИО + дате приёма (+ дате увольнения, если есть);
- type/status мапятся только по явному консервативному словарю;
- unresolved employee/type/status блокирует перенос конкретного отпуска;
- `vacation_pay_gross/net` сохраняются в migration metadata и создают warning, поскольку текущая доменная модель Vacations не имеет денежного поля;
- legacy approvals сохраняются в metadata; новая approval timeline не фабрикуется, потому что в старой схеме отсутствуют stage и acted_at;
- в `absence_status_history` создаётся только явно помеченная синтетическая точка импорта конечного legacy-state.

## Добавление следующего сервиса

1. добавить legacy/target connection config;
2. создать класс в `app/Migration/Services`, реализующий `ServiceMigration`;
3. читать legacy только через `LegacyReader`;
4. зарегистрировать класс в `config/migration.php -> modules`;
5. добавить safety/dry-run/validation cases и документацию;
6. никогда не добавлять общий `if ($service === ...)` в core runner.

Следующие ожидаемые модули: Clients, Timesheets, Recruitment/Specialists по мере получения схем старых БД.
