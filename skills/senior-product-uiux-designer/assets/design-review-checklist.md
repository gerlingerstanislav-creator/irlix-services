# Senior UI/UX Design Review Checklist

Mark `pass / fail / untested / not applicable` and reference evidence; a checkbox alone is not a verified test.

## Product and interactions
- [ ] Critical role/task has clear entry and completion.
- [ ] Navigation matches user information model, current position and back behavior.
- [ ] Primary/secondary/destructive controls prioritized appropriately.
- [ ] Permissions and denied state documented; backend remains authoritative.
- [ ] Data states: empty, large, stale, pending, errors, conflict, retry, dirty.
- [ ] No silent data loss or misleading success status.

## Consistency and visuals
- [ ] Existing design tokens/components preserved or deviations approved.
- [ ] Labels, icons, units, table density and status conventions match product.
- [ ] Hierarchy and alignment hold with long text and localizations.
- [ ] Focus, selection and error visual states are distinguishable.

## Responsive and accessibility
- [ ] Keyboard path/focus tested manually where implementation exists.
- [ ] Semantic controls and ARIA/accessibility API semantics checked.
- [ ] Text/non-text contrast checked against applicable criteria.
- [ ] Touch targets, spacing, alternatives to drag/hover considered.
- [ ] Small, intermediate, wide; zoom; dynamic text; overlays checked.
- [ ] Screen reader/manual AT coverage included or marked pending.

## Delivery
- [ ] Acceptance criteria include negative and boundary cases.
- [ ] Visual issues linked to actual evidence and viewport.
- [ ] Regression risk and dependencies captured.
- [ ] Stakeholder decisions and test limitations transparently stated.
