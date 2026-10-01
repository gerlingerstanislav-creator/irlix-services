# Architecture

## Repository layout

```text
apps/
  portal/               # Dashboard /
  web/                  # Employees /employees/
  vacations/            # Vacations /vacations/
  clients/              # Clients /clients/
  timesheets/           # Timesheets /timesheets/
  design-system/        # /design-system/
packages/
  ui/                   # shared UI, UiAppSidebar, service catalog
  auth/                 # shared browser OIDC client
services/
  platform-core/
  employees/
  vacations/
  clients/
  timesheets/
infra/
  postgres/init/
  keycloak/
.github/workflows/
```

## Runtime

Один Docker Compose project сохраняет логические границы сервисов.

```text
Browser
  |
Host nginx :80
  |-- / ----------------------> Dashboard
  |-- /employees/ ------------> Employees web
  |-- /vacations/ ------------> Vacations web
  |-- /clients/ --------------> Clients web
  |-- /timesheets/ -----------> Timesheets web
  |-- /design-system/ --------> Design System
  |-- /keycloak/auth/ --------> Keycloak
  |-- /api/platform/ ---------> Platform Core
  |-- /api/employees/ --------> Employees API
  |-- /api/vacations/ --------> Vacations API
  |-- /api/clients/ ----------> Clients API
  `-- /api/timesheets/ -------> Timesheets API
```

Backend-сервисы не читают чужие таблицы. Межсервисные данные получают через API с Bearer token текущего пользователя; фоновые/event integration по мере необходимости выполняются через RabbitMQ.

## Data ownership

- `platform_core` → Platform Core;
- `employees` → Employees / `employees_app`;
- `vacations` → Vacations / `vacations_app`;
- `clients` → Clients / `clients_app`;
- `timesheets` → Timesheets / `timesheets_app`;
- Keycloak → identities, credentials, realm roles/groups.

Sources of truth:

- Employees — сотрудники, подразделения, руководители и org scope;
- Clients — клиенты, проекты, аккаунт-менеджеры, подключения и периоды подключений;
- Vacations — официальные отсутствия и их статусы;
- Timesheets — записи коммерческого рабочего времени, предварительные и финальные подтверждения, блокировка через статус отчётного периода Clients и audit trail.

Clients владеет авторизационной матрицей контура клиентов. Employees поставляет организационные и специальные роли; Clients объединяет их в эффективные `permission + scope`. Timesheets получает эффективные разрешения через API Clients. `platform-admin` обрабатывается как неизменяемый суперпользователь во всех сервисах независимо от строк матрицы.

## Timesheets

Timesheets состоит из отдельного Vue frontend и Laravel API.

### Integrations

Timesheets синхронно читает:

- Employees `/employees`, `/departments`, `/self`;
- Clients `/overview` для project assignments;
- Vacations `/calendar-absences` для типов/статусов отсутствий.

Запись рабочего времени разрешена только когда на выбранную дату существует активное подключение в Clients. Перед выдачей рабочих/управленческих/аналитических данных сервис выполняет reconciliation: записи, оказавшиеся вне актуального периода подключения, удаляются, связанные финальные approvals снимаются, действие попадает в audit.

### Confirmation model

- employee confirmation — по отдельному дню, с batch-действиями на неделю/месяц;
- final approval — `employee + project + month`, что позволяет AM подтверждать только собственные проекты, а РН — все проекты сотрудников своих подразделений;
- редактирование сотрудником снимает preliminary confirmation дня;
- редактирование руководителем снимает preliminary confirmation дня и final approval изменённого проекта;
- final approval блокирует сотруднику редактирование соответствующего проекта;
- global period lock блокирует изменения месяца до разблокировки.

### Permission + scope

Timesheets не использует роль как единственное условие доступа к данным.

- `platform-admin` — полный scope;
- AM — assignments, где `account_employee_id` совпадает с текущим employee;
- РН — все сотрудники подразделений, где он `manager_id`, включая сотрудников без проекта, чтобы простой не исчезал из аналитики;
- право закрыть месяц — platform-admin либо сотрудники с должностью «Руководитель направления аккаунтинга» / «Руководитель клиентской службы».

### Analytics

Плановая норма месяца = `8 × weekdays` независимо от отсутствий.

В официальную коммерческую загрузку входят только финально подтверждённые коммерческие часы:

`commercial_percent = final_approved_commercial_hours / norm_hours × 100%`

Простой:

`idle = max(0, norm - commercial - confirmed_absence_hours)`.

Каждый тип отсутствия хранится источником Vacations и выводится отдельным показателем. Коммерческие часы и отсутствие могут пересекаться, поэтому сумма секторов диаграммы может быть больше нормы.

## Frontend platform

Все бизнес-приложения используют `UiAppSidebar` из `packages/ui`. Каталог сервисов централизован в `packages/ui/src/serviceCatalog.js`. Timesheets не копирует rail/launcher implementation.

Browser auth централизован в `packages/auth`: OIDC Authorization Code, refresh/logout и Bearer injection для `/api/*`.

## Security

Backend API проверяют Keycloak RS256 JWT: signature/JWKS, expiry, issuer и authorized client. Health endpoints остаются публичными для инфраструктурных smoke checks; бизнес endpoints требуют Bearer token.

Текущий HTTP-only stand допустим только внутри корпоративного VPN. Внешняя публикация требует HTTPS до включения PWA/PKCE/external access.

## CI/CD

`development` проверяет интеграцию и публикует проверенные образы изменённых компонентов. `main` переиспользует образы по hash build inputs и выкатывает только затронутые контейнеры. Общий registry `infra/ci/services.json` управляет frontend/backend, workers, migrations и smoke checks, включая будущие сервисы. Политика веток, кеша и добавления сервисов обязательна и описана в `docs/CI.md`.
