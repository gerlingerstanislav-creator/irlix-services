# IRLIX Services

Монорепозиторий новой платформы внутренних сервисов компании.

## Текущий этап

Итерация 1: инфраструктурный каркас, Platform Core, общий Vue frontend-shell и Employees как первый эталонный бизнес-сервис.

## Структура

```text
apps/web/                 Vue platform shell
services/platform-core/   Laravel Platform Core
services/employees/       Laravel Employees
infra/postgres/init/      bootstrap PostgreSQL schemas/users
docs/                     документация реализации
.github/workflows/        CI/CD
AGENTS.md                  обязательные правила работы с проектом
```

## Быстрый запуск

```bash
cp .env.example .env
docker compose up -d --build
```

После запуска:

- frontend: `http://127.0.0.1:8080`;
- Platform Core health: `http://127.0.0.1:8081/api/health`;
- Employees health: `http://127.0.0.1:8082/api/health`.

## Архитектурные принципы

- одна физическая PostgreSQL на старте, отдельная schema и DB user для каждого сервиса;
- независимые backend-контейнеры;
- прямой доступ к таблицам другого сервиса запрещён;
- синхронная интеграция через API, асинхронная — через RabbitMQ;
- общий frontend-shell на Vue;
- подтверждённые правила и решения фиксируются в репозитории, чат не является спецификацией.

См. `AGENTS.md`, `docs/ARCHITECTURE.md`, `docs/DEVELOPMENT.md`, `docs/DEPLOYMENT.md`.
