# @irlix/ui

Shared IRLIX design-system implementation for all internal services.

## Responsibility

This package owns reusable visual primitives and tokens used by the common frontend shell and business services. Product/UX rules live in `ideas/company-internal-services-*/design/DESIGN_SYSTEM.md`.

## Current structure

- `src/styles/tokens.css` — colors, spacing, radii, control/table density;
- `src/styles/base.css` — shared form and data-table foundations;
- `src/components/UiButton.vue`;
- `src/components/UiBadge.vue`;
- `src/components/UiPanel.vue`;
- `src/components/UiPageHeader.vue`.

The common frontend shell contains a **Design System** section that renders the current tokens, components and UI patterns as a live catalog. When a shared component or visual rule changes, the catalog should be updated in the same iteration.

New components should be added when there is a real repeated use case. Service-specific business components should stay in the service/web application unless they become reusable patterns.
