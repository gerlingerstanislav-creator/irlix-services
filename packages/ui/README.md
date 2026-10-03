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
- navigation item size remains `42×40px`;
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
- mobile layout switches to a horizontal sticky rail and hides hover labels/bottom actions;
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

- desktop workspace top padding before the eyebrow is `12px`;
- the primary page title is fixed at `24px` with the shared compact line-height;
- services must not restore larger local top padding or responsive oversized page-title typography;
- eyebrow and description keep the shared component typography and spacing.

## Searchable select filters

All select-like controls used specifically for **filtering lists, tables, registries or dashboards** must use `UiSearchSelect`. The same component may also be used in ordinary forms when search, grouping or hierarchical choices are useful. Native `<select>` remains acceptable inside ordinary forms where none of those capabilities is needed.

The standard platform input/select/filter control height is `32px`, defined by `--irlix-control-height`. Business services must not restore taller local filter controls unless a separate component pattern explicitly requires it.

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

The `actions` slot holds contextual page actions; `breadcrumb-extra` holds a shared view selector, as in Clients requests/reporting periods. A `sidebar` slot supports a service adapter that supplies permission-filtered navigation. Existing layouts may adopt `UiAppTopbar` independently while preserving their business workspaces; they do not copy its CSS. The topbar is 45px high, with common service/section typography, separators, loading and mobile wrapping. Layout-only margins belong to the service; visual values belong to `--irlix-topbar-*` and the shared component. Clients and the catalog use `UiAppShell`; Employees, Vacations, Timesheets, Specialists, Recruitment and CV use the shared topbar in their existing layouts.
