# Deployment

## Защита активной миграции во время релиза

При выпуске изменений `migration`, `migration-worker`, `migration-ops`
либо при вынужденной синхронизации ключей deployment **до пересоздания
контейнеров** запускает read-only preflight внутри текущего
`migration-ops` (см. `scripts/ci/check_migration_release_safety.py`).
Проверяются сохранённая операция в `/ops/console.json`, операция со
снимком/restore в `/ops/status.json` и SQLite-очередь
`migration_runs` в `/data/migration.sqlite`.

При статусах `queued`/`running`, отсутствии или повреждении
проверяемых данных, недоступности `migration-ops` при работающих
контейнерах Migration выпуск **прерывается до изменения работающих
контейнеров**. Это защитная блокировка, а не ошибка приложения:
администратор должен выяснить статус worker, при необходимости
дождаться безопасной остановки и затем повторить штатный релиз.
Нельзя считать отсутствие ответа от worker подтверждением завершения
или очищать метаданные для обхода preflight. Впервые создаваемый
пустой стенд, где ещё нет runtime Migration, допускается к bootstrap.

Обновление одного `main` не означает успешную выкладку: необходимы
успешные production CI, deploy и smoke.

## Стенд

Production-стенд автоматически обновляется после успешного release-push в `main` через GitHub Actions. `development` — накопительная интеграционная ветка: до готовности отдельного TEST окружения она проходит CI/build, но **не деплоится**. Release всегда включает всё проверенное состояние `development`, а не выборочные задачи. Команда «кати в прод» — окончательное разрешение: если CI development или проверка кандидата ещё выполняются/стоят в очереди, нужно дождаться их успешного завершения и продолжить без повторного вопроса. Workflow `release.yml` ожидает оба check до 90 минут; при ошибке CI её надо диагностировать, исправить в development и продолжить в рамках той же команды, не выкатывая непроверенный SHA.

Текущая конфигурация сервера по последнему фактическому capacity report:

- 4 vCPU;
- 4 GB RAM;
- 45 GB root disk;
- около 36 GB свободно до установки локальной CV-модели.

Адрес внутреннего стенда не хранится в репозитории. Источник истины для него — GitHub Secret `IRLIX_LOCAL_URL`. Значение может быть полным URL (`http://...` / `https://...`) или адресом без схемы; deploy нормализует его и записывает в server `.env` как `IRLIX_PUBLIC_URL`, `KEYCLOAK_PUBLIC_URL` и `KEYCLOAK_ISSUER`.

Host nginx слушает `:80`, Docker-сервисы опубликованы только на loopback. Конфигурация host nginx хранится в `infra/nginx/irlix-services.conf`.

| Порт | Сервис |
|---|---|
| 8080 | Employees web |
| 8081 | Platform Core API |
| 8082 | Employees API |
| 8083 | Design System |
| 8084 | Dashboard |
| 8102 | System frontend (only platform-admin) |
| 8103 | Finance frontend |
| 8104 | Finance API |
| 8085 | Keycloak |
| 8086 / 8087 | Vacations API / web |
| 8088 / 8089 | Clients API / web |
| 8090 / 8091 | Timesheets API / web |
| 8092 / 8093 | Specialists API / web |
| 8094 / 8095 | Recruitment API / web |
| 8096 | CV converter web |
| 8097 | CV converter API |
| 8098 / 8099 | Migration API / web |

Внешние маршруты:

- `/` и `/tasks/` → Dashboard;
- `/system/` → System (Resource Monitor, Service Map, Migration Console); старые `/resources/`, `/service-map/`, `/migration/` перенаправляются в System;
- `/employees/`, `/vacations/`, `/clients/`, `/timesheets/`, `/specialists/`, `/recruitment/`, `/cv-converter/`, `/design-system/` → frontend-приложения;
- `/api/platform/`, `/api/employees/`, `/api/vacations/`, `/api/clients/`, `/api/timesheets/`, `/api/specialists/`, `/api/recruitment/`, `/api/cv-converter/` → backend API;
- `/keycloak/auth/` → Keycloak.

System frontend включён в штатный Compose registry как `docker-compose.system.yml`, сборка `apps/system/Dockerfile`, публикуется только на loopback. Не развёртывать его в отдельный production релиз без обычного накопительного проверенного перехода `development` → `main`.

PostgreSQL, Redis, RabbitMQ и локальный `cv-llm` наружу не публикуются.

## CV converter local LLM

CV iteration 2 подключается через отдельный overlay `docker-compose.cv.yml`.

По умолчанию:

- `CV_LLM_PROVIDER=local`;
- inference — `llama.cpp` CPU server;
- модель — Cotype Nano 1.5B, quantization Q4_K_M;
- модель загружается из Hugging Face при первом старте и кешируется в volume `cv_llm_cache`;
- `cv-llm` ограничен `1600 MB RAM` и `3 CPU`;
- `cv-converter` backend ограничен `512 MB RAM` и `1.5 CPU`;
- backend не сохраняет исходные CV, canonical JSON и renders после запроса.

Deploy после запуска контейнеров ждёт `/api/health/llm` до фактической готовности local inference. Если модель не помещается в лимит RAM или не запускается, deploy завершается ошибкой и выводит последние логи `cv-llm`/`cv-converter`.

Provider переключается через `CV_LLM_PROVIDER` без изменения UI и canonical schema. Подготовлены `local`, `gigachat`, `yandex`, `mws`/`openai_compatible`. Внешние credentials хранятся только в server `.env`/Secrets и при local mode не требуются.

## Защищённое включение ролей Keycloak

`IRLIX_AUTHZ_JWT_ENABLED` включается только после безопасного backfill
`Employees → Keycloak` и независимого подтверждения фактических
ролей/permissions/scopes, доступа в каждом сервисе и готового rollback.
Доставка контейнеров сама по себе не означает успешную активацию.

Инциденты 21.10.2026:
- На первом rollout обычный `docker compose up --force-recreate`
  пересоздал Keycloak/PostgreSQL, вызвал `cURL error 7` и длинные
  отложенные повторы. Исправлено `--no-deps`, Admin API preflight
  и ограниченным повтором временных ошибок.
- Следующий production CI `39656841368`: `24/24` привязанных identities
  оставались `retry`, все `HTTP 400 at user_marker_update`.
  Keycloak 26 не принял запись нестандартного `irlix_managed_roles`
  в профиле пользователя. Скрипт корректно отменил JWT-only переключение.
- Старый статус `synced >= identities` был небезопасен: учитывал
  синхронизированные записи *без* Keycloak identity (`synced=456`)
  и мог ложно подтвердить готовность при сбое всех 24 привязанных.

Исправление в PR #499:
1. Убрать `PUT /users/{id}` для служебного `irlix_managed_roles`.
   Уже существующее поле Keycloak читать только для миграционной
   совместимости. Сохранять список ранее управляемых ролей в
   `Employees.keycloak_auth_sync.managed_roles` (`jsonb`).
2. Выполнить начальную повторную сверку всех привязанных identities
   после миграции. Каждую операцию назначения делать через
   минимальную `RoleRepresentation {id,name}`.
3. После записи заново прочитать эффективные realm roles через
   `/users/{id}/role-mappings/realm/composite`: все ожидаемые
   должны присутствовать, непредусмотренных IRLIX roles быть не должно,
   в том числе доставленных Keycloak-группами. Только после
   подтверждения сохранять `managed_roles` и `synced`.
4. `authz-status.php` требует `synced` и ненулевой проверенный
   `managed_roles` для **каждой** связанной учётной записи, а также
   нулевые pending/processing/retry/failed. Не полагаться на общий счётчик.
5. Синхронизатор и защищённые сервисы пересоздаются `--no-deps`
   только после Keycloak Admin API preflight. При проблеме worker
   выключается и legacy вход остаётся активным.

**Security gate:** присутствие маркера и успешного read-back ещё
не заменяет E2E подтверждения *реально выданных JWT* и бизнес-матриц.
Для особо чувствительных операций старые access JWT действуют
до истечения TTL (сейчас 600 секунд). Перед production JWT-only
проверить безопасный отзыв прав и сохранённые копии состояния
Keycloak/Employees; иначе остановить rollout.

Тесты: `python3 -m unittest discover -s scripts/tests
-p 'test_keycloak_authz_rollout.py' -v`, PHP lint/QA и независимая
интеграция с изолированным Keycloak 26
(`.github/workflows/keycloak-authz.yml`). Без production E2E
не заявлять `rollout PASS`.

## PostgreSQL

На старте используется одна физическая PostgreSQL с отдельными schema/DB users. Schema bootstrap выполняется `infra/postgres/init/001-init-schemas.sh`. Миграции затронутых backend-сервисов запускаются после обновления контейнеров.

## CI/CD

Обязательная схема веток, регистрация новых сервисов, кеширование и аудит лишних сборок описаны в `docs/CI.md`.

Единый CI собирает и проверяет затронутые образы после push в `development`. В `main` используются те же проверенные GHCR images по hash build inputs; если образа для итоговых inputs нет, собирается только недостающий компонент. Deployment допускается после подтверждённого fast-forward `main` до проверенного SHA `development`; CI дополнительно проверяет происхождение release-коммита. Для GitHub Actions `GITHUB_TOKEN` release workflow отдельно запускает production CI через `workflow_dispatch` с `deploy_release=true`. Автоматические дублирующие PR-сборки CV/Migration и отдельный Recruitment bootstrap workflow удалены; их полезные проверки перенесены в общий pipeline.

`infra/ci/services.json` задаёт компоненты, image tag variables, worker aliases, PostgreSQL migrations и smoke URLs. Compose задаёт build/runtime configuration. План `.ci/deploy-plan.json` сравнивает изменения с последним успешным CI/release соответствующей ветки; shared frontend packages не пересобирают backend. Image overlay `docker-compose.images.yml` генерируется из registry. Все overlays, включая CV и Migration, проходят общий `docker compose config`.

Стенд выполняет только `pull` и `up --no-build`, bootstrap необходимых схем, migrations выбранных backend и smoke. Frontend-only релиз не меняет backend tag и не выполняет его migrations. Model readiness/real-inference smoke CV запускается только при изменении CV backend/model/runtime. Настройки/auth bootstrap Keycloak обновляются только при его изменениях.

## Promotion and future TEST stand

Production выпускать только по явному запросу: проверить успешный `CI verified` для точного SHA `development`, показать полный diff с `main`, удостовериться в fast-forward и отсутствии параллельного изменения вершины. `main` не принимает прямые hotfix, cherry-pick или squash. Для ручного запуска предусмотрен `.github/workflows/release.yml`, который продвигает ref и dispatch-ит prod CI. Отдельный GitHub ruleset должен требовать на точном SHA два успешных GitHub Actions checks: `CI verified` и `Development release candidate`. Это предотвращает запись новых непроверенных коммитов напрямую в `main`, сохраняя возможность fast-forward через текущий `GITHUB_TOKEN`, без нового GitHub App или SSH-ключа. Одна лишь проверка CI после push не заменяет required status checks перед push. См. ограничения и настройку в `docs/CI.md`.

Планируемый TEST: отдельный стенд, доменное имя/URL, SSH-учётная запись, окружение `testing` в GitHub, `TEST_DEPLOY_HOST/PORT/USER/SSH_KEY`, изолированная БД/Keycloak/volumes и независимые credentials. После подготовки `development` автоматически разворачивается на TEST, а `main` остаётся единственным production-триггером. До подключения второго окружения любые development-deploy и доступ к production credentials исключены.

## Smoke verification

Сохраняются общие `scripts/verify-stand.sh` и `scripts/verify-timesheets.sh`. `scripts/ci/verify_plan.py` автоматически проверяет выбранные контейнеры и зарегистрированные health/page endpoints, затем дополнительные integration scripts. Для `finance`, `finance-web` и прямых source backend `clients`/`timesheets`/`employees` post-deploy verification дополнительно проверяет страницу `/finance/`, DB-backed health Finance, анонимные GET-отказы 401 для четырёх финансовых API и read-only source smoke из контейнера Finance (успешный configured token, отказ 403 без data для отсутствующего/неверного token; без редиректов и вывода приватных ответов). Это расширяет только проверки, не состав restart/build/migrations. Recruitment проверяется в том же release, без повторного bootstrap в отдельном workflow. Migration image до публикации проходит чистый SQLite bootstrap, CV image — offline DOCX/PDF rendering; к legacy DB в CI никто не подключается.

Провал проверки завершает CI/release ошибкой. Проверенные image tags сохраняются в `.env`; предыдущие refs — в `.ci/previous-image-tags.env`. Приложения на сервере не собираются, image/build cache не очищается перед каждым deploy. Cleanup — отдельная обслуживающая операция.

## Capacity diagnostics

`scripts/server-capacity.sh` выполняет read-only диагностику текущей VM и выводит:

- количество CPU, load average и uptime;
- использование RAM/swap;
- использование корневого filesystem;
- размер `/opt`, `/var/lib/docker`, `/var/log` и `/tmp`;
- live CPU/RAM/IO каждого запущенного Docker-контейнера;
- Docker `images`, `containers`, `volumes` и build cache.

Workflow `.github/workflows/capacity-report.yml` запускает этот отчёт на стенде через существующий deploy SSH. Он доступен через `workflow_dispatch` и автоматически выполняется при изменении самого diagnostic script/workflow в `main`. После установки CV LLM capacity report используется для фиксации фактического RAM/CPU/disk consumption модели под реальными конвертациями.

**Production Docker storage recovery (21.10.2026):** если свободное место Docker Root ниже 8 GiB, перед пакетным pull релиза удаляются *только* неиспользуемые Docker images и build-cache (`docker image prune --all --force`, `docker builder prune --all --force`). Работающие/остановленные контейнеры и ссылки на их образы сохраняются; volumes, PostgreSQL, Keycloak и файлы не удаляются. Если места всё равно не хватает, релиз честно падает без автоматического уничтожения данных; нужна ручная оценка диска или увеличение раздела.

## Keycloak

Keycloak bootstrap выполняется только когда scope изменений затрагивает auth/infra. Приложения используют общий browser OIDC-клиент `packages/auth`, backend API валидируют Bearer JWT. CV backend также валидирует Keycloak JWT по внутреннему JWKS endpoint. Redirect URI и Web Origin для стенда формируются из runtime `IRLIX_PUBLIC_URL`; в репозитории остаются только безопасные localhost defaults.

Секреты хранятся только в GitHub Secrets или server `.env`; `.env` на сервере не перезаписывается release archive. Runtime URL и image-tag переменные обновляются управляемо deploy-скриптом.

## Capacity rule

Ресурсы стенда не увеличиваются заранее. Перед расширением фиксируется конкретный bottleneck (CPU, RAM/OOM, disk pressure, latency или рост числа контейнеров), затем выбирается минимально необходимое изменение.

## Снимок для теста миграции Employees

Только release с маркером `[migration-test-snapshot]` в сообщении main-коммита после успешной проверки стенда выполняет `scripts/migration_test_snapshot.py`. Backup PostgreSQL схемы Employees и приватной SQLite Migration Service хранится на сервере в root-only каталоге, не в CI artifacts. Восстановление доступно только как отдельный ручной workflow `migration-test-rollback.yml` с ID снимка и явным подтверждением; оно возвращает Employees и metadata мигратора на момент снимка и не трогает схемы остальных сервисов. До восстановления нельзя редактировать Employees либо использовать новые employee ID в других сервисах.

## Компактная форма авторизации

Тема `infra/keycloak/themes/irlix/login` наследует стандартные шаблоны Keycloak. Карточка имеет максимальную ширину 420 px и естественную высоту; экран центрирует логотип и карточку вместе, при нехватке высоты доступен обычный вертикальный скролл. Поля 32 px, текст 13 px, радиус 10 px и нейтральная рамка соответствуют tokens `packages/ui`. Тема работает вне Vue shell и содержит соответствующие CSS custom properties; при изменении общих control tokens сверять эту тему.

Кнопка `data-password-toggle` расположена внутри правой части поля с резервом под иконку, без отдельной рамки, с видимым клавиатурным focus. Показ пароля остаётся штатным поведением Keycloak. «Запомнить меня» выровнено через inline-flex, без абсолютного позиционирования checkbox. На узких и низких экранах уменьшаются отступы; строка опций переносится по необходимости. Проверены соответствующие классы и атрибуты шаблона Keycloak 26.7.0; визуальная проверка на реальном экране входит в smoke авторизации.

Migration frontend регистрируется независимо как `migration-web` (`MIGRATION_WEB_IMAGE_TAG`, loopback 8099). Образ собирается из `apps/migration` + shared UI/auth и имеет locked npm dependencies. `/migration/` и вложенные маршруты получают одну SPA от его nginx; снаружи host nginx удаляет prefix при proxy. Backend/worker/ops продолжают использовать Migration overlay. Dashboard/Portal не обслуживает страницы переноса.


CI liveness: every CI job has an explicit timeout (changes 10 min, image build 35 min, result gate 3 min, deployment 20 min, post-deploy 75 min including an optional checkpoint). SSH setup is capped at 2 min, upload at 5, deployment step at 15, Keycloak at 8, stand verification at 7, browser smoke at 5, optional checkpoint at 60. SSH uses BatchMode, 15-second connect/keepalive intervals and three missed keepalives; host-key verification remains enabled. Failure gates use `!cancelled()` instead of `always()` so normal cancellation can stop running verification. Tests and success gates remain mandatory; a timeout fails the release. GitHub queue/service availability is outside these running-job limits.


## Timesheets reconciliation — 08.10.2026

The Timesheets release updates Clients API, Timesheets API/web and starts the `timesheets-reconcile` alias from the same Timesheets image. Deploy generates a stable random `IRLIX_TIMESHEETS_INTEGRATION_TOKEN` in server `.env` before starting the two APIs and worker. Health/migration checks remain in the common planner. The worker retries dependency/DB failures every 60 seconds; inspect `docker compose logs timesheets-reconcile` if cleanup is delayed. It has no published port.


## Four-hour idle sessions — 08.10.2026

The auth release applies `irlix` realm/client session policy with bootstrap: access token 600 seconds, SSO idle 18000 seconds, absolute maximum 86400 seconds, matching Remember Me limits and inherited client idle/max limits. This allows a four-hour inactive tab to refresh on its next request without relying on browser timers. If a session was already expired before deployment, the employee needs one fresh sign-in. The `master` realm policy remains independent.

## Ресурсный монитор

`docker-compose.resources.yml` зарегистрирован в общем CI registry и добавляет изолированный collector с постоянным volume истории. Platform Core читает volume только read-only; раздел Dashboard `/resources/` доступна `platform-admin` и `system-admin`. Health `/api/platform/resources/health` проверяет свежий полный snapshot без раскрытия метрик. Установка не меняет лимиты остальных сервисов или ресурсы VM. Подробности: [RESOURCE_MONITOR.md](RESOURCE_MONITOR.md).

Collector дополнительно измеряет Docker writable layers/volumes фоново раз в 5 минут. Его container healthcheck требует успешный свежий дисковый замер; CPU/RAM сбор не блокируется. Portal показывает общую цветовую легенду трёх кольцевых диаграмм и компактную таблицу.

## Host RAM sampler

To avoid 96 MiB container memory virtualization, a resource-monitor release installs `irlix-resource-host-memory.service` and `.timer` on the Docker VM via the normal deploy SSH job. Every ~10 seconds it writes only `/proc/meminfo` aggregate RAM/swap counters to the existing Docker `resource_monitor_data` volume. This does not grant the collector extra permissions. Deployment checks a real RAM sample (`memory_source=host`) rather than only the freshness of unrelated CPU and disk values. Monitor releases fail verification if host sampling cannot be installed or no trustworthy RAM sample is produced. See `docs/RESOURCE_MONITOR.md`.


Optional rollback integration check may be skipped for unrelated releases. Production deploy uses an explicit `!cancelled()` status condition and requires successful `changes` and `verified` jobs, so GitHub's implicit success() over skipped ancestors cannot silently skip deployment. Main/event and deploy-plan gates remain mandatory; failed or cancelled verification never permits deployment. The production baseline continues to use the last successful deploy, rather than a green build-only main run.

## Единая иконка браузерной вкладки

Все frontend-приложения (`apps/*/index.html`) используют общий favicon Dashboard: `<link rel="icon" type="image/svg+xml" href="/favicon.svg" />`. Единственным источником SVG является `apps/portal/favicon.svg`: Portal публикует его в корне домена, а host nginx маршрутизирует `/favicon.svg` в Portal. При обновлении значка меняется только этот файл; в сервисах не следует создавать локальные версии или ссылаться на локальные пути `/employees/favicon.svg`, `/migration/favicon.svg`, `/design-system/favicon.svg`. В локальном standalone Vite dev server абсолютный `/favicon.svg` требует проксирования корневого пути на Portal или собственного dev-asset; на общем production-стенде работает без дополнительных настроек.


## QA gate

Development/main CI requires the independent QA checks job before CI verified. PR QA also exercises the safe Migration Python coordinator regression suite (`scripts/tests/test_migration_control.py`) in addition to Node contract tests. It runs only with synthetic local files/SQLite and uploads QA artifacts, without using production credentials. Interactive browser and role-based E2E require a separate TEST environment with isolated identities/data; production verification remains its own post-deploy smoke. See [QA.md](QA.md).


QA synthetic-browser checks run only on GitHub-hosted runners with localhost Vite previews and fixture-backed routes. They need no production SSH, Keycloak or database. Failure blocks CI verified; full authenticated TEST and real backend integration remain future stages. See [QA.md](QA.md).
