# Interaction Design Specification — [feature]

## Context
User job, roles, platforms, evidenced problem, desired outcomes, non-goals and project rules. Evidence sources and assumptions.

## Design-system contract
Existing components/tokens/typography/layout/icons to reuse; exceptions and why; compatibility with neighboring screens.

## Navigation and IA
Entry point, primary/secondary navigation, deep links, browser/system Back, return focus/scroll, filtering/search/saved view.

## Screen/flow contract
For each step specify: entry/precondition; visible content; primary action; secondary actions; validation; result state; next screen; failure and recovery; permissions; analytics event (when approved).

## State matrix
Link `component-state-matrix.md` or table: idle/loading/empty/no-results/error/success/dirty/offline/conflict/denied + role variations, keyboard/focus behavior.

## Responsive/adaptive rules
Smallest content width, wide viewport, tablet, keyboard/safe area, zoom/dynamic type, long text, list/table transformation, changes in navigation.

## Accessibility specification
Accessible names/roles/states, labels, order/keyboard shortcuts, modal focus handling, announces, contrast/target sizing verification, manual tests required.

## Acceptance criteria
- Given [role/precondition], when [action], then [user-visible result].
- Include negative, async, long content, narrow viewport, touch, keyboard and data-loss prevention.

## Dependencies, risks and open decisions
Product/analyst/QA/backend dependencies; no unsupported changes to API or permission logic.
