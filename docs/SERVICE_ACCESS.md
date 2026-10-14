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

## Current migration boundary

Equipment uses the service-level authorization contract end-to-end. Other existing services retain domain-specific backend permission checks (e.g. Clients permission matrix) and require an explicit adapter to the Employees service entitlement before they can be declared fully centralized. Their current coarse launcher entitlements are compatibility defaults, **not** a claim that all service APIs have migrated. Any new restriction to those services must update backend guards together with Employees policy.

Platform admin and platform tester are distinct: migration is administrator-only. Equipment is granted to platform privileged accounts, explicitly assigned system admins, and employees in accounting departments and descendants. In Equipment, issuing/returning hardware remains limited to platform privileged users and system admins; accounting has management/valuation access but cannot issue hardware.
