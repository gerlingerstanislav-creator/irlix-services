# Deployment

## Стенд

Стенд автоматически разворачивается после успешного push в `main` через GitHub Actions. `development` используется как интеграционная ветка и не деплоится.

Текущая конфигурация сервера:

- 4 vCPU;
- 4 GB RAM;
- 10 GB disk.

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

Внешние маршруты:

- `/` → Dashboard;
- `/employees/`, `/vacations/`, `/clients/`, `/timesheets/`, `/specialists/`, `/recruitment/`, `/design-system/` → frontend-приложения;
- `/api/platform/`, `/api/employees/`, `/api/vacations/`, `/api/clients/`, `/api/timesheets/`, `/api/specialists/`, `/api/recruitment/` → backend API;
- `/keycloak/auth/` → Keycloak.

PostgreSQL, Redis и RabbitMQ наружу не публикуются.

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

Deployment overlay хранится в `docker-compose.images.yml`. Базовые `docker-compose.yml`/`docker-compose.override.yml` сохраняют `build:` для локальной разработки, а image overlay задаёт GHCR images для CI/deploy.

Каждый deployable image имеет собственную переменную тега (`CLIENTS_IMAGE_TAG`, `CLIENTS_WEB_IMAGE_TAG`, `TIMESHEETS_IMAGE_TAG` и т.д.). При выборочном deploy CI обновляет на сервере только теги затронутых сервисов. Это позволяет неизменённым сервисам продолжать использовать ранее проверенные образы, даже если текущий commit их не собирал.

`employees` и `employees-events` используют один image, поскольку это одна кодовая база с разными командами запуска.

GitHub Actions имеет `packages: write` для публикации GHCR. Во время deploy текущий `GITHUB_TOKEN` используется для временной авторизации Docker на сервере и не хранится в репозитории.

Сервис, который не затронут изменением, не попадает в соответствующую build matrix. Если frontend или backend сборки не нужны, matrix создаёт служебный `not-required` job, чтобы downstream deploy сохранял стабильную зависимость от build stage.

Path-aware detection охватывает Portal, Employees, Vacations, Clients, Timesheets, Specialists, Recruitment, Design System и Platform Core. Изменения `packages/ui` и `packages/auth` расширяют frontend matrix на зависящие приложения. Инфраструктурные изменения переводят workflow в полный режим и публикуют полный комплект application images.

Recruitment входит в общий GHCR build/deploy. Дополнительный `recruitment-smoke.yml` после основного CI больше не пересобирает Recruitment на сервере и использует уже развёрнутые images.

При deploy значение `IRLIX_LOCAL_URL` обязательно. Оно не выводится в код или документацию и применяется только как runtime-конфигурация стенда.

## Smoke verification

Общий `scripts/verify-stand.sh` получает адрес стенда из runtime `.env` и проверяет платформу и существующие сервисы без жёстко заданного IP. Дополнительно `scripts/verify-timesheets.sh` проверяет Timesheets frontend/API/migration/auth guard. Recruitment имеет отдельный post-CI smoke workflow.

Если контейнер не стартует или smoke-проверка не проходит, workflow завершается ошибкой; проверки не отключаются ради успешного deploy.

## Keycloak

Keycloak bootstrap выполняется только когда scope изменений затрагивает auth/infra. Приложения используют общий browser OIDC-клиент `packages/auth`, backend API валидируют Bearer JWT. Redirect URI и Web Origin для стенда формируются из runtime `IRLIX_PUBLIC_URL`; в репозитории остаются только безопасные localhost defaults.

Секреты хранятся только в GitHub Secrets или server `.env`; `.env` на сервере не перезаписывается release archive. Runtime URL и image-tag переменные обновляются управляемо deploy-скриптом.

## Capacity rule

Ресурсы стенда не увеличиваются заранее. Перед расширением фиксируется конкретный bottleneck (CPU, RAM/OOM, disk pressure, latency или рост числа контейнеров), затем выбирается минимально необходимое изменение.
