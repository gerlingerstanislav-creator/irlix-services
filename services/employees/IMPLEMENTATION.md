# Employees implementation notes

The working Employees slice is deployed to the internal stand and evolves together with the business specification in `ideas`.

## Implemented

- department and employee persistence in the `employees` PostgreSQL schema;
- employee reference-data API and backend validation;
- organization structure with seeded baseline departments;
- compact employee registry with search/filtering;
- HR-oriented new employee flow;
- employee side drawer with `Инфо`, `ТУ`, `Зарплаты`, `Роли`, `Заметки` tabs;
- editable employee personal and employment information;
- historical employment/cooperation periods;
- salary history as Employees-owned source data;
- five seeded test employees in different departments with different cooperation types and salary histories;
- automatic database migrations during stand deployment;
- CI verification through the stand Nginx.

## Employee creation

The current HR creation flow requires first name, last name, gender, login, personal email, hire date, cooperation type and department. The work email is derived as `<login>@irlix.ru` and the employee is created with status `Трудоустроен` together with the first employment-period record.

The employee row includes `onboarding_email_status`. Until the onboarding email template/transport is configured, a newly created employee is marked `pending_template`; the application must not claim that an email was actually delivered.

## Employee card

Implemented information fields include name parts, gender, login/work email, personal email, department, position, specialization, status, work format, cooperation type, hire/fire dates, birth date, city, phone, Telegram, Skype and remote-work flag.

The card opens as a right-side drawer over the employee list.

## Employment periods

`employment_periods` stores historical cooperation periods independently from the current employee state. Each row stores:

- cooperation type;
- start and optional end date;
- department snapshot reference;
- position snapshot.

This supports rehiring and type changes such as `Штат -> ГПХ -> ИП` without destroying history.

## Salary history

`salary_history` stores dated gross salary/bonus records and is the current source of truth for compensation history. New changes are appended as new records instead of overwriting history.

## Organization structure

Department fields include hierarchy, name/alias, manager, HR, direct employee count, Yandex infrastructure ID, LDAP/Keycloak mapping and production flag. LDAP mapping remains data only until provisioning rules are confirmed.

## Shared UI

Employees uses `@irlix/ui`. The application shell now follows the legacy-service visual reference with a compact icon-only sidebar. Hovering any menu item reveals labels for the whole menu.

## Deferred

- actual onboarding email template and mail transport;
- LDAP/Keycloak provisioning/group synchronization;
- roles and notes business logic;
- compensation/FOT workflow beyond raw salary history;
- permissions/scopes;
- complete dismissal workflow;
- RabbitMQ domain events and audit trail.
