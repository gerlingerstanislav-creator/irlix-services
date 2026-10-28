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

## Локальная ролевая матрица Recruitment V2 — подготовка 28.10.2026

Добавлены независимые `recruitment_role_permissions`, `recruitment_permission_audit` и защищённые `GET/PUT/DELETE /api/recruitment/role-permissions`. В целевом JWT-режиме права выдаются только на основании подписанных `org:*`, `head:*`, `special:*` и проверяются через собственную матрицу Recruitment для конкретного HTTP-действия. Отказ по умолчанию, явный deny приоритетнее allow, `platform-admin` имеет глобальный override и подтверждает актуальную роль в Employees. Непроверенные или уже отозванные организационные назначения не используются.

Операции вынесены в отдельные permissions: общий `workspace.view` (включая сведения кандидатов и офферов), работа с кандидатами/заявками/этапами/interviews/offers/pools/employment/onboarding/tasks. Доступ к суммам зарплатных ожиданий и офферов выделен в `recruitment.compensation.view`. В V2 при отсутствии этого разрешения backend исключает `salary_expectation` и `offers.compensation` из `/workspace` и `/candidates/{id}`. Это отдельная серверная проверка, а не просто скрытие интерфейсом.

Данные Recruitment сейчас **не фильтруются по строкам и управляемым подразделениям**; поэтому область `own/team` не допускается ни матрицей, ни серверным вычислением до полноценной объектной авторизации с подтверждёнными владельцами и историческими связями. Это предотвращает ложное ощущение защищённости candidate/offer персональных данных.

Новый режим `IRLIX_RECRUITMENT_LOCAL_AUTHZ_ENABLED=false` по умолчанию, и действующая авторизация не затронута. Переключение потребует согласованного определения объектных scopes для рекрутеров, HR и руководителей, миграции старых прав, отрицательных тестов на чужих кандидатах, офферах и персональных данных, полной parity-проверки и rollback. Не включать только потому, что новая таблица существует.


### Проверка прав по операциям с компенсацией

`recruitment.compensation.view` и `recruitment.compensation.manage` — **разные** локальные permissions. Даже разрешение создавать кандидата (`recruitment.candidates.manage`) или оффер (`recruitment.offers.manage`) не позволяет записать/изменить `salary_expectation` либо `offers.compensation`, в том числе установить `null`: backend проверяет сам факт присутствия поля в валидированных данных. При отсутствии `compensation.view` ответы операций создания и обновления также скрывают значения. Повторный переход только через будущий явный V2 cutover; legacy-поведение не меняется.

`GET /api/recruitment/permissions/me` возвращает только текущие эффективные разрешения из Recruitment (`mode=local`) либо `mode=legacy` при сохранении старой модели. Сервисный frontend получает их после Keycloak-проверки через backend; больше не использует `Employees.data.services.recruitment` как вторую независимую бизнес-политику. В локальном режиме поле компенсации не показывается и не передаётся без соответствующего разрешения. Проверки: `php tests/compensation_policy.php` внутри Laravel образа и `npm test` в `apps/recruitment`.
