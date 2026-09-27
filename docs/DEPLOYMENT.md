# Deployment

## Стенд

Стенд автоматически разворачивается после успешного push в `main` через GitHub Actions. `development` используется как интеграционная ветка и не деплоится.

Текущая конфигурация сервера:

- 4 vCPU;
- 4 GB RAM;
- 10 GB disk.

Адрес внутреннего стенда не хранится в репозитории. Источник истины для него — GitHub Secret `IRLIX_LOCAL_URL`. Значение может быть полным URL (`http://...` / `https://...`) или адресом без схемы; deploy нормализует его и записывает в server `.env` как `IRLIX_PUBLIC_URL`, `KEYCLOAK_PUBLIC_URL` и `KEYCLOAK_ISSUER`.

Host nginx слушает `:80`, Docker-сервисы опубликованы только на loopback:

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

Внешние маршруты:

- `/` → Dashboard;
- `/employees/`, `/vacations/`, `/clients/`, `/timesheets/`, `/design-system/` → frontend-приложения;
- `/api/platform/`, `/api/employees/`, `/api/vacations/`, `/api/clients/`, `/api/timesheets/` → backend API;
- `/keycloak/auth/` → Keycloak.

PostgreSQL, Redis и RabbitMQ наружу не публикуются.

## PostgreSQL

На старте используется одна физическая PostgreSQL с отдельными schema/DB users. Для Timesheets:

- schema: `timesheets`;
- DB user: `timesheets_app`;
- bootstrap: `infra/postgres/init/001-init-schemas.sh`;
- переменные: `TIMESHEETS_DB_USER`, `TIMESHEETS_DB_PASSWORD`.

При первом deploy Timesheets CI добавляет безопасные значения этих переменных в существующий server `.env`, если их ещё нет, затем повторно запускает idempotent schema bootstrap и migration сервиса.

## CI/CD

Основной workflow:

1. `Detect changes`;
2. `Build frontend`;
3. `Build backend`;
4. `Validate infrastructure` (`docker compose config --quiet`);
5. `Deploy affected services`;
6. `Bootstrap authentication and verify stand`.

При deploy значение `IRLIX_LOCAL_URL` обязательно. Оно не выводится в код или документацию и применяется только как runtime-конфигурация стенда.

Timesheets участвует в path-aware change detection:

- `apps/timesheets/**` → `timesheets-web`;
- `services/timesheets/**` → `timesheets`;
- изменения `packages/ui` и `packages/auth` пересобирают Timesheets frontend;
- инфраструктурные изменения выполняют полный runtime deploy.

Deploy Timesheets выполняет `php artisan migrate --force` и не отключает проверки при ошибке.

## Smoke verification

Общий `scripts/verify-stand.sh` получает адрес стенда из runtime `.env` и проверяет платформу и существующие сервисы без жёстко заданного IP. Дополнительно `scripts/verify-timesheets.sh` проверяет:

- `/timesheets/` и реально сгенерированный JS asset;
- `/api/timesheets/health` и доступность DB;
- наличие Timesheets migration;
- обязательный `401` для анонимного запроса к `/api/timesheets/workspace`.

Если Timesheets backend/frontend не стартует, smoke script выводит хвост логов соответствующего контейнера и завершает workflow ошибкой.

## Keycloak

Keycloak bootstrap выполняется только когда scope изменений затрагивает auth/infra. Приложения используют общий browser OIDC-клиент `packages/auth`, backend API валидируют Bearer JWT. Redirect URI и Web Origin для стенда формируются из runtime `IRLIX_PUBLIC_URL`; в репозитории остаются только безопасные localhost defaults.

Секреты хранятся только в GitHub Secrets или server `.env`; `.env` на сервере не перезаписывается последующими release archive, но runtime URL-переменные синхронизируются из `IRLIX_LOCAL_URL` при каждом deploy.

## Capacity rule

Ресурсы стенда не увеличиваются заранее. Перед расширением фиксируется конкретный bottleneck (CPU, RAM/OOM, disk pressure, latency или рост числа контейнеров), затем выбирается минимально необходимое изменение.
