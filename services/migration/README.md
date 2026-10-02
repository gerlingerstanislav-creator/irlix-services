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

## Metadata DB runtime

Служебная SQLite БД migration-service живёт только в приватном volume `/data`. Канонический путь задаётся отдельной переменной `MIGRATION_METADATA_DATABASE` и по умолчанию равен `/data/migration.sqlite`.

Migration Service не полагается на Laravel-default `database/database.sqlite`: service provider принудительно направляет default SQLite connection в `MIGRATION_METADATA_DATABASE`, API и worker используют один и тот же файл, а entrypoint создаёт файл и применяет migrations до запуска API.

Docker healthcheck обращается к `/api/migration/health`. Сервис считается healthy только если в metadata DB существуют обязательные таблицы `migration_runs`, `migration_connections`, `migration_run_events` и `migration_conflicts`. Это не позволяет выпустить контейнер в healthy-состоянии, если приложение случайно открыло пустую/не ту SQLite БД.

## Dashboard и доступ

В Dashboard migration-service отображается отдельной компактной карточкой рядом с Design System. Карточка и `/migration/` доступны только пользователю, у которого Employees `/access/me` возвращает специальную роль `platform-admin`.

Защита двухуровневая:

1. Portal скрывает карточку и страницу от остальных пользователей;
2. каждый `/api/migration/*` запрос независимо перепроверяет bearer token и роль через Employees.

Если Employees недоступен или роль `platform-admin` отсутствует, API работает fail-closed.

## Admin Console

Для каждого реализованного migration-модуля интерфейс показывает:

- параметры подключения к legacy PostgreSQL: host, port, database, user, password, SSL mode;
- `SSL mode` по умолчанию — `disable`; другой режим выбирается только если legacy PostgreSQL действительно требует TLS;
- признак, что пароль уже сохранён (сам пароль обратно в браузер никогда не отдаётся);
- отдельное подтверждение оператора, что используется выделенный read-only пользователь;
- кнопку проверки доступности сервера БД по TCP без PostgreSQL-аутентификации;
- кнопку проверки подключения и фактических PostgreSQL privileges;
- кнопки `Inspect`, `Dry run`, `Перенести`, `Validate`;
- текущий run, фазу, heartbeat, количество обработанных/mapped записей, warnings и conflicts;
- общую историю запусков;
- подробный event log, summary и список conflicts по выбранному run.

Форма подключения хранит пользовательский черновик независимо от фонового polling. Автообновление состояния migration-service не должно изменять или сбрасывать введённые host/port/database/user/password, выбранный SSL mode и read-only checkbox до явного сохранения или удаления подключения.

Любое пользовательское действие, которое ждёт ответ API (`Сохранить доступ`, TCP-проверка, проверка PostgreSQL-подключения, удаление доступа, постановка `Inspect`/`Dry run`/`Перенести`/`Validate` в очередь), переводит карточку модуля в явное pending-состояние: показывается индикатор ожидания и текст текущего действия, все поля и кнопки этого модуля временно блокируются, а повторный клик не создаёт второй запрос. Для фоновой операции блокировка продолжается после HTTP 202 до `completed`, `conflicts` или `failed` именно полученного run ID. Потеря связи не считается завершением: блокировка сохраняется, отображается повторная проверка статуса. Активные запуски опрашиваются каждую секунду, без активных — каждые 5 секунд. После перезагрузки страницы блокировка восстанавливается по серверному active_run. Сервер сериализует постановку задач через write-lock приватной SQLite metadata DB, запрещая параллельные запуски одного модуля даже из разных вкладок. Ручное `Обновить` аналогично блокируется и показывает собственный loading-state до завершения запроса.

`Проверить доступность сервера` использует введённые в текущую форму `host` и `port` и проверяет только возможность установить TCP-соединение. Эта операция не проверяет имя БД, логин, пароль, SSL negotiation или права PostgreSQL. Полная проверка выполняется отдельной кнопкой `Проверить подключение`.

Операции запускаются через background queue worker, поэтому браузерный HTTP-запрос не держится открытым и закрытие вкладки не останавливает перенос. Пока есть активный run, панель опрашивает состояние примерно раз в 2 секунды; в покое — реже.

Реальный `migrate` разрешается только если:

1. подключение сохранено;
2. live read-only verification прошёл успешно;
3. после этой проверки выполнен `Dry run`;
4. нет другого активного run этого сервиса;
5. platform-admin явно подтвердил реальный перенос.

## Хранение credentials

Legacy-пароли не пишутся в git, frontend storage или migration logs. Они шифруются отдельным `MigrationCredentialCipher` и сохраняются только в приватной metadata DB migration-service. Cipher напрямую использует постоянный `MIGRATION_APP_KEY` из process environment и не зависит от Laravel `Crypt`/`config('app.key')`; это гарантирует одинаковое шифрование в API и background worker даже при долгоживущем queue process. Новые payload имеют версионированный префикс `migration:v1:`.

`MIGRATION_APP_KEY` генерируется один раз на сервере при deploy, сохраняется в серверном `.env` и прокидывается в оба контейнера одновременно как `MIGRATION_APP_KEY` (а `APP_KEY` остаётся тем же значением для самого Laravel). `verify-migration.sh` проверяет обе переменные у API и worker против серверного `.env`. После перехода со старого Laravel-Crypt формата оператор один раз повторно вводит пароль legacy-подключения; следующий save сохраняет его уже в `migration:v1`.

При редактировании формы пустой password означает «оставить текущий пароль». Любое изменение параметров подключения сбрасывает статус verification, поэтому перед следующим запуском требуется повторная live-проверка.

После завершения всего проекта миграции credentials следует удалить из панели, runtime migration-service выключить, а ключ/volume архивировать или уничтожить согласно принятой политике хранения migration metadata.

## Критическое правило безопасности legacy DB

Migration-service никогда не должен иметь write-доступ к старым БД.

Для каждого legacy-сервиса DBA должен выдать отдельные credentials роли, которая имеет только минимально необходимое чтение. Migration-service сам не создаёт пользователей, не выполняет `GRANT/REVOKE` и не меняет настройки/схему старой БД.

Перед каждой операцией `LegacyReader` заново применяет сохранённый профиль подключения и проверяет его. Сохранённая отметка `verified` не заменяет live-check внутри run.

Приложение применяет несколько уровней защиты:

1. оператор обязан явно подтвердить использование read-only учётной записи;
2. соединение переводится в `default_transaction_read_only=on` только на уровне клиентской сессии;
3. каждый extraction query выполняется в отдельной PostgreSQL transaction с `SET TRANSACTION READ ONLY`;
4. установлены короткие `statement_timeout`, `lock_timeout`, `idle_in_transaction_session_timeout`;
5. `LegacyReader` принимает только SQL, начинающийся с `SELECT`;
6. запрещены multiple statements, comments, locking SELECT, mutating keywords и известные side-effect PostgreSQL functions;
7. перед чтением проверяется, что роль не является `SUPERUSER`, не имеет `CREATEDB`, `CREATEROLE`, `REPLICATION`, `BYPASSRLS`;
8. проверяется отсутствие `INSERT/UPDATE/DELETE/TRUNCATE/TRIGGER` на пользовательских таблицах;
9. проверяется отсутствие `CREATE` на базе/пользовательских схемах и `USAGE/UPDATE` на sequences.

Ожидаемый профиль legacy-пользователя — фактически SELECT-only. Если проверка блокируется, ответ должен показывать, какое условие ожидалось и какие запрещённые role attributes/privileges обнаружены у текущего PostgreSQL пользователя. Если не установлен UI-флаг `readonly_acknowledged`, права БД ещё не проверяются и ответ явно сообщает именно об этом.

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
## Проверить результат (Validate)

Операция не переносит и не исправляет данные. Employees сравнивает число legacy employees/departments с числом migration mappings соответствующих типов. Vacations сравнивает число legacy отпусков с числом mappings. Несовпадение даёт `conflicts`. Это проверка полноты сопоставлений, а не сверка каждого поля, фактического существования каждой целевой записи или зарплат. Полная reconciliation остаётся отдельным шагом.

Каждая новая задача содержит короткий SHA-256 fingerprint ключа API. Worker проверяет его против своего ключа до чтения legacy DB. При несовпадении сообщает runtime mismatch; повторный ввод пароля его не устраняет. Ошибки расшифровки отдельно указывают формат payload (migration:v1 либо Laravel Crypt), чтобы не предлагать повторный ввод при любом сбое. Секреты и ciphertext не возвращаются в UI.
