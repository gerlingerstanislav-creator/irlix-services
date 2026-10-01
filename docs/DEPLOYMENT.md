# Deployment

## Стенд

Стенд автоматически разворачивается после успешного push в `main` через GitHub Actions. `development` используется как интеграционная ветка и не деплоится.

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
| 8085 | Keycloak |
| 8086 / 8087 | Vacations API / web |
| 8088 / 8089 | Clients API / web |
| 8090 / 8091 | Timesheets API / web |
| 8092 / 8093 | Specialists API / web |
| 8094 / 8095 | Recruitment API / web |
| 8096 | CV converter web |
| 8097 | CV converter API |

Внешние маршруты:

- `/` → Dashboard;
- `/employees/`, `/vacations/`, `/clients/`, `/timesheets/`, `/specialists/`, `/recruitment/`, `/cv-converter/`, `/design-system/` → frontend-приложения;
- `/api/platform/`, `/api/employees/`, `/api/vacations/`, `/api/clients/`, `/api/timesheets/`, `/api/specialists/`, `/api/recruitment/`, `/api/cv-converter/` → backend API;
- `/keycloak/auth/` → Keycloak.

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

## PostgreSQL

На старте используется одна физическая PostgreSQL с отдельными schema/DB users. Schema bootstrap выполняется `infra/postgres/init/001-init-schemas.sh`. Миграции затронутых backend-сервисов запускаются после обновления контейнеров.

## CI/CD

Основной принцип deploy: **build once → GHCR → pull → run**. Docker-образы бизнес-сервисов больше не пересобираются на сервере.

Основной workflow:

1. `Detect changes` определяет затронутые сервисы и формирует frontend/backend matrices;
2. frontend-сборки запускаются отдельными параллельными jobs вида `Build & publish frontend / <service>`;
3. backend-сборки запускаются отдельными параллельными jobs вида `Build & publish backend / <service>`;
4. каждый job собирает Docker image один раз, тегирует SHA текущего commit и публикует image в GitHub Container Registry (`ghcr.io`);
5. `Validate infrastructure` проверяет объединённую Compose-конфигурацию;
6. `Pull & deploy affected services` на сервере обновляет image tags только затронутых сервисов, выполняет `docker compose pull` и `up --no-build`;
7. затем выполняется `Bootstrap authentication and verify stand`.

Deployment overlay хранится в `docker-compose.images.yml`. CV iteration 2 дополнительно использует `docker-compose.cv.yml`. До включения CV frontend/backend в общую GHCR matrix они собираются на стенде при full deploy; `cv-llm` использует готовый llama.cpp image, а веса модели кешируются отдельно.

Каждый deployable image имеет собственную переменную тега (`CLIENTS_IMAGE_TAG`, `CLIENTS_WEB_IMAGE_TAG`, `TIMESHEETS_IMAGE_TAG` и т.д.). При выборочном deploy CI обновляет на сервере только теги затронутых сервисов. Это позволяет неизменённым сервисам продолжать использовать ранее проверенные образы, даже если текущий commit их не собирал.

`employees` и `employees-events` используют один image, поскольку это одна кодовая база с разными командами запуска.

GitHub Actions имеет `packages: write` для публикации GHCR. Во время deploy текущий `GITHUB_TOKEN` используется для временной авторизации Docker на сервере и не хранится в репозитории.

Сервис, который не затронут изменением, не попадает в соответствующую build matrix. Если frontend или backend сборки не нужны, matrix создаёт служебный `not-required` job, чтобы downstream deploy сохранял стабильную зависимость от build stage.

Path-aware detection охватывает Portal, Employees, Vacations, Clients, Timesheets, Specialists, Recruitment, Design System и Platform Core. CV iteration 2 пока попадает в full mode через новые paths; отдельный workflow `cv-converter-check.yml` проверяет compose, сборку frontend/backend и DOCX/PDF render smoke fixture.

Recruitment входит в общий GHCR build/deploy. Дополнительный `recruitment-smoke.yml` после основного CI больше не пересобирает Recruitment на сервере и использует уже развёрнутые images.

При deploy значение `IRLIX_LOCAL_URL` обязательно. Оно не выводится в код или документацию и применяется только как runtime-конфигурация стенда.

## Smoke verification

Общий `scripts/verify-stand.sh` получает адрес стенда из runtime `.env` и проверяет платформу и существующие сервисы без жёстко заданного IP. Дополнительно `scripts/verify-timesheets.sh` проверяет Timesheets frontend/API/migration/auth guard. Recruitment имеет отдельный post-CI smoke workflow. Для CV readiness сам deploy ждёт успешный `/api/cv-converter/health/llm` через loopback backend.

Если контейнер не стартует или smoke-проверка не проходит, workflow завершается ошибкой; проверки не отключаются ради успешного deploy.

## Capacity diagnostics

`scripts/server-capacity.sh` выполняет read-only диагностику текущей VM и выводит:

- количество CPU, load average и uptime;
- использование RAM/swap;
- использование корневого filesystem;
- размер `/opt`, `/var/lib/docker`, `/var/log` и `/tmp`;
- live CPU/RAM/IO каждого запущенного Docker-контейнера;
- Docker `images`, `containers`, `volumes` и build cache.

Workflow `.github/workflows/capacity-report.yml` запускает этот отчёт на стенде через существующий deploy SSH. Он доступен через `workflow_dispatch` и автоматически выполняется при изменении самого diagnostic script/workflow в `main`. После установки CV LLM capacity report используется для фиксации фактического RAM/CPU/disk consumption модели под реальными конвертациями.

## Keycloak

Keycloak bootstrap выполняется только когда scope изменений затрагивает auth/infra. Приложения используют общий browser OIDC-клиент `packages/auth`, backend API валидируют Bearer JWT. CV backend также валидирует Keycloak JWT по внутреннему JWKS endpoint. Redirect URI и Web Origin для стенда формируются из runtime `IRLIX_PUBLIC_URL`; в репозитории остаются только безопасные localhost defaults.

Секреты хранятся только в GitHub Secrets или server `.env`; `.env` на сервере не перезаписывается release archive. Runtime URL и image-tag переменные обновляются управляемо deploy-скриптом.

## Capacity rule

Ресурсы стенда не увеличиваются заранее. Перед расширением фиксируется конкретный bottleneck (CPU, RAM/OOM, disk pressure, latency или рост числа контейнеров), затем выбирается минимально необходимое изменение.
