# Design System catalog

The live catalog at `/design-system/` renders the same `@irlix/ui` components and tokens as business services. It is a frontend application without a backend or live business data.

Default shell: `UiAppShell` supplies the live sidebar and topbar with service name and automatic breadcrumbs. The navigation section additionally demonstrates linked ancestor breadcrumbs.

Sections: foundations, components, working patterns, navigation. Sidebar items scroll to their corresponding section. Examples cover all exported primitives, multiple/grouped selects, table/empty/error states, Clients hierarchy controls and table group headings, plus nested resizable drawers. All fixtures are synthetic.

When shared UI changes, update its example and `packages/ui/README.md` in the same task. Product specification: `ideas/company-internal-services-*/design/DESIGN_SYSTEM.md`. Clients is a reference for current composition, while `packages/ui` owns reusable visual behavior. Consult only the relevant component/contract according to `docs/CONTEXT_SCOPE.md`.

Validate with a production build and visually check desktop/mobile, menu navigation, filter dropdowns, hierarchy, sticky table scrolling and drawers. Shared UI changes also require builds of affected consuming frontends through the unified CI.
