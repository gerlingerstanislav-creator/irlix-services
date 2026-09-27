# Recruitment backend

Laravel-сервис отдельного бизнес-контура Recruitment. Владеет схемой PostgreSQL `recruitment` и не читает таблицы других сервисов напрямую.

## Текущий статус

Функциональный MVP подключён к UI через live API. Реализованы базовые рабочие сценарии кандидатов, заявок, hiring pipeline, интервью, офферов, Talent Pools, трудоустройства, onboarding-проекции и задач.

## Домен MVP

- `candidates`;
- `recruitment_requests`;
- `hiring_processes` + `stage_history`;
- `activities`;
- `interviews` + `evaluations`;
- `offers`;
- `talent_pools` + membership кандидатов;
- `employment_requests`;
- `onboarding_processes` — временная проекция до окончательного решения о владельце onboarding;
- `tasks`.

Принципиальные границы:

- Candidate не равен HiringProcess;
- HiringProcess не равен RecruitmentRequest;
- Candidate не равен Employee;
- Employees остаётся source of truth по сотрудникам и оргструктуре;
- Specialists остаётся source of truth по корпоративному каталогу технологий/компетенций.

## API MVP

Чтение:

- `GET /api/health`;
- `GET /api/workspace`;
- `GET /api/candidates/{id}`.

Кандидаты и подбор:

- `POST /api/candidates`;
- `POST /api/requests`;
- `POST /api/candidates/{id}/activities`;
- `POST /api/hiring-processes/{id}/stage`.

Интервью и feedback:

- `POST /api/interviews`;
- `POST /api/interviews/{id}/evaluations`.

Офферы:

- `POST /api/offers`;
- `PATCH /api/offers/{id}`.

Talent Pools:

- `POST /api/talent-pools`;
- `POST /api/talent-pools/{id}/candidates`.

Трудоустройство и onboarding:

- `POST /api/employment-requests`;
- `PATCH /api/employment-requests/{id}`;
- `PATCH /api/onboarding/{id}`.

Задачи:

- `POST /api/tasks`;
- `POST /api/tasks/{id}/complete`.

API защищён общим Keycloak Bearer middleware. Детальная permission + scope модель для Recruiter/HR и руководителей направлений остаётся отдельным следующим слоем и должна опираться на оргструктуру Employees.

## Frontend

`apps/recruitment` использует платформенный `UiAppSidebar` и стабильные маршруты:

- `/recruitment/`;
- `/recruitment/requests`;
- `/recruitment/candidates`;
- `/recruitment/candidates/{id}`;
- `/recruitment/hiring`;
- `/recruitment/interviews`;
- `/recruitment/offers`;
- `/recruitment/talent-pools`;
- `/recruitment/employment`;
- `/recruitment/onboarding`;
- `/recruitment/tasks`;
- `/recruitment/analytics`.

UI получает рабочие данные из `GET /api/recruitment/workspace` и выполняет основные действия через Recruitment API.

## Открытые интеграционные задачи

1. permission + scope через оргструктуру Employees для Recruiter/HR/руководителей;
2. API Specialists для выбора технологий/компетенций вместо свободного текста;
3. создание нового HiringProcess для существующего Candidate в рамках конкретной RecruitmentRequest;
4. фактическое создание Employee через Employees API после финального этапа EmploymentRequest;
5. автоматический запуск onboarding после создания Employee и окончательное определение source of truth onboarding;
6. RabbitMQ-события для межсервисных процессов;
7. правила дедупликации кандидатов и политика хранения персональных данных;
8. перенос demo seed из первичной migration после согласования UX и реальных бизнес-правил.
