# System — административный frontend

**Принято 20.10.2026:** отдельное frontend-приложение `apps/system/` — основной интерфейс системного контура, **только `platform-admin`**.

## Маршруты

- `/system/` → `/system/resources/` — ресурсный монитор;
- `/system/service-map/` — карта сервисов;
- `/system/migration/` — консоль переноса данных;
- `/resources/`, `/service-map/`, `/migration/` — совместимые redirect на соответствующие пути System.

Общая `UiAppShell` обеспечивает sidebar, topbar, тему и account menu. В системной topbar нет хлебных крошек-ссылок на Dashboard; выводится только название «Система» и текущий раздел. Встраивание `MigrationConsole` использует режим `embedded`, чтобы не создавать второе меню; backend миграции и операция restore/snapshot остаются прежними. Код текущего мониторинга и карты переиспользуется компонентами, а не дублируется. Продуктовые действия и исходные сервисные API не перемещаются в Dashboard.

## Доступ

Перед mount System запрашивает `/api/employees/access/me` через OIDC; строго проверяет `platform-admin`, роль `platform-tester` или `system-admin` недостаточна. Ошибки проверки приводят к fail-closed. `data.services.system` выдаёт только Employees для platform-admin; наличие ссылки в launcher не даёт права. ResourceMonitor backend `PlatformAdminAuthorizer` также ограничен `platform-admin`. Migration backend продолжает проверять отдельно `services.migration`. Прямая ссылка и refresh не обходят проверок.

## Runtime / CI

System собирается `apps/system/Dockerfile`, хостится как `system-web` на loopback-порту `8102` и проксируется host nginx по `/system/`. Docker Compose overlay: `docker-compose.system.yml`; build/image registry: `infra/ci/services.json` и генерируемый `docker-compose.images.yml`. Production-релиз по-прежнему выполняется **только** по явному разрешению владельца и после CI для полного development.

## Регрессия

- platform-admin может открыть все три экрана, а tester/system-admin/employee не видят приложение и не получают ресурсные данные;
- прямые ссылки, logout/relogin и Browser Back/Forward работают;
- старые URL переходят на новые; перенос данных не теряет настройки, бэкапы, запуск, rollback и polling;
- monitor сохраняет графики/список сервисов; карта сохраняет интерактивные связи;
- 1300px, мобильный viewport, светлая/тёмная тема; одна topbar и одно левое меню.
