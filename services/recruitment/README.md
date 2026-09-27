# Recruitment backend

Laravel-сервис отдельного бизнес-контура Recruitment. Владеет схемой PostgreSQL `recruitment` и не читает таблицы других сервисов напрямую.

## Домен MVP

- candidates;
- recruitment_requests;
- hiring_processes + stage_history;
- activities;
- interviews + evaluations;
- offers;
- talent_pools;
- employment_requests;
- onboarding_processes (временная проекция до окончательного решения о владельце onboarding);
- tasks.

## API MVP

- `GET /api/health`;
- `GET /api/workspace`;
- `GET /api/candidates/{id}`;
- `POST /api/candidates`;
- `POST /api/requests`;
- `POST /api/candidates/{id}/activities`;
- `POST /api/hiring-processes/{id}/stage`;
- `POST /api/employment-requests`.

Все API кроме health проходят через общий Keycloak Bearer middleware.

## Следующие шаги

1. permission + scope через оргструктуру Employees;
2. API Specialists для технологий/компетенций;
3. отдельные workflow endpoints для интервью, офферов, трудоустройства и onboarding;
4. Employees API для фактического создания Employee после завершения EmploymentRequest;
5. RabbitMQ события;
6. убрать демонстрационные данные из первичной migration после согласования UX.
