# Accessibility, responsive/adaptive and inclusive design

## Normative priority
For web use WCAG 2.2 AA as **recommended evaluation baseline** unless owner defines regulatory/contractual criteria. Do not claim legal compliance without jurisdiction. Understand which success criteria apply and their exceptions. For interactive custom controls use W3C WAI-ARIA APG as an implementation pattern guide, not a substitute for testing with assistive technologies. For native iOS/Android use their platform patterns, accessibility APIs and design guidance.

## Concrete reviews
- Semantics: headings in order, landmarks, labels, input descriptions, meaningful titles, announced dynamic changes; don't use ARIA when native control works.
- Keyboard: all meaningful controls accessible; logical focus order, clear visible focus, no keyboard trap outside intentional modal, exit dialogs, grid navigation only if intentionally implemented.
- Screen readers: component role/name/value/state; status notifications without spam; tables preserve header associations; errors associate to fields; selected and disabled distinguished.
- Contrast: WCAG 2.x normal text at least 4.5:1 and large text at least 3:1 under AA, with specified exceptions; non-text UI components/meaningful graphical objects at least 3:1 where applicable. Audit actual rendered colors, not token names.
- Pointer/touch: WCAG 2.2 SC 2.5.8 AA target at least 24×24 CSS px **or an enumerated exception such as sufficient spacing**. This is a *web criterion* and not interchangeable with design recommendations: iOS comfortable primary controls often aim for 44×44 pt. Consider density and separate interactive area from visual row height.
- Layout and magnification: inspect reflow at 320 CSS px-equivalent viewport where criterion applies, up to 400% zoom, resizing text to 200%, browser font overrides, dynamic type, visible sticky regions. Do not block orientation without real necessity.
- Input: labels, instructions and validation not conveyed by color/placeholder alone; avoid requiring dragging, swiping or multi-pointer gestures without alternatives; sensible touch targets.
- Motion/media: respect reduced-motion preference where relevant; provide captions/transcripts if media needed; avoid flashing.
- Language/internationalization: date/number/currency formats; translation expansion; long proper nouns; RTL support where product requires; plain language and inclusive terminology.
- Cognitive accessibility: consistent actions, no unexpected focus changes, avoid re-entry, clear correction, predictable navigation and sufficient time for tasks.

## Responsive audit matrix
Default exploratory widths (not mandated breakpoints): narrow phone ~320–390 CSS px, large phone ~430–480, tablet 768–1024, laptop ~1280–1440, wide 1920; add intermediate widths where behavior breaks. Test portrait/landscape, zoom, dynamic type, keyboard covering lower screen, browser bars/safe areas, scrolling in both axes, long content, empty/overfull states. Adaptive breakpoints derive from content constraints and existing project system.

For each page ask:
1. Is the primary action discoverable and operable with touch and keyboard?
2. Do rows/columns remain comprehensible? Are tooltips necessary for clipped labels, and can they be reached without hover?
3. Do overlays or sticky sections obscure focus, controls or errors?
4. Does order of content make sense when stacked and when read linearly?
5. Can users recover from network/validation failures without losing context?

## Contrast and target nuance
WCAG thresholds are not an excuse to shrink main controls to 24px; that's a threshold with exceptions, not a universal good-touch-size recommendation. Conversely a compact 30px visual row doesn't automatically violate the target-size criterion when actual targets meet it or a relevant exception applies. Document the measured target, surrounding targets, input modality and exception. For inaccessible legacy patterns, report exact failure instead of broad claims.

## Verification levels
- Static review: known issues and likely risks; cannot certify.
- Automated checks (axe, lint, contrast tool) when available: useful subsets and regressions; false negatives remain.
- Manual keyboard, zoom, screen reader (VoiceOver, TalkBack, NVDA or equivalent) and real device testing when possible.
- User testing including participants with relevant access needs for high-impact flows where feasible.

## Examples of acceptance checks
- With only keyboard a user can search, select, edit, cancel and complete the main task, retaining a visible focus path.
- On a narrow phone form labels and field-level errors remain readable with software keyboard open.
- Screen reader announces sorting state, table headers, selected row and async-save failure.
- At increased zoom the page does not lose information or require two-dimensional scrolling in contexts where WCAG reflow applies; if exception applies to inherently two-dimensional data tables, still provide keyboard and comprehension support.
