# Example D: warehouse tablet / intermittent connectivity (synthetic fixture)

**Evidence:** `tests/fixtures/warehouse-tablet.html` at 800px Chromium viewport with DOM inspection. No physical device, barcode scanner, keyboard or network test carried out.

## Problem framing
Operator wearing gloves uses tablet to inspect stock and change quantities. Precision, screen readability and recoverability under poor Wi-Fi matter more than decorative density.

## Findings
- **P1 / high / miniature controls:** plus/minus buttons measured 22×22px; too small for robust touch and below WCAG 2.2 target baseline absent an applicable exception. Reuse existing large-action variant for tablet and allocate hit regions while retaining compact rows. **Verify:** spacing, touch with gloves, 200% text resizing, unexpected nearby actions.
- **P1 / high / inaccessible detail action:** `div[onclick]` “Детали” is not in tab order and has no button semantics. Use semantic `<button>` or project button component with visible focus. **Verify:** Tab/Enter/Space, screen reader announces action and outcome.
- **P1 / high / status as color dot:** orange dot lacks accessible label. Show “Ожидает синхронизации” or actual domain state label **only after confirming status meaning**. **Verify:** color-independent identification and announce queue/error state.

## Unverified but high-priority questions
Is quantity increment persisted immediately or queued? Is there offline store, duplicate submission protection, conflict resolution and last-synced timestamp? Does role allow counts/editing? Prototype implies internet-based status but does not prove offline logic. Request owner decisions rather than inventing queue policies.

## Handoff sketch
`Given tablet user, when a quantity action is tapped with an imprecise finger, then adjacent actions are not accidentally triggered.` `Given keyboard-only, when focus reaches Details, then user can activate and return to same row.`
