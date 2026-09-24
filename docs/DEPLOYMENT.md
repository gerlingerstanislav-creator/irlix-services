# Deployment

## Стенд

Стенд разворачивается автоматически после успешного push в `main` через GitHub Actions.

Публичный HTTP вход обслуживает host nginx. Docker-сервисы слушают только loopback интерфейс сервера:

- `127.0.0.1:8080` — Vue platform shell;
- `127.0.0.1:8081` — Platform Core API;
- `127.0.0.1:8082` — Employees API.

Host nginx маршрутизирует:

- `/` → frontend shell;
- `/api/platform/*` → Platform Core `/api/*`;
- `/api/employees/*` → Employees `/api/*`.

PostgreSQL, Redis и RabbitMQ наружу не публикуются.

## Выкладка

CI выполняет:

1. `docker compose config`;
2. сборку всех Docker images;
3. передачу release archive на сервер по SSH;
4. установку nginx/Docker на чистом Debian/Ubuntu сервере при необходимости;
5. `docker compose up -d --build --remove-orphans` в `/opt/irlix-services`;
6. проверку frontend и health endpoints обоих backend-сервисов;
7. внешнюю HTTP-проверку стенда.

Файл `/opt/irlix-services/.env` создаётся из `.env.example` только при первом deploy и далее не перезаписывается. Боевые/стендовые секреты должны храниться только на сервере или в GitHub Secrets.

## Health checks

- `/api/platform/health`
- `/api/employees/health`

Health endpoint backend-сервиса проверяет доступ к принадлежащей сервису PostgreSQL schema через его отдельного DB user.
