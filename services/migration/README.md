# Legacy Migration Service

Временный сервис переноса исторических данных из старых рабочих сервисов в IRLIX Services. Пока идёт миграция, сервис работает как закрытая административная панель. После завершения переноса runtime отключается, а код, mappings, runs, conflicts и отчёты остаются в репозитории/metadata для аудита и воспроизводимости.

## Принципы

- один migration-service, но независимый модуль на каждый бизнес-сервис;
- `employees` и `vacations` уже зарегистрированы; следующие модули добавляются без изменения ядра runner;
- каждый модуль можно `inspect`, `dry-run`, `migrate` и `validate` отдельно;
- mapping/conflicts/overrides/runs хранятся в приватной SQLite БД migration-service, а не в бизнес-схемах;
- API и background worker используют одну metadata DB в WAL-режиме;
- обычные business API и доменные side effects не вызываются при bulk import;
- неизвестные значения не угадываются: строка попадает в conflict report и пропускается.

## Dashboard и доступ

В Dashboard migration-service отображается отдельной компактной карточкой рядом с Design System. Карточка и `/migration/` доступны только пользователю, у которого Employees `/access/me` возвращает специальную роль `platform-admin`.

Защита двухуровневая:

1. Portal скрывает карточку и страницу от остальных пользователей;
2. каждый `/api/migration/*` запрос независимо перепроверяет bearer token и роль через Employees.

Если Employees недоступен или роль `platform-admin` отсутствует, API работает fail-closed.

## Admin Console

Для каждого реализованного migration-модуля интерфейс показывает:

- параметры подключения к legacy PostgreSQL: host, port, database, user, password, SSL mode;
- признак, что пароль уже сохранён (сам пароль обратно в браузер никогда не отдаётся);
- отдельное подтверждение оператора, что используется выделенный read-only пользователь;
- кнопку проверки подключения и фактических PostgreSQL privileges;
- кнопки `Inspect`, `Dry run`, `Перенести`, `Validate`;
- текущий run, фазу, heartbeat, количество обработанных/mapped записей, warnings и conflicts;
- общую историю запусков;
- подробный event log, summary и список conflicts по выбранному run.

Операции запускаются через background queue worker, поэтому браузерный HTTP-запрос не держится открытым и закрытие вкладки не останавливает перенос. Пока есть активный run, панель опрашивает состояние примерно раз в 2 секунды; в покое — реже.

Реальный `migrate` разрешается только если:

1. подключение сохранено;
2. live read-only verification прошёл успешно;
3. после этой проверки выполнен `Dry run`;
4. нет другого активного run этого сервиса;
5. platform-admin явно подтвердил реальный перенос.

## Хранение credentials

Legacy-пароли не пишутся в git, frontend storage или migration logs. Они шифруются Laravel `Crypt` и сохраняются только в приватной metadata DB migration-service. Ключ `MIGRATION_APP_KEY` генерируется один раз на сервере при deploy и сохраняется в серверном `.env`.

При редактировании формы пустой password означает «оставить текущий пароль». Любое изменение параметров подключения сбрасывает статус verification, поэтому перед следующим запуском требуется повторная live-проверка.

После завершения всего проекта миграции credentials следует удалить из панели, runtime migration-service выключить, а ключ/volume архивировать или уничтожить согласно принятой политике хранения migration metadata.

## Критическое правило безопасности legacy DB

Migration-service никогда не должен иметь write-доступ к старым БД.

Для каждого legacy-сервиса DBA должен выдать отдельные credentials роли, которая имеет только минимально необходимое чтение. Migration-service сам не создаёт пользователей, не выполняет `GRANT/REVOKE` и не меняет настройки/схему старой БД.

Перед каждой операцией `LegacyReader` заново применяет и проверяет профиль подключения. Сохранённая отметка «verified» не заменяет live-check внутри run.

Приложение применяет несколько уровней защиты:

1. оператор обязан явно подтвердить использование read-only учётной записи;
2. соединение переводится в `default_transaction_read_only=on` только на уровне клиентской сессии;
3. каждый extraction query выполняется в отдельной PostgreSQL transaction с `SET TRANSACTION READ ONLY`;
4. установлены короткие `statement_timeout`, `lock_timeout`, `idle_in_transaction_session_timeout`;
5. `LegacyReader` принимает только SQL, начинающийся с `SELECT`;
6. запрещены multiple statements, comments, locking SELECT, mutating keywords и известные side-effect PostgreSQL functions;
7. перед чтением проверяется, что роль не является superuser/elevated и не имеет эффективных `INSERT/UPDATE/DELETE/TRUNCATE/TRIGGER` прав ни на одну пользовательскую таблицу.

Если любая проверка не проходит, операция блокируется. Обхода через `force` нет.

## CLI

CLI остаётся доступен для диагностики и аварийного администрирования. Он использует те же migration-модули и LegacyReader.

```bash
docker compose \
  -f docker-compose.yml \
  -f docker-compose.migration.yml \
  exec migration php artisan legacy:list
```

Inspect:

```bash
docker compose -f docker-compose.yml -f docker-compose.migration.yml \
  exec migration php artisan legacy:inspect employees
```

Dry-run:

```bash
docker compose -f docker-compose.yml -f docker-compose.migration.yml \
  exec migration php artisan legacy:migrate employees --dry-run
```

Реальный перенос и проверка:

```bash
docker compose -f docker-compose.yml -f docker-compose.migration.yml \
  exec migration php artisan legacy:migrate employees

docker compose -f docker-compose.yml -f docker-compose.migration.yml \
  exec migration php artisan legacy:validate employees
```

`vacations` запускается независимо теми же командами. На уровне данных Vacations ожидает, что соответствующие сотрудники уже существуют в новом Employees; Employees автоматически не запускается.

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
5. добавить запись в `config/migration.php -> catalog`;
6. добавить safety/dry-run/validation cases и документацию;
7. никогда не добавлять общий `if ($service === ...)` в core runner.

Следующие ожидаемые модули: Clients, Timesheets, Recruitment/Specialists по мере получения схем старых БД.
