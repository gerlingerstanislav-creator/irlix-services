# Service-level access contract

## Single source of truth

`GET /api/employees/access/me` returns `data.services`: a map of stable service keys to effective boolean entitlements. Employees resolves organizational membership and assigned special roles. The dashboard cards and the service switcher consume this map via `getVisibleServiceGroups`; missing keys or unavailable access data grant **no** access.

`audience` in `packages/ui/src/serviceCatalog.js` is display-only metadata, not an authorization policy. A service cannot grant access by setting `available: true`.

Backend access is enforced separately for each request. The Equipment backend validates the signed user token, asks Employees for the effective `services.equipment` permission, and rejects missing/negative/unavailable decisions without a permissive Keycloak-role fallback. Its frontend checks the same permission before mounting. These are required enforcement boundaries, not independent access rules.

## Updating access

1. Change the effective entitlement rule in `services/employees/app/Support/EmployeesAccess.php` (later move policy to a configurable permissions matrix without changing this API contract).
2. Update the accompanying product audience label in `serviceCatalog.js`, if needed. The label must never decide authorization.
3. Confirm dashboard, sidebar, direct URL, and API results with and without the entitlement. Test role revocation and Employees downtime.
4. A new service must implement backend entitlement enforcement (deny by default), even though visibility in the global launcher requires no separate logic.

## Implemented enforcement coverage

- Employees: locally enforces its permissions and scope; its self/access routes remain available for cross-service authorization.
- Vacations, Clients, Timesheets, Specialists, Recruitment: the API middleware verifies the user's current Employees service entitlement on every authenticated request. Domain-specific authorization follows; a service entitlement is not permission to perform every operation.
- Equipment: checks the same contract at the API boundary.
- CV Converter: its FastAPI endpoints require the Employees entitlement, with no Keycloak realm-role shortcut.
- Migration: authorizes through Employees and checks `services.migration` in addition to its existing console-state checks.
- Design System: static frontend showcase checks the Employees entitlement before mounting; it has no business-data API.
- Portal/Dashboard: reads Employees entitlements and only advertises authorized services; every frontend also checks the entitlement on direct entry.

Health probes remain accessible for infrastructure readiness. Internal system routes are protected by their existing integration-auth mechanisms and are not browser-facing service pages.

**Audience rules:** Clients admission is limited to the client service, accounting/account management, sales, production direction leads and platform administrators (except the platform tester, who is deliberately excluded). Specialists is for production direction leads and platform-privileged roles. Recruitment is for HR/recruitment, organizational managers, personnel officers and platform-privileged roles. These gates are resolved by Employees from organizational membership, special roles, and position metadata. A change to the Employees service-level policy takes effect in Dashboard, sidebar, direct URLs and API routes without duplicating that policy.

**Remaining distinction:** detailed Clients/Timesheets permission-matrix changes can further limit available pages/actions without necessarily revoking service-level admission. Any future policy that requires removing the whole service as a result of a domain-matrix change must feed the effective service-level entitlement into Employees; avoid circular synchronous requests from Employees to a backend that itself calls Employees.


Platform admin and platform tester are distinct: migration is administrator-only. Equipment is granted to platform privileged accounts, explicitly assigned system admins, and employees in accounting departments and descendants. In Equipment, issuing/returning hardware remains limited to platform privileged users and system admins; accounting has management/valuation access but cannot issue hardware.
