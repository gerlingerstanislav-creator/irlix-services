# Example B: mobile restaurant preorder review (synthetic fixture)

**Evidence:** `tests/fixtures/mobile-preorder.html` at 375px, screenshot and Chromium DOM checks. No real provider integration, customers or allergy profile used.

## Problem framing
The customer chooses an item, table and payment. It is high-friction and potentially safety-relevant when product allergens conflict with a declared preference; storing and sharing health-relevant preferences needs user transparency and consent.

## Findings
- **P0 / high / allergen contradiction:** mock shows “Содержит орехи” while the profile contains “орехи” as an allergy, yet pay action has no explicit visible warning. **Minimal patch:** clear persistent conflict warning before checkout with user-controlled correction/removal path; clearly distinguish authoritative ingredient data vs restaurant uncertainty; confirm business policy for potentially unsafe order rather than choosing policy unilaterally. **Verify:** conflict detectable visually and via screen reader; user cannot proceed inadvertently under the approved business rule; no unconsented transmission of private preference data.
- **P1 / high / selection state:** selected seat has CSS color only, no `aria-pressed` / semantic selected state. Use accessible `aria-pressed` on toggle buttons or single-select radio group, in line with existing components. **Verify:** mouse, keyboard, touch, assistive tech; distinguish unavailable vs chosen seat.
- **P2 / high / small primary button for touch comfort:** 38px-tall pay button is below common 44pt iOS ergonomic recommendation, but **not automatically** a WCAG failure. Align with existing mobile touch sizing tokens; check actual CSS pixels and platform. **Verify:** comfortable touch on device and dynamic type, no safe-area overlap.

## Missing states to specify (not proven defects)
Reservation stale or contested, changing menu availability, no online payment, timeout, duplicate order, cancellation/payment failure, table conflict, allergy data out of date, missing confirmation of preferences, location/consent. Ensure seat occupancy is rechecked server-side and UI does not promise guaranteed availability early.

## Handoff sketch
`Given an item with an ingredient matching a confirmed allergy, when it appears in checkout, then a prominent actionable conflict is displayed before order confirmation according to approved policy.` `Given selection of seat 2, then selection is conveyed without reliance on green color and is announced to assistive technology.`
