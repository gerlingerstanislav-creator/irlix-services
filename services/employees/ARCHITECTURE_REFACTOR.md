# Employees: поэтапный переход на Eloquent + сервисный слой

Дата: 2026-10-24. Статус: первый вертикальный срез в feature-ветке, **весь Employees пока не переработан**.

## Согласованные инварианты

- Employees остаётся владельцем сотрудников, отделов и назначаемых ролей. Keycloak — провайдер аутентификации.
- Межсервисные API, response shape, HTTP-коды, permission + scope и ограничения `platform-admin` сохраняются.
- Никаких DDL, изменения таблиц, изменения миграции данных или массового переприсваивания ролей в этом срезе.
- Brandbook по-прежнему выдаёт короткую зашифрованную HttpOnly-cookie только для действующего Keycloak identity и назначенной специальной роли; доступ на содержимое повторно проверяет серверный gate.

## Границы пилотного среза

Переведены:
- `app/Models/{Employee,Department,EmployeeAccessRole,EmployeePermissionRule}.php` — Eloquent-модели.
- `app/Services/{SpecialRoleService,PermissionRuleService}.php` — отдельные операции над ролями/правилами.
- `app/Http/Controllers/{AccessRoleController,PermissionRuleController,BrandbookSessionController}.php` — HTTP-слой.
- `routes/access.php` и `routes/permissions.php` — только привязки методов контроллеров.
- `app/Support/EmployeesAccess.php` — запросы к текущим локальным таблицам через Eloquent. Существующая матрица authorization сохранена без бизнес-изменений.
- Статические регрессионные проверки роли platform-admin и маршрутов.

Пока **не переведены**: основной `routes/api.php` (кадровый CRUD), `organization.php`, `self.php`, `fot.php`, `finance.php`, `audit.php`, `delete.php`, вспомогательные sync/onboarding middleware/команды и worker. Технический долг остаётся до отдельных безопасных итераций.

## Проверка пилота

1. `php services/employees/tests/platform_roles.php`
2. `php services/employees/tests/eloquent_architecture.php`
3. `php -l` всех изменённых PHP-файлов.
4. В интеграционном Laravel/PostgreSQL окружении: GET /access/roles, GET/PUT /access/permissions, PUT/DELETE /access/roles, POST /brandbook/session для admin, top-management, обычного сотрудника и удалённого/неактивного пользователя; проверить 403/404/409, идемпотентное назначение, невозможность снять роль системного admin, JWT identity binding и сохранение audit/Keycloak downstream sync.
5. Проверить весь CI на точном SHA перед интеграцией. Статический тест не заменяет HTTP/E2E с Keycloak и PostgreSQL.

## Следующие вертикальные срезы

1. Организационная структура и должности.
2. Карточка сотрудника, трудоустройство/увольнение и onboarding.
3. Личные/межсервисные read-model endpoints.
4. ФОТ, аудит, события и sync workers.

Выбор и порядок корректировать по риску и имеющемуся покрытию. Никакого production-релиза без отдельной команды владельца.
