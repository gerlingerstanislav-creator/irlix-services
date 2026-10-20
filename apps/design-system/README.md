# Design System catalog

The live catalog at `/design-system/` renders the same `@irlix/ui` components and tokens as business services. It is a public frontend application without login, a backend, protected API requests or live business data. Direct links and reload work anonymously.

Default shell: `UiAppShell` supplies the live sidebar and topbar with service name and automatic breadcrumbs. The navigation section additionally demonstrates linked ancestor breadcrumbs. Account controls are disabled through the shared `accountEnabled` prop; the launcher receives an empty entitlement map and shows only the public Design System entry.

The catalog is one scrolling page with eight groups: Основы; Таблицы и списки; Кнопки и действия; Поля ввода и выбор; Статусы и загрузка; Вкладки и переключатели; Карточки и панели; Навигация. `src/catalogSections.js` owns sidebar labels, group order, headings and anchors. Every group has a titled horizontal divider; shared-component examples have subordinate headings.

The catalog owns hash navigation (`#section-fields`), direct links, reload and browser Back/Forward. Legacy section paths/query links and retained example hashes migrate to canonical `/design-system/#section-*` links. Manual scrolling updates the active group without adding history entries. Shared legacy routing deliberately excludes this application.

Examples use shared primitives and contained compositions, including grouped selects, checkbox states, filter/grouping rail, registry loading/empty/error states and nested drawers. `UiClientSummary` demonstrates a real shared component with clearly synthetic values. Portal dashboard compositions and service-specific business prose do not belong in this catalog. The filter rail lives inside its example, with no second app mount or floating page control.

When shared UI changes, update its example and `packages/ui/README.md` in the same task. Product specification: `ideas/company-internal-services-*/design/DESIGN_SYSTEM.md`. Clients is a reference for current composition, while `packages/ui` owns reusable visual behavior. Consult only the relevant component/contract according to `docs/CONTEXT_SCOPE.md`.

Validate with a production build and visually check desktop/mobile, menu navigation, filter dropdowns, hierarchy, sticky table scrolling and drawers. Shared UI changes also require builds of affected consuming frontends through the unified CI.


Browser regressions (with Vite running and Playwright/Chromium installed):

```bash
DESIGN_SYSTEM_PREVIEW=http://127.0.0.1:5196 node apps/design-system/tests/navigation.browser.cjs
DESIGN_SYSTEM_PREVIEW=http://127.0.0.1:5196 node apps/design-system/tests/registry.browser.cjs
```

Both tests run without authentication fixtures. The navigation test asserts anonymous boot and launcher interaction make no API/OIDC requests and expose no fake account.
