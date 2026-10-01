# IRLIX Services

Монорепозиторий платформы внутренних сервисов компании.

## Реализованные приложения

- Dashboard / launcher;
- Employees;
- Vacations;
- Clients;
- Timesheets;
- Specialists;
- Recruitment;
- CV конвертер;
- Design System;
- Platform Core.

## Структура

```text
apps/portal/              Dashboard / launcher
apps/web/                 frontend Employees
apps/vacations/           frontend Vacations
apps/clients/             frontend Clients
apps/timesheets/          frontend Timesheets
apps/specialists/         frontend Specialists
apps/recruitment/         frontend Recruitment
apps/cv/                  frontend CV конвертера
apps/design-system/       витрина дизайн-системы
packages/ui/              общая UI-библиотека
packages/auth/            общий OIDC-клиент
services/platform-core/   Laravel Platform Core
services/employees/       Laravel Employees
services/vacations/       Laravel Vacations
services/clients/         Laravel Clients
services/timesheets/      Laravel Timesheets
services/specialists/     Laravel Specialists
services/recruitment/     Laravel Recruitment
services/cv-converter/    Python/FastAPI CV parsing + render backend
infra/postgres/init/      bootstrap PostgreSQL schemas/users
docs/                     документация реализации
.github/workflows/        CI/CD
AGENTS.md                  обязательные правила работы с проектом
```

## Быстрый запуск

```bash
cp .env.example .env
docker compose -f docker-compose.yml -f docker-compose.cv.yml up -d --build
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
- Timesheets API/web — `127.0.0.1:8090` / `127.0.0.1:8091`;
- Specialists API/web — `127.0.0.1:8092` / `127.0.0.1:8093`;
- Recruitment API/web — `127.0.0.1:8094` / `127.0.0.1:8095`;
- CV конвертер web/API — `127.0.0.1:8096` / `127.0.0.1:8097`.

На стенде host nginx публикует `/employees/`, `/vacations/`, `/clients/`, `/timesheets/`, `/specialists/`, `/recruitment/`, `/cv-converter/`, `/design-system/` и соответствующие `/api/*` маршруты для backend-сервисов. CV API доступен через `/api/cv-converter/`. Старый `/cv/*` перенаправляется на `/cv-converter/`.

Каждый самостоятельный экран frontend-сервиса имеет стабильный URL и может быть открыт прямой ссылкой; переходы внутри сервиса поддерживают browser Back/Forward. Полная карта маршрутов: `docs/ROUTING.md`.

## CV конвертер iteration 2

CV конвертер реализует pipeline:

`PDF/DOCX -> text extraction -> LLM -> CanonicalCv -> IRLIX template -> DOCX -> PDF`.

Текущая реализация:

- принимает PDF с текстовым слоем и DOCX;
- показывает исходник слева;
- автоматически разбирает произвольную структуру CV через сменный `CvExtractionProvider`;
- по умолчанию использует локальный CPU inference: `llama.cpp + Cotype Nano Q4_K_M` без внешних API;
- имеет готовые adapters для GigaChat, Yandex AI Studio и OpenAI-compatible/MWS endpoint;
- валидирует результат через canonical Pydantic schema и не разрешает модели придумывать отсутствующие факты;
- формирует preview результата как реальный PDF;
- генерирует DOCX и PDF из одной canonical-модели;
- не сохраняет исходный файл, canonical data или render после запроса;
- работает на `/cv-converter/convert/` и доступен компактной ссылкой Dashboard.

`cv-llm` ограничен по ресурсам и используется для фактического теста 1.5B-модели на текущей VM. Если качества окажется недостаточно, provider переключается через env без изменения UI/canonical schema/renderer.

Legacy `.doc` и OCR для сканов пока не поддерживаются. Клиентские шаблоны и интеграции с Clients/Recruitment относятся к следующим итерациям. Полное продуктовое ТЗ: `ideas/company-internal-services-*/services/cv/ТЗ.md`.

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
- каждый самостоятельный frontend-экран имеет стабильный route и поддерживает прямой вход по URL;
- авторизация проектируется как permission + scope, `platform-admin` имеет полный платформенный доступ;
- подтверждённые решения фиксируются в репозитории, чат не является спецификацией.

См. `AGENTS.md`, `docs/ARCHITECTURE.md`, `docs/DEVELOPMENT.md`, `docs/DEPLOYMENT.md`, `docs/ROUTING.md`.
