# IRLIX Services

Монорепозиторий новой платформы внутренних сервисов компании.

## Текущий этап

Итерация 1: инфраструктурный каркас, Platform Core, общий portal/launcher, Employees как первый эталонный бизнес-сервис и отдельное приложение Design System.

## Структура

```text
apps/portal/              корневая разводящая по внутренним сервисам
apps/web/                 frontend Employees (пока историческое имя каталога)
apps/design-system/       отдельная витрина дизайн-системы
packages/ui/              общая UI-библиотека
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

После запуска контейнеры доступны локально:

- Employees frontend: `http://127.0.0.1:8080`;
- Platform Core health: `http://127.0.0.1:8081/api/health`;
- Employees health: `http://127.0.0.1:8082/api/health`;
- Design System: `http://127.0.0.1:8083`;
- Portal: `http://127.0.0.1:8084`.

На стенде внешний nginx публикует:

- `/` — разводящая по сервисам;
- `/employees/` — Employees;
- `/design-system/` — Design System;
- `/api/platform/*` — Platform Core API;
- `/api/employees/*` — Employees API.

## Архитектурные принципы

- одна физическая PostgreSQL на старте, отдельная schema и DB user для каждого сервиса;
- независимые backend-контейнеры;
- прямой доступ к таблицам другого сервиса запрещён;
- синхронная интеграция через API, асинхронная — через RabbitMQ;
- общая UI-библиотека в `packages/ui`, отдельные frontend-приложения публикуются на собственных route prefixes;
- подтверждённые правила и решения фиксируются в репозитории, чат не является спецификацией.

См. `AGENTS.md`, `docs/ARCHITECTURE.md`, `docs/DEVELOPMENT.md`, `docs/DEPLOYMENT.md`.
