# Web platform shell

Common Vue frontend for IRLIX Services.

Iteration 1 provides the platform landing page and live health state for Platform Core and Employees. Global navigation and shared design-system components will grow here instead of being duplicated by business services.

## Mandatory page-routing contract

Every page/section that appears as an independent item in service navigation MUST have a canonical browser URL and MUST work when that URL is opened directly or refreshed.

For Employees the canonical routes are:

- `/employees/` — employee registry;
- `/employees/departments` — organization structure;
- `/employees/staff-positions` — staffing positions;
- `/employees/roles` — special roles;
- `/employees/audit` — audit trail.

A new page is not considered implemented if it only changes local Vue state such as `currentSection` without synchronizing browser history. Navigation must update the URL, direct load must restore the same page, and browser Back/Forward must restore the matching section. Query parameters that represent page filters must remain compatible with the canonical route.

Internal sidebar navigation uses `history.pushState` and immediately synchronizes `currentSection` from the resulting URL; it must not reload the document with `location.assign`. The existing `popstate` listener restores the section on Back/Forward. Direct entry and refresh initialize the section from the current pathname after authentication.

When adding a new Employees page, update the route map in `src/App.vue`, add the matching sidebar/topbar item and verify both navigation click and direct URL load. The Nginx SPA fallback in `nginx.conf` must continue serving `index.html` for nested frontend routes.

Page visibility and page management are separate permissions. A read permission controls whether a navigation item and page can be opened; a manage permission controls editing actions inside that page. In particular, staffing uses `staff_positions.read` for navigation/rendering and `staff_positions.manage` only for create/edit/close/delete actions.

## Employees shell UX

Employees uses the shared service topbar as the only page-level heading. Do not add a second `UiPageHeader` below it for standard Employees sections.

The topbar contract for Employees is:

- it touches the top viewport edge with no workspace gap above it;
- left side contains the service/section breadcrumb;
- compact page totals and status summaries live on the right side;
- primary entity actions (`+ Сотрудник`, `+ Подразделение`, `+ Должность`, role assignment and similar actions) live on the far right;
- page content starts immediately below the topbar.

Registry filters are not rendered as a horizontal toolbar in Employees. The employee registry uses shared `UiFilterRail` from `@irlix/ui`:

- collapsed state is a narrow right-side icon rail;
- each active filter is indicated only by a subtle grey icon background;
- hovering anywhere over the filter rail opens the complete filter panel;
- clicking the rail pins/unpins the panel;
- the open panel contains all filter controls for the current registry and a `Сбросить все` action;
- hovering an active filter icon exposes its current value through the tooltip/title;
- the expanded panel overlays content instead of reflowing the table.

`UiFilterRail` belongs to the shared design system, but adoption by other services is separate work and must not be done implicitly when changing Employees.

## Deployed navigation verification

The main release runs `scripts/ci/check_employees_navigation.cjs` in Chromium against frontend HTML/JS served by the stand nginx, through an SSH tunnel. It checks the staffing sidebar click, canonical URL, same-document navigation, Back/Forward, refresh and direct entry. OIDC and business API responses are synthetic, so this check does not read employee records or validate real-account permissions. A release remains failed if this check or subsequent runtime checks fail.
