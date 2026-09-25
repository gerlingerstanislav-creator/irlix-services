# Deployment

## Стенд

Стенд разворачивается автоматически после успешного push в `main` через GitHub Actions.

Текущая конфигурация сервера:

- 4 vCPU;
- 4 GB RAM;
- 10 GB disk;
- internal IP `192.168.90.100`.

Публичный HTTP вход обслуживает host nginx. Docker-сервисы слушают только loopback интерфейс сервера:

- `127.0.0.1:8080` — Employees web;
- `127.0.0.1:8081` — Platform Core API;
- `127.0.0.1:8082` — Employees API;
- `127.0.0.1:8083` — Design System;
- `127.0.0.1:8084` — Dashboard;
- `127.0.0.1:8085` — Keycloak.

Host nginx маршрутизирует:

- `/` → Dashboard;
- `/employees/*` — Employees web;
- `/design-system/*` — Design System;
- `/keycloak/auth/*` — Keycloak;
- `/api/platform/*` — Platform Core `/api/*`;
- `/api/employees/*` — Employees `/api/*`.

Старый `/auth/*` больше не является маршрутом Keycloak и на стенде возвращает 404.

PostgreSQL, Redis и RabbitMQ наружу не публикуются.

## CI/CD

Основной workflow разделён на независимые jobs:

1. `Detect changes`;
2. `Build frontend`;
3. `Build backend`;
4. `Validate infrastructure`;
5. `Deploy affected services`;
6. `Bootstrap authentication`;
7. `Verify stand`.

Frontend и backend builds выполняются параллельно. Markdown-only изменения и `docs/**` не запускают основной CI.

`Detect changes` сравнивает текущий commit с последним успешным deploy, поэтому изменения, не доехавшие из-за красного CI, не теряются. При этом scope определяется по типу файлов:

- изменения конкретного frontend/backend сервиса собирают и перезапускают только связанные контейнеры;
- `packages/ui` затрагивает только приложения, использующие общую UI-библиотеку;
- `packages/auth` затрагивает auth-dependent frontend и Keycloak bootstrap;
- `infra/**`, `docker-compose.yml` и `.env.example` переводят pipeline в полный runtime rebuild;
- `.github/workflows/**` и `scripts/verify-stand.sh` считаются pipeline/verification-only изменениями: release синхронизируется и полный `Verify stand` выполняется, но application containers из-за этих файлов не пересобираются;
- неизвестные runtime scripts/files по-прежнему консервативно включают полный pipeline.

Это означает, что серия красных CI не должна сама по себе превращать последующий фикс одного сервиса в rebuild всего проекта. Полный rebuild остаётся только там, где накопленный diff действительно содержит infrastructure/runtime изменения с общим влиянием.

Authentication bootstrap выполняется только когда изменились Keycloak/auth/infra области. `--force-recreate` не используется для неизменившихся контейнеров.

## Выкладка

Deploy передаёт release archive на сервер по SSH, обновляет host nginx, запускает нужные Compose services, выполняет Employees/Vacations migrations при необходимости и затем допускает отдельные auth/verify jobs. Даже если rebuild контейнеров не нужен, release archive синхронизируется, чтобы новая версия `scripts/verify-stand.sh` могла быть выполнена на стенде.

Файл `/opt/irlix-services/.env` создаётся из `.env.example` только при первом deploy и далее не перезаписывается. Боевые/стендовые секреты должны храниться только на сервере или в GitHub Secrets.

## Administrators

Для bootstrap временного администратора приложения используется GitHub Actions secret `TEMP_ADMIN_PASSWORD`.

Отдельный `keycloak-admin` для Keycloak Admin Console получает пароль из `KEYCLOAK_ADMIN_PWD`. Admin Console доступна по `/keycloak/auth/admin/`.

## Health / smoke checks

- `/api/platform/health`;
- `/api/employees/health`;
- `/keycloak/auth/realms/irlix/.well-known/openid-configuration`;
- Dashboard HTML и реально сгенерированный JS bundle;
- Keycloak authorization endpoint с `client_id=irlix-services-web`;
- анонимный доступ к защищённым Employees endpoints должен получать `401`.

Employees health endpoint остаётся публичным для инфраструктурного мониторинга. Остальные Employees API endpoints требуют Keycloak Bearer token и текущую bootstrap-role `platform-admin`.

## Capacity rule

Расширение 4 vCPU / 4 GB RAM / 10 GB disk не выполняется заранее. Перед увеличением ресурсов фиксируется конкретный bottleneck: CPU saturation, RAM/OOM, disk pressure, latency или рост количества контейнеров. Сначала проверяются очистка Docker cache/logs, retention и оптимизация конкретного сервиса; затем предлагается конкретное расширение с обоснованием.

## Further optimization

Если feedback снова станет узким местом, следующий приоритет — persistent BuildKit/GitHub Actions cache либо build/publish immutable images один раз в CI с последующим deploy готовых images. Health/auth проверки ради скорости не отключаются.
