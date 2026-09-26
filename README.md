# IRLIX Services

Монорепозиторий платформы внутренних сервисов компании.

## Реализованные приложения

- Dashboard / launcher;
- Employees;
- Vacations;
- Clients;
- Timesheets;
- Design System;
- Platform Core.

## Структура

```text
apps/portal/              Dashboard / launcher
apps/web/                 frontend Employees
apps/vacations/           frontend Vacations
apps/clients/             frontend Clients
apps/timesheets/          frontend Timesheets
apps/design-system/       витрина дизайн-системы
packages/ui/              общая UI-библиотека
packages/auth/            общий OIDC-клиент
services/platform-core/   Laravel Platform Core
services/employees/       Laravel Employees
services/vacations/       Laravel Vacations
services/clients/         Laravel Clients
services/timesheets/      Laravel Timesheets
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

Локальные порты:

- Employees web — `127.0.0.1:8080`;
- Platform Core API — `127.0.0.1:8081`;
- Employees API — `127.0.0.1:8082`;
- Design System — `127.0.0.1:8083`;
- Dashboard — `127.0.0.1:8084`;
- Keycloak — `127.0.0.1:8085`;
- Vacations API/web — `127.0.0.1:8086` / `127.0.0.1:8087`;
- Clients API/web — `127.0.0.1:8088` / `127.0.0.1:8089`;
- Timesheets API/web — `127.0.0.1:8090` / `127.0.0.1:8091`.

На стенде host nginx публикует `/employees/`, `/vacations/`, `/clients/`, `/timesheets/`, `/design-system/` и соответствующие `/api/*` маршруты.

## Timesheets MVP

Timesheets реализует:

- календарный ввод коммерческих часов по действующим подключениям Clients;
- несколько проектов на один день;
- шаг времени 0,25 часа и максимум 24 часа в сутки;
- предварительное подтверждение сотрудником по дню, неделе и месяцу;
- финальное подтверждение AM/РН в рамках permission + scope;
- блокировку редактирования после финального подтверждения;
- управленческую матрицу месяца с редактированием по двойному клику;
- отображение предоставленных и неподтверждённых отсутствий из Vacations;
- коммерческую загрузку по сотрудникам, подразделениям и компании;
- отдельные показатели отпусков, больничных, отгулов и других отсутствий;
- расчёт простоя;
- глобальное закрытие/разблокировку периода для уполномоченных ролей;
- аудит действий;
- автоматическое удаление записей, оказавшихся вне актуальных периодов подключения Clients.

Полное продуктовое ТЗ: `ideas/company-internal-services-*/services/timesheets/ТЗ.md`.

## Архитектурные принципы

- одна физическая PostgreSQL на старте, отдельная schema и DB user для каждого backend-сервиса;
- прямой доступ к таблицам другого сервиса запрещён;
- синхронные интеграции выполняются через API, асинхронные — через RabbitMQ;
- Employees — source of truth для сотрудников и оргструктуры;
- Clients — source of truth для проектов и подключений;
- Vacations — source of truth для официальных отсутствий;
- Timesheets — source of truth для введённых/подтверждённых записей рабочего времени;
- frontend-сервисы используют общую UI-библиотеку и `UiAppSidebar`;
- авторизация проектируется как permission + scope, `platform-admin` имеет полный платформенный доступ;
- подтверждённые решения фиксируются в репозитории, чат не является спецификацией.

См. `AGENTS.md`, `docs/ARCHITECTURE.md`, `docs/DEVELOPMENT.md`, `docs/DEPLOYMENT.md`.
