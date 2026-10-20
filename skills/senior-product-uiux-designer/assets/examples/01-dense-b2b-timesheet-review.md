# Example A: dense B2B timesheet review (synthetic fixture)

**Evidence:** `tests/fixtures/dense-timesheet.html` opened at width 375 in Chromium; screenshot `tests/results/dense-timesheet.png`; scripted DOM inspection. **Not** a review of any real commercial application. No original design tokens supplied; preserve product-specific styles rather than importing a new visual language.

## Problem framing
A payroll/timekeeper user needs to find a person, compare dates and safely edit daily values. A read-only table turned into independent cards may destroy the essential date/row comparison model. Investigate with actual end users before replacing it.

## Findings
- **P1 / high / clipped content:** `.tablewrap` uses `overflow:hidden` and 1310px minimum table width; on 375px screen only the left column is visible. User cannot see/edit dates. **Minimal patch:** horizontally scrollable table region, clear scroll affordance, keep employee identifier and headers in view when possible; alternatively expose an explicit day-detail view on mobile, never silently hide dates. **Verify:** edit day 31 with touch and keyboard on 375px and at 200% zoom; no unlabeled inaccessible data.
- **P1 / high / tiny action:** edit button measured 16×16px, below WCAG 2.2 AA default 24×24 target baseline; determine if an exception applies rather than claiming blanket noncompliance. **Minimal patch:** separate hit area from 30px visual row density; use larger touch interaction via cell-level edit mode, keyboard, or alternate control. **Verify:** measure actual hit region/spacing, keyboard focus visibility, no accidental adjacent edits.
- **P1 / high / opaque status:** orange dots have no names/legend; screen reader and color-impaired users may not know what status means. **Minimal patch:** display short status text/accessible name using existing status component, preserving colors. **Verify:** status perceivable without color, read by AT; terms match actual domain.

## State and QA extensions
Consider unsaved edits, simultaneous approvals, sent/approved/locked, invalid hours, loading/retry, denied role, row virtualization, thousand rows, bulk actions. These were **not** verified in fixture. Confirm state machine with product owner rather than inventing it.

## Handoff sketch
`Given a phone-width screen, when user scrolls within the calendar table, then relevant days remain operable and row identity is recoverable.` `Given an actionable day cell, when focused with keyboard, then visible focus and Enter/Space behavior are consistent with component contract.` `Given status without color perception, then user can determine semantic status.`
