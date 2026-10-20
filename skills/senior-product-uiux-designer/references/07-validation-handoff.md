# Validation, usability testing, developer and QA handoff

## Prepare task-based usability test
1. Test objective: design decision and measurable unknown.
2. Participants: real target roles, levels of expertise and access needs. Separate cohorts if behavior differs.
3. Consistent realistic fixtures; avoid identifying sensitive data.
4. Scenarios formulated as outcomes; do not tell participant which menu/button to press.
5. Success criterion defined beforehand; observe completion, errors, detours, help, recoveries.
6. Pilot the protocol and allow user control/consent; do not collect more data than needed.
7. Synthesize per issue with evidence, not generalized certainty; triage and iterate.

## Validation layers
**Level 1. Heuristic / specification review:** can happen without implementation. Detect mismatched labels, missing states and flow gaps.

**Level 2. Prototype walkthrough:** confirm navigability, comprehension and state flow, using tasks. Static prototype cannot validate network, screen reader behavior or production performance.

**Level 3. Implementation verification:** inspect shipped state, links, keyboard/focus, mobile touch, zoom/RTL/languages if applicable, actual performance and no data loss. Include QA role/permission checks and regression checks of reused components.

**Level 4. Outcome measurement:** after controlled release, compare against baseline and segmented metrics; report confounders.

## Developer handoff contract
Deliver:
- Screen purpose, route/entry, roles and access matrix.
- Existing components/tokens to reuse; new component exceptions and ownership.
- IA/flow and triggers, state machine, loading/failure/empty/dirty/conflict behavior.
- Responsive behavior rules across content widths (not just three static mocks).
- Content/labels, localization, truncation and data examples.
- Accessibility semantics, keyboard order, focus destination, notifications.
- Data rules and server expectations requiring analyst/product confirmation.
- Acceptance scenarios in `Given / When / Then` readable by QA.
- Instrumentation and UX metrics, if appropriate and authorized.
- Explicit open questions / assumptions / out-of-scope.

## Acceptance checklist for QA/design review
- Functional: happy path, negative path, saved/unsaved, stale data, permission variation, bulk operations, partial completion.
- Content: empty/invalid/very-long values, 0/1/many rows, translations.
- Visual: layout, tokens, states, responsive breakpoints and intermediate widths.
- Accessibility: keyboard, focus, semantics, dynamic announcements, contrast, zoom, touch, screen reader.
- Reliability: pending, network error, timeout, offline/duplicate, version conflict.
- Existing design: reused components across old screens not broken by new variant.

## Bug report evidence
`ID; environment and URL; role; viewport/zoom; fixture; prerequisites; steps; observed; expected; impact; screenshot/video/trace; accessibility evidence if applicable; reproducibility; priority; suspected component; open decision`. Never produce a fabricated screenshot or claim recorded video.

## Definition of done
Not simply “mockups done”. Define: approved interaction spec; states documented; project design-system consistency; accessibility risks reviewed; QA acceptance clear; critical tests actually executed or marked pending; known tradeoffs accepted by owner.
