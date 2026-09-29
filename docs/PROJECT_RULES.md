# Project rules

The canonical project working rules are stored in `/AGENTS.md` and must be read before changes. This file exists as a documentation index entry only; do not duplicate or fork the rules here.
### Client contour and timesheets

- Management final approval is stored per specialist and project for the month; it never grants approval to another project.
- Final approval on the Timesheets management page must always expose an explicit action for both states: `Подтвердить` when not approved and `Снять` when approved. Removing approval is allowed while the related reporting period is `Новый`; after the period enters the client approval workflow it is blocked until the reporting period is returned to `Новый`.
- Reporting-period links open Timesheets management with `section=management` and scoped client/employee filters. The shared section router must preserve this explicit section and canonicalize it to `/timesheets/management/`, never to `/timesheets/mine/`.
- Client cards expose the client project collection; the default project is protected from rename/delete and projects with members cannot be deleted.
- Absence hatching is a transparent overlay, so the underlying green preliminary/final confirmation remains visible. Combined `prelim/final + absence-*` states must explicitly retain the approval `background-color`; absence styles may change only the overlay/background image and must not reset the approval color.
- Every Clients sidebar page must have a canonical route. The permission matrix page uses `/clients/permissions/` and must stay open when selected from the sidebar.
- Account Manager has `reports.view` and `reports.manage` within own scope and must see the `Отчётные периоды` section. Account-manager role identifiers from Employees are normalized to the Clients contour role naming before permissions are resolved.
- Clients uses the `clients-directory` integration for employee names and Account Manager context. Its page must never fall back to the Employees registry, which has a separate access policy. Reporting periods render once in the Clients application; an old overlay must not make a second Employees registry request.
