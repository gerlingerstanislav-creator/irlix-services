# Accessibility, responsiveness and visual evidence

Use project's design system and established supported-browser requirements. When none is specified, evaluate against WCAG 2.2 AA as a useful target (not a conformance certification). Confirm interaction in real browsers, not from source-only reasoning.

## Keyboard / assistive-technology paths

- Semantic controls (`button`, labelled `input`, native table/list where feasible), label/error association and announced loading/error/status (`aria-live` sparingly).
- Visual focus, logical tab order, skip navigation, modals with focus containment and restoration, Escape/close policy; focus is not obscured by sticky bars; no keyboard traps.
- Mouse/pointer interactions have a keyboard alternative, especially kanban drag/drop, row actions and disclosure controls.
- Status never signaled by color alone; accessible names for icon-only actions; announce validation and bulk-operation outcomes.
- Consider reduced motion, localization, zoom and 200%/400% reflow. Automated a11y checks are supplemental; real keyboard and screen reader review as available.

## Responsive review matrix

- Start with project's explicit breakpoints. At minimum verify narrow mobile, tablet, laptop-density and desktop widths relevant to its audience; don't invent perfect universal breakpoints. Check long headings, empty/partial tables, dialogs, sticky columns, dropdown overlays, form keyboard, touch targets and landscape.
- Test at user/browser zoom, not only CSS viewport. Prevent clipped actions and horizontal overflow of the whole app; tables may intentionally scroll inside a designated region.
- Dense B2B UI is not automatically required to become cards on mobile: verify product workflow and user information density before restructuring.

## Visual audit protocol

1. Capture actual baseline browser screenshot(s) and viewport + zoom + device pixel ratio, including relevant states.
2. Exercise scenario; capture changed state at **same** viewport, compare for alignment, typography, spacing, overflow, wrapping, clipping, z-index and scroll jump.
3. Verify focus and interactive states in browser. For major responsive changes, repeat across supported breakpoints and themes; use visual regression tests where the project already has them.
4. Evidence labels: **source inspected**; **tests executed**; **browser interacted**; **screenshots compared**. A mocked static HTML preview does not prove integration with live app.

References: https://www.w3.org/WAI/standards-guidelines/wcag/ ; https://vuejs.org/guide/best-practices/accessibility ; https://playwright.dev/docs/visual-comparisons
