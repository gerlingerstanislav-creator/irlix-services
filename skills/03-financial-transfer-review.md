# Example C: consequential transaction form (synthetic fixture)

**Evidence:** `tests/fixtures/transfer-form.html` at 390px; Chromium tested click with blank amount. No backend or real money transfer.

## Problem framing
A transfer must be unambiguous, correctable and honest about transaction state. Design cannot assert server success if only a local click event occurred.

## Findings
- **P0 / high / false completion:** clicking “Отправить перевод” immediately shows “Успех” with no request, confirmation or status source. **Minimal patch:** after valid submit show pending state; report success only on confirmed result, error/unknown on timeout; tie status to server/backend contract and unique operation ID. **Verify:** pending, success, error, retry and timeout fixtures with product and backend teams. Existing success text/visual language should be reused.
- **P1 / high / unlabeled amount:** amount field has placeholder only; it disappears during editing and has no form-associated label. Add stable label and unit/currency; help/error linking. **Verify:** label persists on filled, focused and error states; screen reader announces field and currency.
- **P1 / high / submission without validation:** blank amount can be submitted; app shows success. Specify positive permitted amount, decimal currency precision, sufficient balance etc only with domain owner, not guess from UI. Validate with clear inline errors and move focus appropriately. **Verify:** empty, zero, negative, malformed, excessive amount, duplicate-click and network error; no misleading completion.

## Additional design dependencies
Recipient disambiguation, confirmation or review step based on risk, destination details, accessibility of copy/paste, fraud controls, authorizations, audit. Don't claim regulatory adequacy from these mockups.

## Handoff sketch
`Given missing amount, when user submits, then the request is blocked before dispatch and a field-level error explains correction.` `Given request timeout, when user returns, then UI does not claim confirmed transfer without a verified status.`
