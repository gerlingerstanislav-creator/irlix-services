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
- `/employees/*` → Employees web;
- `/design-system/*` → Design System;
- `/auth/*` → Keycloak;
- `/api/platform/*` → Platform Core `/api/*`;
- `/api/employees/*` → Employees `/api/*`.

PostgreSQL, Redis и RabbitMQ наружу не публикуются.

## Выкладка

CI выполняет:

1. `docker compose config`;
2. сборку Docker images;
3. передачу release archive на сервер по SSH;
4. установку nginx/Docker на чистом Debian/Ubuntu сервере при необходимости;
5. `docker compose up -d --build --remove-orphans` в `/opt/irlix-services`;
6. миграции Employees;
7. идемпотентный Keycloak bootstrap;
8. проверку Dashboard, Employees, Design System, backend health endpoints и OIDC discovery;
9. проверку, что защищённые Employees endpoints не доступны анонимно.

`--force-recreate` не используется: неизменившиеся PostgreSQL, Redis, RabbitMQ и Keycloak не должны перезапускаться при каждой выкладке.

Файл `/opt/irlix-services/.env` создаётся из `.env.example` только при первом deploy и далее не перезаписывается. Боевые/стендовые секреты должны храниться только на сервере или в GitHub Secrets.

## Temporary platform admin

Для bootstrap первого администратора используется GitHub Actions secret `TEMP_ADMIN_PASSWORD`.

Во время deploy `infra/keycloak/bootstrap.sh` создаёт или обновляет пользователя `admin`, назначает email `admin@irlix.ru`, устанавливает пароль из секрета и назначает realm-role `platform-admin`. Пароль в репозитории не хранится и в логах не выводится.

## Health checks

- `/api/platform/health`
- `/api/employees/health`
- `/auth/realms/irlix/.well-known/openid-configuration`

Employees health endpoint остаётся публичным для инфраструктурного мониторинга. Остальные Employees API endpoints требуют Keycloak Bearer token и текущую bootstrap-role `platform-admin`.

## Capacity rule

Расширение 4 vCPU / 4 GB RAM / 10 GB disk не выполняется заранее. Перед увеличением ресурсов фиксируется конкретный bottleneck: CPU saturation, RAM/OOM, disk pressure, latency или рост количества контейнеров. Сначала проверяются очистка Docker cache/logs, retention и оптимизация конкретного сервиса; затем предлагается конкретное расширение с обоснованием.

## CI performance

Сейчас pipeline остаётся намеренно простым и надёжным. Основной расход времени — чистая сборка Docker images на GitHub-hosted runner и server-side build/deploy. При текущей длительности порядка нескольких минут отдельная сложная оптимизация не обязательна.

Если CI начнёт стабильно занимать более ~3–5 минут и мешать итерациям, следующий приоритет оптимизации: persistent BuildKit/GitHub Actions layer cache либо сборка/publish images один раз в CI с последующим deploy готовых images. Проверки build/verify ради скорости не отключаются.
