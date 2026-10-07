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

Если PHP не видит постоянный ключ миграции, API отклоняет запуск до создания run с явной ошибкой конфигурации. При отсутствии отдельной переменной `MIGRATION_APP_KEY` допускается только тот же постоянный `APP_KEY` контейнера; deploy проверяет его совпадение с ключом серверного `.env`. Каждый deploy со включённым Migration Service сверяет переменные у API и worker, при расхождении пересоздаёт оба контейнера и проверяет доступность ключа из PHP-процессов. Smoke запускается даже при выпуске другого сервиса, чтобы ошибку не обнаруживал первым оператор Dry run. Он проверяет расшифровку credentials нового формата в API и worker без подключения к legacy DB; старый Laravel Crypt профиль отмечается как требующий повторного сохранения пароля и не блокирует выпуск. Сравнение fingerprint между процессами не блокирует фоновые задачи.

Маршрут `/migration/` при обновлении страницы сначала показывает состояние авторизации, затем после проверки `platform-admin` открывает страницу миграции. Общий дашборд не должен появляться промежуточным кадром. Начально скрытые страницы и карточки остаются скрытыми независимо от CSS layout.

Кнопки и поля модуля блокируются от отправки запроса до финального ответа по конкретному run ID. HTTP 202 означает только очередь. Активная кнопка показывает loading, карточка — операцию, номер запуска и статус; при сбое polling блокировка сохраняется. Активные задачи обновляются каждую секунду, остальные — каждые 5 секунд. Сервер исключает гонку двух запусков одного сервиса с помощью SQLite write-lock.

`Validate` в UI называется «Проверить результат»: сравнивает количество legacy employees/departments либо vacations с количеством migration mappings; не изменяет данные и не сверяет значения полей или зарплаты.

Старое нечитаемое значение пароля не считается рабочим доступом: `/migration/state` показывает `credential_status`, интерфейс требует явно ввести и сохранить пароль, а Verify и создание run возвращают 409 до исправления. Сохранение подтверждается чтением шифртекста обратно из metadata DB и успешной расшифровкой в API.

Ключ нового формата хранится также в `/data/migration-credential.key` внутри приватного общего volume. Файл создаёт HTTP-процесс API атомарно при первом health-запросе; worker только читает его. Это исключает расхождение PHP process environment при сохранении и выполнении. Deploy проверяет расшифровку текущих payload в обоих контейнерах; при отказе выпуск не считается успешным. Файл сохраняется вместе с metadata DB и не должен удаляться при пересоздании контейнеров.

Для Employees legacy-роль должна иметь `USAGE` на схему `public` и `SELECT` на `departments`, `employees`, `employments`, `employee_roles`, `salaries`, `users`, `subcontracts`, `comments`. Для Vacations: `employee`, `vacation`, `vacation_approval`, `vacation_type_dict`. `LegacyReader::assertSafe()` теперь до запуска чтения проверяет весь список и показывает недостающие таблицы/права одной диагностикой. DBA выдаёт минимальные права в исходной БД; migration-service не делает GRANT.

## Обратимый тест реального переноса Employees на стенде

Перед проверкой в действующем тестовом приложении отдельный CI шаг создаёт серверный снимок: архив схемы PostgreSQL `employees` и согласованную копию приватного volume Migration Service (SQLite mappings/runs и файл ключа). Снимок создаётся только при отсутствии активных migration runs; скрипт откажется, если другая схема имеет внешний ключ в `employees`. Дамп остаётся в закрытом каталоге `/opt/irlix-services/.ci/migration-test-snapshots/<CI run ID>` с правами доступа root; в GitHub artifacts он не загружается. Проверка CI должна завершиться успешно и вывести ID снимка **до** нажатия «Перенести».

На время теста publisher `employees-events` остановлен, чтобы события тестового импорта не ушли в RabbitMQ и другие сервисы. После решения оставить результат оператор запускает `docker compose -f docker-compose.yml -f docker-compose.migration.yml --env-file .env start employees-events` на стенде; откат запускает publisher самостоятельно. Не выполнять новый deploy, пока решение не принято: deploy может снова поднять остановленный контейнер.

Снимок создаётся отдельным CI шагом по явному запросу оператора, а не при каждом нажатии «Перенести». Для следующего теста нужен новый ID снимка и успешная проверка его восстановления.

В промежутке между снимком и решением о сохранении результата не редактировать Employees и не создавать сущности других сервисов со ссылками на новых сотрудников. Восстановление возвращает **всю схему Employees и всю metadata мигратора** к моменту снимка: оно удалит также любые другие изменения этих данных, сделанные позже. Текущий test stand на время снимка и восстановления кратко остановит Migration Service; при восстановлении останавливается и Employees API. Остальные схемы PostgreSQL не восстанавливаются. При обнаружении нового запуска миграции другого сервиса или внешних FK откат отказывается работать.

Ручной откат: GitHub Actions → **Restore Employees migration test snapshot** → Run workflow в `main`, указать числовой ID снимка и точное подтверждение `RESTORE EMPLOYEES`. Скрипт проверяет SHA-256 архивов, восстанавливает схему одной транзакцией PostgreSQL, затем SQLite metadata и перезапускает контейнеры. При ошибке восстановления сервисы остаются остановленными, исходный снимок сохраняется для восстановления оператором. Не удалять снимок до решения о сохранении результата.

### Снимок и откат в интерфейсе

Карточка Employees показывает доступные снимки и состояние операции. `platform-admin` нажимает «Создать снимок», ждёт статуса «Готово», выбирает точку отката и запускает перенос. Повторный запуск после завершившегося `failed` может использовать тот же снимок и сохранённые mappings. Для отката выбирается снимок и вводится точное подтверждение `RESTORE EMPLOYEES`; старые снимки не удаляются автоматически. Возврат к снимку удаляет все изменения Employees и metadata Migration Service после даты снимка, включая другие ручные изменения этих областей. При активном переносе снимок и откат запрещены.

Отдельный `migration-ops` контейнер принимает только команды создания и восстановления через приватный Unix-сокет. Только он имеет доступ к Docker socket и каталогу архивов; Migration API получает доступ лишь к Unix-сокету и проверяет `platform-admin` на каждом запросе. Операция асинхронна: временная остановка Migration API не обрывает процесс отката, после запуска страницы продолжают опрашивать состояние. Каталог архивов остаётся на сервере; данные и секреты не отправляются в браузер. При неуспешном восстановлении контейнеры остаются остановленными для ручного восстановления из архива. Ручной workflow GitHub Actions остаётся резервным способом.

## Пульт переноса

Новый интерфейс доступен по `/migration/console/` только роли `platform-admin`. Frontend — самостоятельное приложение `apps/migration` и контейнер `migration-web`, не раздел Portal. Host nginx направляет `/migration/` в web на loopback 8099; API остаётся на 8098. Собственное меню: «Текущий интерфейс» (`/migration/`) и «Пульт переноса» (`/migration/console/`); активный пункт соответствует маршруту. Дашборд доступен через общий переключатель сервисов. Он использует `UiAppShell` и компоненты `@irlix/ui`. Очередь реализованных модулей: Employees → Vacations. Будущие модули видны, но не запускаются.

«Перенести все» создаёт согласованную общую точку Employees/Vacations, затем отдельную точку перед каждым сервисом и последовательно выполняет Inspect → Dry run → Migrate → Validate. Перенос одного сервиса создаёт его точку и выполняет те же этапы. Любая ошибка останавливает очередь; продолжения после перезапуска ops нет. Validate проверяет полноту mappings, а не каждое целевое поле.

Контрольные точки хранятся в `/snapshots/console/<scope>/<id>`: проверенный PostgreSQL dump, SQLite metadata и ключ credentials, checksums и manifest. На время снимка останавливаются соответствующие writers и Migration API/worker; источник legacy всегда read-only. Restore требует `RESTORE ALL`, `RESTORE EMPLOYEES` или `RESTORE VACATIONS`. Активные runs блокируют snapshot/restore. Откат одного сервиса запрещён, если после его точки переносился другой сервис. Неудачный restore оставляет writers остановленными для ручного восстановления. Общий откат восстанавливает обе схемы и metadata; история пульта в ops сохраняется отдельно, поэтому детали старых run после отката могут быть недоступны.

API: `GET /api/migration/console/state`, `POST .../start` (`scope`, `confirm:true`), `POST .../restore` (`scope`, `snapshot_id`, `confirmation`), `GET .../runs/{id}`, `GET .../runs/{id}/conflicts?before=<id>`. Глобальная reservation блокирует конкурентные запуски старой страницы. Host coordinator хранит последнюю операцию и последние 50 операций в `/ops/console.json`; команды строго ограничены сервисами и режимами.

Таблица показывает реальные total/read/success/error/warning counters по legacy-таблицам. Чтение вспомогательной таблицы не считается переносом; неизвестный total не превращается в выдуманный процент. Журнал объединяет события этапов выбранной операции и конфликты, имеет фильтры по severity, сервису и таблице, подгрузку старых сообщений.

Фоновое обновление выполняется без перекрытия запросов (1 секунда при работе, 5 секунд в ожидании). Оно обновляет состояние, а не черновик формы доступа. Значения, password, focus, caret и DOM-поля сохраняются; открытие формы явно загружает сохранённые параметры. Ошибка сети блокирует новые операции и повторяет проверку. Browser regression запускается при отсутствующем Portal и проверяет собственное меню, переход между интерфейсами, сохранность поля через несколько polling-ответов, блокировку повторного запуска, mobile layout и запрет для non-admin.

Проверки: `node --test apps/migration/tests/*.mjs`, browser fixture `apps/migration/tests/migration-console.browser.cjs`, `python -m unittest discover -s scripts/tests -v`, `php tests/console_smoke.php` внутри Laravel migration runtime. Snapshot/restore и импорт реальных БД требуют отдельной операторской проверки; тесты не запускают перенос production-данных.

## Самостоятельный frontend

`apps/migration` имеет собственные package/lock, Vite base `/migration/`, OIDC storage `irlix.migration.auth`, Dockerfile/nginx и CI component `migration-web`. Исходники и тесты обоих интерфейсов перенесены из Portal; Portal не содержит migration API calls, polling, форм, стилей или меню переноса. Общие зависимости — только `packages/ui` и `packages/auth`. Изменение Migration frontend собирает/выкатывает только его web image; изменение Dashboard — только Portal. При недоступном Portal Migration остаётся доступен; при недоступном Migration API web показывает ошибку и запрещает новые операции. Credentials/SQLite, worker, checkpoints и backend API при выделении frontend не меняются.
