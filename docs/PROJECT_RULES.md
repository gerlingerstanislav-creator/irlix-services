# Project rules

The canonical project working rules are stored in `/AGENTS.md` and must be read before changes. This file exists as a documentation index entry only; do not duplicate or fork the rules here.
### Client contour and timesheets

- Management final approval is stored per specialist and project for the month; it never grants approval to another project.
- Reporting-period links open Timesheets management with `section=management` and scoped client/employee filters. The shared section router must preserve this explicit section and canonicalize it to `/timesheets/management/`, never to `/timesheets/mine/`.
- Client cards expose the client project collection; the default project is protected from rename/delete and projects with members cannot be deleted.
- Absence hatching is a transparent overlay, so the underlying green preliminary/final confirmation remains visible; hatching CSS must not reset the cell `background-color`.
- Every Clients sidebar page must have a canonical route. The permission matrix page uses `/clients/permissions/` and must stay open when selected from the sidebar.
- Account Manager has `reports.view` and `reports.manage` within own scope and must see the `Отчётные периоды` section. Account-manager role identifiers from Employees are normalized to the Clients contour role naming before permissions are resolved.

