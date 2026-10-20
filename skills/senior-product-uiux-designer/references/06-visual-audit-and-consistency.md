# Visual audit, design system conformance and UX heuristics

## Visual audit procedure
1. Name exact screen/version, role, device width, browser/build, data fixture, viewport, zoom and state. **Missing screenshot/DOM means only conceptual review, not visual inspection.**
2. Screenshot/preview at multiple widths; inspect body, header, navigation shell, overlays and all expanded/collapsed states. If only static image is available, list interactions that cannot be verified.
3. Reconstruct visual hierarchy: task focal point, primary/secondary actions, navigation, errors, status, contrast, whitespace, scanning patterns.
4. Compare neighboring screens and design system: tokens (color, type, spacing, radius, shadow), component shape/states, nomenclature, icons, elevations, alignment, responsive patterns.
5. Test density (compact B2B does not imply unreadability): row rhythm, hierarchy, truncation, grouping, units, empty states, sticky overlays.
6. Inspect interaction feedback by performing tasks; record state transitions and failure recovery.
7. Test accessibility/responsive in `05-accessibility-responsive.md`.
8. Classify issues by impact, evidence, risk and required change size.

## Heuristic lenses adapted from Nielsen's 10 heuristics
- system status / feedback
- mental model and language
- user control, undo, exit
- consistency and learned conventions
- error prevention
- recognition rather than memory load
- expert efficiency and novice affordances
- relevance and visual economy
- recovery instructions
- contextual help and documentation

Combine heuristic audit with task walkthroughs. Do not equate a heuristic mismatch with observed user failure without evidence.

## Visual conformance questions
- Does important content align to an established grid and hierarchy?
- Is text readable with real-world names, unknown numbers, long translations and status labels?
- Are interactive elements visually discoverable and consistently located?
- Do status colors have non-color signals? Are disabled actions explained?
- Are overlays, tooltips, dropdown menus and hover affordances accessible to keyboard/touch?
- Do selected/focused/loading/error states differ clearly?
- Are design tokens reused? Is a local exception justified by a product need?
- Are data and action semantics preserved on small screens?

## Severity and evidence
Use P0/P1/P2/P3 (see research reference). For each issue: ID, screen, role/viewport/state, actual behavior, expected behavior, impact (user/task), observed evidence, reproduction, change proposal, component/token compatibility, confidence (high/medium/low), priority, manual test. Screenshots may establish visual clipping, but NOT keyboard defects or latency unless those were directly tested.

## Anti-patterns and correctives
- **Cosmetic modernization** without task benefit → propose smallest product-compatible change.
- **Magic spacing/colors** → map to existing tokens, raise gap in design system if needed.
- **Hover-only actions** → provide visible or touch/keyboard alternatives.
- **Status as single dot** → label/icon, available detail and accessible name.
- **Huge table turned into cards** → preserve comparison and bulk workflows.
- **Form reset on server refresh** → preserve dirty fields, clarify stale data and merge policy.
- **Error-only toast** that disappears → persistent inline explanation and recovery path.
- **Generic confirmation modals everywhere** → proportional friction, undo where appropriate.
- **'Looks fine' at one width** → test extremes and intermediate widths.

## Deliverable
Use `assets/ui-audit-report.md`. Recommend one conservative patch for each P0/P1 with a precise check; optionally outline a bigger redesign path only after documenting costs and user validation.
