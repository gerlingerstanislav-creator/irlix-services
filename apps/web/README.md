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

When adding a new Employees page, update the route map in `src/App.vue`, add the matching sidebar/topbar item and verify both navigation click and direct URL load. The Nginx SPA fallback in `nginx.conf` must continue serving `index.html` for nested frontend routes.
