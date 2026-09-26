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

## Other shared components

- `UiButton`, `UiBadge`, `UiPanel`, `UiPageHeader`;
- `UiDrawer`, `UiTabs`, `UiSegmentedControl`, `UiViewSwitch`, `UiFilterBar`;
- common form/table foundations in `src/styles/base.css`.

The **Design System** application renders the current tokens, components and UI patterns as a live catalog. When a shared component or visual rule changes, the catalog must be updated in the same iteration.

New components should be added when there is a real repeated use case. Service-specific business components should stay in the service/web application unless they become reusable patterns.
