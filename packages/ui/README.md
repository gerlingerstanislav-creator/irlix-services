# @irlix/ui

Shared IRLIX design-system implementation for all internal services.

## Responsibility

This package owns reusable visual primitives, platform navigation and tokens used by the common frontend shell and business services. Product/UX rules live in `ideas/company-internal-services-*/design/DESIGN_SYSTEM.md`.

## App sidebar — mandatory platform component

All services MUST use `UiAppSidebar` from `@irlix/ui`. A service must not implement or copy its own left navigation shell, services launcher, logo, hover labels, colors or dimensions.

A service only supplies its own navigation data:

```js
const items = [
  { id: 'requests', label: 'Запросы', icon: 'target' },
  { id: 'leads', label: 'Лиды', icon: 'crown', groupStart: true },
  { id: 'clients', label: 'Клиенты', icon: 'briefcase' },
];
```

`groupStart: true` starts a new visual navigation group before the item: the rail gets a small additional gap and divider. The hover-label column receives the same spacing, so labels remain aligned with icons while scrolling.

```vue
<UiAppSidebar
  v-model:section="section"
  :items="items"
  current-service="clients"
  :current-user="auth.user"
  @logout="auth.logout"
/>
```

### Fixed behavior

- rail width: `60px`;
- navigation item size remains `42×40px`; ordinary items have no vertical gap, group dividers use an 8px gap;
- on desktop the whole global rail is fixed to the viewport and never moves with page/content scrolling;
- upper navigation scrolls independently inside the fixed rail; bottom actions stay fixed;
- hovering any navigation item exposes labels for ALL service navigation items at once;
- each hover label uses content width: no fixed/minimum tooltip width beyond its text and horizontal padding;
- the active icon and active hover label use the primary green state;
- the logo has equal top spacing and spacing to the divider below, so its hover state never touches the divider;
- clicking the IRLIX logo opens the shared grouped services launcher;
- clicking outside closes the services launcher;
- the current service is marked in the launcher;
- unavailable future services may be shown disabled in the shared catalog;
- services marked `platformAdminOnly` in the shared catalog appear only when the host passes `platformAdmin` or an authenticated `platformAccess` callback returning the Employees effective access response; route and API authorization remain server-side;
- optional navigation grouping is configured only through `groupStart`, never with service-specific CSS;
- mobile layout switches to a horizontal sticky rail and hides hover labels/extra bottom actions; account and theme remain accessible;
- keyboard/focus and aria labels are part of the component contract.

All sizes and colors are defined by `--irlix-sidebar-*` tokens in `src/styles/tokens.css`. Change them globally, never per service.

### Shared sources

- `src/components/UiAppSidebar.vue` — behavior and layout;
- `src/components/UiIcon.vue` — shared navigation icon set;
- `src/serviceCatalog.js` — single catalog for the logo services launcher;
- `src/styles/tokens.css` — sidebar colors/sizes and the rest of design tokens.

When somebody asks to “сделать левое меню как в остальных сервисах”, this means: use `UiAppSidebar`; do not reproduce the UI manually.

## Full-height registry workspace

Desktop registry/list screens use the shared shell invariant from `src/styles/base.css`: the main `.workspace` fills the viewport height and the primary `.ui-panel` stretches through all remaining vertical space down to the bottom edge of the viewport, even when the table contains only a few rows. The same work surface is full-bleed horizontally relative to the service work area, while the page header and top controls keep their normal padding.

Rules:

- do not leave decorative bottom whitespace below the main registry/list panel on desktop;
- do not leave outer left/right gutters around the primary registry/list panel on desktop;
- page headers, filters and secondary controls consume their natural height and keep their normal content padding;
- the main work surface gets the remaining height and full available width;
- the registry/table/calendar body owns overflow and scrolls internally when content is taller than available space;
- a short dataset does not shrink the primary work surface;
- horizontal scrolling, when unavoidable, remains inside the table/registry container;
- the header row of `irlix-data-table` remains sticky at the top of its internal scroll container while table rows scroll underneath it;
- business services must not disable the shared sticky table header with local CSS unless a distinct table pattern explicitly requires another behavior;
- business services must not reintroduce arbitrary desktop side/bottom padding that breaks this invariant;
- mobile layouts may return to document flow and natural page scrolling.

## Page header invariant

All standard service pages use `UiPageHeader` from `@irlix/ui`.

- page-content spacing belongs below `UiAppTopbar`; the workspace has no top padding before the panel;
- the primary page title is fixed at `24px` with the shared compact line-height;
- services must not restore larger local top padding or responsive oversized page-title typography;
- eyebrow and description keep the shared component typography and spacing.

## Searchable select filters

All select-like controls used specifically for **filtering lists, tables, registries or dashboards** must use `UiSearchSelect`. The same component may also be used in ordinary forms when search, grouping or hierarchical choices are useful. Native `<select>` remains acceptable inside ordinary forms where none of those capabilities is needed.

The standard platform input/select/filter control height is `32px`, defined by `--irlix-control-height`. Buttons use the same height and `--irlix-control-radius` (10px). Compact buttons remain 27px in cards; inside the topbar they use the standard 32px height automatically. Business services must not restore taller local controls unless a separate component pattern explicitly requires it. Navigation view menus and year/month context are explicit exceptions to searchable filters: use `UiViewSelect` and `UiPeriodPicker` below.

`UiSearchSelect` provides:

- search inside the dropdown;
- single-select and `multiple` modes;
- clear action without reopening the menu;
- string, number and object options;
- configurable label/value keys;
- keyboard Escape handling and click-outside close;
- empty state and disabled options;
- shared visual behavior matching the filter toolbar pattern;
- optional grouped/tree-like option lists for hierarchical selectors.

### Grouped options

For hierarchical selectors, pass a flat sequence containing non-selectable group headers and selectable children:

```js
const specialistOptions = [
  { value: 'direction:backend', label: 'Backend', kind: 'group' },
  { value: '42', label: 'Иван Петров', depth: 1 },
  { value: '43', label: 'Анна Соколова', depth: 1 },
  { value: 'direction:qa', label: 'QA', kind: 'group' },
  { value: '57', label: 'Олег Смирнов', depth: 1 },
];
```

`kind: 'group'` renders a non-selectable heading. `depth` controls visual indentation of selectable rows. Search keeps a matching group together with its matching children and never makes group headings selectable. This pattern is suitable for forms such as `Направление → Специалист`, not only for registry filters.

### Multiple selection invariant

Use `multiple` whenever several filter values can logically be active at once, for example statuses, departments, types, technologies or other independent classifications.

- selecting one option MUST NOT close the dropdown in `multiple` mode;
- every selected option remains visibly marked in the dropdown;
- the trigger shows a compact selected-count badge followed by the filter label instead of concatenating long selected labels;
- clear resets the entire selected set;
- option labels may wrap to multiple lines, but use a compact readable line-height (`~1.15`) and enough vertical padding so adjacent option text never overlaps;
- selected rows may use a subtle primary-soft background to make the current set easy to scan;
- business services should use the shared `multiple` behavior rather than building local checkbox dropdowns.

### Select chevron invariant

The dropdown chevron is part of the shared component contract:

- it lives in a fixed right-side icon zone and is vertically centered relative to the control;
- closed state points down; open state rotates exactly `180deg` around its own center;
- opening or closing MUST NOT change the chevron X/Y position;
- the transition animates rotation only; state-specific `translate` offsets are prohibited;
- the optional clear action is placed to the left of the chevron and must not shift the chevron;
- business services must not override chevron positioning or rotation with service-specific CSS.

Example:

```vue
<UiSearchSelect
  v-model="departmentId"
  :options="departments.map((item) => ({ value: item.id, label: item.name }))"
  placeholder="Подразделения"
  search-placeholder="Поиск подразделения"
/>
```

The same component must be reused by business services instead of building local searchable dropdowns.

## Right filter rail

`UiFilterRail` is the shared right-side filter pattern for dense registries. Employees is the first consumer; other services adopt it only when their own screens are migrated.

The component receives filter metadata through `items` and renders each control in the matching named slot `#filter-<id>`. The icon and the control share the same layout row, so opening the panel keeps the icon vertically aligned with its corresponding input/select instead of maintaining two independent stacks.

Optional grouping controls are passed separately through `groupingItems` and rendered in `#grouping-<id>` slots. When present, the component places them below filters under a dedicated `groupingTitle` heading. This keeps filtering and grouping as distinct concepts while preserving the same rail interaction and icon alignment.

```vue
<UiFilterRail :items="filterItems" @reset="resetFilters">
  <template #filter-search>
    <label class="irlix-field">
      <span>Поиск</span>
      <input v-model="search" />
    </label>
  </template>
  <template #filter-department>
    <label class="irlix-field">
      <span>Подразделение</span>
      <UiSearchSelect v-model="departmentId" :options="departmentTreeOptions" />
    </label>
  </template>
</UiFilterRail>
```

Fixed behavior:

- collapsed rail width is `48px`; filter icons remain `40×40px`;
- expanded filter panel width is `300px` on desktop and opens to the left without resizing the registry;
- every filter row owns both its control and its icon, preserving alignment while labels or controls vary;
- active filters use the existing subtle rail active state and expose their current value in the icon tooltip;
- the reset action is a paired footer row: text button in the expanded panel and reset icon in the collapsed rail, both fixed to the bottom;
- hover opens the panel, click pins/unpins it; working inside a pinned filter does not close the panel;
- hierarchical filters continue to use grouped/depth options of `UiSearchSelect`; the rail does not own business hierarchy;
- `contained` exists only for bounded previews such as the Design System catalog; normal services use the default fixed viewport behavior.

## Resizable right drawers

`UiDrawer` is resizable on desktop by dragging its left boundary. The right edge stays fixed to the viewport, so changing width expands or contracts the drawer to the left.

- maximum width is `90vw`;
- minimum width is derived from the component's base pixel `width` (70%, but never below 320px) unless a business component explicitly supplies `minWidth`;
- the left boundary is a thin neutral line and uses primary-green feedback on hover/drag;
- resize is disabled on mobile, where the drawer occupies the available viewport width;
- entity-specific drawers with dense content should configure a larger base `width` and, if needed, an explicit `minWidth` rather than overriding the shared resize behavior.

## Other shared components

- `UiButton`, `UiBadge`, `UiPanel`, `UiPageHeader`;
- `UiDrawer`, `UiTabs`, `UiSegmentedControl`, `UiViewSwitch`, `UiFilterBar`, `UiSearchSelect`;
- common form/table foundations in `src/styles/base.css`.

The **Design System** application renders the current tokens, components and UI patterns as a live catalog. When a shared component or visual rule changes, the catalog must be updated in the same iteration.

New components should be added when there is a real repeated use case. Service-specific business components should stay in the service/web application unless they become reusable patterns.

## Mandatory use in every UI task

All new or changed platform interfaces use `@irlix/ui`, shared styles and design tokens. First find the existing component/pattern; do not reproduce its appearance or override its base styling in a business service. Service-local CSS owns business composition and layout. Add a genuinely reusable missing primitive to this package and its interactive example to `apps/design-system` in the same task. Update the matching specification in `ideas/.../design/`.

## Hierarchy and table groups

`UiTreeToggle` is the shared disclosure control used in Clients registries and the Request → Position → Attempt hierarchy. `expanded` controls state; `label` supplies the accessible action name; `disabled` prevents interaction; `variant="plus"` selects the +/− branch control. `hover=false` keeps borderless workflow rows visually quiet. Handle `@click.stop` in the owning row to keep disclosure separate from opening the entity card. The service owns children, business status and indentation, while the component owns the icon, dimensions, focus and visual state.

A section row in a table body uses `<tr class="irlix-table-group"><th :colspan="columnCount">…</th></tr>`. Its grey heading is not a sticky column header. Colors live in `--irlix-table-group-*`; hierarchy colors in `--irlix-tree-*`.

## Catalog verification (2026-10-02)

The catalog was compared with Clients and now renders every exported UI component, all five badge tones, compact/disabled buttons, multiple and grouped searchable selects, tab counts, icons, borderless hierarchy, table section headings, a sticky scrollable registry with data/loading/empty/error states, and resizable nested drawers. Showcase data is explicitly synthetic. Business-specific workflow progress, permissions and drag-and-drop remain in Clients; they are not copied into shared primitives.


## Default application shell and topbar

`UiAppShell` is the default for new service interfaces. It renders `UiAppSidebar` and `UiAppTopbar` automatically. Pass `service`, `section`, `items`, `bottomItems` and `currentUser`. The service name defaults to the central catalog, the current breadcrumb defaults to the active navigation label. `serviceName` and `breadcrumbs` allow explicit labels and deeper paths. Only ancestor crumbs with `href` are links; the final crumb has `aria-current="page"`.

```vue
<UiAppShell service="clients" v-model:section="section" :items="items" :current-user="user" @logout="logout">
  <template #actions><UiButton @click="create">Создать</UiButton></template>
  <main class="content">…</main>
</UiAppShell>
```

The `actions` slot holds contextual page actions; `breadcrumb-extra` holds `UiViewSelect`, as in Clients requests/reporting periods. A `sidebar` slot supports a service adapter that supplies permission-filtered navigation. Existing layouts may adopt `UiAppTopbar` independently; the panel is the first child of an unpadded work area, and content gutters belong to a child below it. Specialists/Recruitment use `irlix-service-workspace` with `irlix-service-content` below the panel. Legacy `.app-shell > .workspace` follows the same invariant in shared base styles. Portal/Migration mount the same Vue panel above their content.

### Single visual owner

- Height 45px, no outer top margin/padding, bottom border on every service.
- Service → breadcrumb path → view selector always form one line. Actions stay at the right edge when they fit.
- Text and controls never shrink or wrap. If the entire row exceeds the viewport, horizontal scrolling is contained in `irlix-app-topbar__viewport`; keyboard focus reveals offscreen actions.
- Mobile keeps the same row below the shared horizontal sidebar; only the sticky offset changes.
- Panel layout/typography use `--irlix-topbar-*`; control height/radius use shared control tokens.
- Service-local rules targeting the panel, breadcrumbs or the new selectors are prohibited and checked by `topbarContract.test.js`.
- Updating shared sources updates every frontend after build/deployment. Each frontend in `infra/ci/services.json` declares `packages/ui/`; existing planner tests verify this selects all frontend consumers and no backends. New frontend services must declare that dependency too.

### Plain view selector

```vue
<template #breadcrumb-extra>
  <span class="irlix-breadcrumbs__separator" aria-hidden="true">—</span>
  <UiViewSelect v-model="view" :options="[{value:'list',label:'Список'},{value:'kanban',label:'Канбан'}]" />
</template>
```

`UiViewSelect` accepts `modelValue`, `options` (`{value,label,disabled}` or primitive values), `ariaLabel`, `disabled`; emits `update:modelValue` and `change`. The trigger is borderless, without pointer-focus rings; keyboard focus remains visible. The menu has text rows, no search/clear/selection icons. Arrow keys/Home/End navigate, Enter selects, Escape restores trigger focus. Menus teleport to body and follow their anchor, avoiding clipping by service scroll containers.

### Shared year/month calendar

```vue
<UiPeriodPicker v-model="year" mode="year" /> <!-- numeric year, e.g. 2026 -->
<UiPeriodPicker v-model="month" /> <!-- YYYY-MM string, e.g. 2026-10 -->
```

`UiPeriodPicker` uses `mode="year"` or default `mode="month"`; `minYear`/`maxYear` default to 1900/2100, and `disabled` disables all actions. Year values preserve their number/string type; month values are YYYY-MM. Events are `update:modelValue` and `change`.

The 32px field has a 10px outer radius, 30px square arrow zones (control height minus its 2px border), and 2px padding next to the label. Left/right arrows immediately change the period; the center opens a calendar. Year mode displays a 12-year grid with paging. Month mode displays 12 months and a year header: clicking the header opens year selection, selecting a year returns to months without changing the value until a month is chosen. Selecting a period commits immediately and closes. Month arrows cross January/December correctly. Bounds disable unavailable choices. Escape/outside click close, keyboard arrows navigate the grid. The anchored, teleported calendar remains above table/viewport overflow.

### Verification

Run `node --test packages/ui/tests/*.test.*`, build all frontend consumers, and use `/design-system/` navigation examples to check view selection, year/month selection, calendar paging, keyboard focus, narrow widths and 32px button/control alignment. CI runs the same Node regression tests plus its existing planner/image checks.


## Shared service catalog and dashboard

`serviceCatalog.js` owns stable contour keys/order, service keys, labels, routes, icons, availability, descriptions and informational audience labels. `getVisibleServiceGroups(platformAdmin, serviceAccess)` is the visibility policy used by both `UiAppSidebar` and `UiServiceDashboard`. Admin-only services remain hidden for non-admin users; audience labels are informational, not permissions. Service access still requires route/API authorization.

`UiAppSidebar` receives `platformAdmin` from a verified access check or a `platformAccess` authenticated callback returning Employees effective access. `UiAppShell` forwards both. The design-system route passes its already-verified admin state. Every business auth adapter must expose `auth.fetch`; Node regression tests cover this contract, including Recruitment and Timesheets. A transient role request failure is retried when the menu next opens. The launcher has viewport-bounded vertical scrolling on desktop/mobile so all visible groups remain reachable. Services must not replace its catalog or appearance.

`UiServiceDashboard` accepts `platformAdmin` and `serviceAccess` and renders the exact same filtered groups, including the unavailable Assessments placeholder. At window widths >=1200px it has four equal card columns, each contour spans two; paired contours align their rows and the next pair starts below the taller contour. At 621–1199px it has three card columns and full-width contours in catalog order. Smaller mobile widths use two columns, then one at <=390px. All cards have a fixed 210px height on desktop/tablet (250px on small mobile); content never drives individual card height. The dashboard includes no topbar; the enclosing shell alone owns that panel. Portal mounts this shared component directly, without injected enhancement scripts or separate contour maps.

Run `node --test packages/ui/tests/*.test.*`. Verify admin/non-admin catalog equality between launcher/dashboard, menu scrolling at short viewport heights, authenticated access in every service, four columns at 1360px, three at 1100px, equal card heights/row positions, and exactly one topbar on the dashboard.

`UiIcon name="settings"` — шестерёнка для открытия настроек. Icon-only `UiButton` обязательно получает `aria-label` и tooltip; disabled используется для недоступных модулей.

Clients access exception: a verified `platform-tester` without `platform-admin` does not see Clients in the dashboard or launcher, including when organizational roles grant client permissions. Both components use the same role-aware visibility function; `UiAppShell` forwards `serviceAccess`. The sidebar loads effective Employees roles through `platformAccess` even when `platformAdmin` is true. A real administrator (including both roles) retains Clients access. This UI restriction accompanies the Clients API guard; Timesheets and Vacations read integration contracts remain available.

## Platform typography and appearance

All current and future frontend services use Onest as primary font with a system fallback stack, declared in `src/styles/tokens.css`. Use the semantic type scale rather than tuning isolated text to fit:

- `--irlix-font-size-caption` / `--irlix-font-size-xs`: 12px, labels, metadata and short helper text.
- `--irlix-font-size-table`: 13px, dense registers, tables, calendar cells and controls.
- `--irlix-font-size-body`: 14px, regular interface copy.
- `--irlix-font-size-card-title` / `--irlix-font-size-section-title`: 16px, sections.
- `--irlix-font-size-page-title`: 24px, page headings.

This deliberately restricts typography to five distinct sizes. Preserve existing compact table geometry, readable labels, truncation/ellipsis, tooltips and horizontal/inner scrolling when migrating. There are no preapproved font-size exceptions, including dense matrices such as Timesheets. If the content does not fit, first improve column spacing, layout, labels, wrapping, ellipsis and contextual details without reducing the shared font size or hit targets. Prefer layout/spacing fixes to new text sizes. Font weights and line-heights are also tokens.

Appearance uses `src/theme.js` (`getTheme`, `setTheme`, `initTheme`), and `src/styles/tokens.css` for both palettes. The selection `light | dark | system` is stored in origin-scoped `localStorage['irlix:theme']` and synchronizes across tabs. Every application should import the shared UI entry point (directly or through its shared components), not implement a local theme. The selector is part of `UiAppSidebar`. Use semantic background/text/border/status tokens; never assume a white canvas. For a new frontend page, initialize the theme before mount to avoid light flashes. Cross-origin deployments need a separate synchronization mechanism.

Onest Variable is bundled locally by each frontend through pinned `@fontsource-variable/onest@5.3.1` and imported from the Vite entry point. Font files are emitted into each frontend artifact, including Cyrillic subsets, with system fallback when assets are unavailable. License: SIL Open Font License 1.1; do not copy font binaries directly into git. Verify the generated CSS/font assets on every frontend build. Visual testing must cover both palettes and the system-following mode, plus contrast and dense matrices.

## Color registry and single source of truth

The live **Foundations → Цветовая палитра** section in `apps/design-system` presents the semantic tokens actually declared in `src/styles/tokens.css`, grouped by use (foundations, forms/tables, sidebar and filters), displaying the resolved value for the currently selected theme. The catalog is not a separate palette configuration and must not hardcode copies of swatch values.

**Single-color global change contract:** change the color value of a named token in `src/styles/tokens.css` for each relevant theme. Every consuming component/service must reference that token with `var(--irlix-...)`. Rebuild all frontend consumers of `packages/ui`; local hex/rgb/hsl declarations or component overrides prevent propagation and must be eliminated during migration. Keep semantic status mappings (danger, warning, success, info, absence/confirmation) readable in both themes.

The palette catalog is a view of tokens, not an editor. In-browser editing/persistence of palette values is not provided: code review, tests and normal deployment remain required for global color changes.

Migration access: only `platform-admin` can open the console or its API. `isPlatformAdministratorAccess` performs the strict role check; `isPlatformAdminAccess` retains its broader admin/tester behavior for other services. The shared role-aware catalog hides Migration from testers in the sidebar, launcher and Dashboard, including when they have organizational roles. A user holding both platform roles retains administrator access.

## Shared Kanban — `UiKanbanBoard`

All boards (connection attempts, reporting periods and leads) **must use** `UiKanbanBoard` exported from `@irlix/ui`. Structure, header/count, fixed header, independent vertical scrolling, card spacing, drop highlighting and breakpoints are implemented **once** in `packages/ui/src/components/UiKanbanBoard.vue` and `packages/ui/src/styles/kanban.css`. Do not copy `.kanban-column`, `.kanban-card`, grid or scrollbar styles into services.

Use `:columns` for ordered status names (or `{id,label}`), `:items` for status-bearing records, `:active-target` for the eligible drag destination. Slot `#cards="{column,items}"` renders business data, each clickable/draggable item using `.irlix-kanban-card` and optional `.irlix-kanban-card--dragging`. Handlers for `@dragover`, `@drop` and `@dragleave` receive the native event and destination status. **The board does not mutate application data**: allowed status transitions, missing-fields prompts, permissions and persistence must remain in the owning service.

The design-system Foundations/Components screen provides an interactive demonstration with drag-and-drop. Any global Kanban appearance change must be made in the shared CSS, then checked in all three integrations and in the live demo. Columns maintain their header position while each column's cards scroll independently.

### Account and compact Kanban controls (2026-10-10)

The last sidebar action is the account avatar. It opens a shared popover with name, username/email when available, and the logout button emitting the existing `logout` event. There is no separate logout icon. Outside click and Escape close the popup; Escape restores focus. No additional API request or role inference is needed.

Each live Kanban filter bar includes `UiSearchSelect multiple` with placeholder `Столбцы`. Values are the visible column IDs; an empty selection hides every column and shows guidance. Filter the original column order, rather than reordering by selection. Defaults hide Attempts `Закрыт: успех/неудача`, Leads `Сделка закрыта - Успех/Отказ`, Reports `Счет оплачен`. Column visibility only changes presentation; data and domain transitions are preserved. Selection lives for the mounted page and resets on a new page visit.

Use shared `.irlix-kanban-filters` for compact filter spacing. Visible columns share the available desktop width; only mobile (720px and below) enforces the minimum column width with horizontal scrolling. Cards use a single constrained grid track and border-box width. Columns reserve no empty scrollbar gutter; an actual scrollbar is thin. Leads stack labels above wrapping values, without a redundant status badge (status is in the header).
