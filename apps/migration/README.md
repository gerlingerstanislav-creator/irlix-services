# Migration frontend

Самостоятельный web сервис на `/migration/`, отдельный от Dashboard/Portal. Меню: текущий интерфейс и пульт `/migration/console/`. Общая оболочка и переключатель сервисов — `@irlix/ui`; OIDC — `@irlix/auth` с отдельным storage prefix. Только platform-admin; права проверяются через Employees и повторно в Migration API.

`npm ci`, `npm run build`; локальный preview `npx vite --host 127.0.0.1`. API использует абсолютный `/api/migration/`. Production host nginx удаляет `/migration/` при проксировании на контейнер `migration-web` (8099); Vite asset base сохраняет внешний prefix.

`node --test tests/*.mjs`. Browser fixture `tests/migration-console.browser.cjs` принимает `MIGRATION_PREVIEW`, `PLAYWRIGHT_MODULE`, `CHROMIUM_EXECUTABLE`; все identities и API синтетические. Проверяет отдельное меню, admin boundary, queue lock, mobile overflow и сохранность value/DOM/focus/caret при polling без запущенного Portal.

Run/rollback orchestration, read-only credentials и контрольные точки: [docs/MIGRATION.md](../../docs/MIGRATION.md).
