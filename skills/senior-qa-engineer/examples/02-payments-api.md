# Scenario 02 — Payment/order API, idempotency and distributed failures

**Test task:** "Design QA for API `POST /orders/{id}/pay`, external payment processor and webhook; client may retry after timeout." No implementation or contract is supplied. **Expected mode: design-only, not_run.**

## Rules and unknowns
- REQ-01: one intended payment creates at most one charge; precise idempotency scope and retention **MISSING**.
- REQ-02: balance/order state must match confirmed processor state after reconciliation.
- REQ-03: callbacks may be duplicated or out of order; status monotonicity/compensations require specification.
- REQ-04: amount/currency come from trusted priced order, not editable client payload.
- REQ-05: actor must own or be entitled to pay order; no cross-tenant resource lookup.

## Prioritized test design
| ID | Stimulus | Observable assertion | Level | Status |
|---|---|---|---|---|
| TC-01 | Valid amount, permitted owner, success | One charge, one order mutation, traceable receipt | E2E | not_run |
| TC-02 | Two same requests with same idempotency key concurrently | One effective charge; deterministic replay result | integration | not_run |
| TC-03 | Same key but different amount/body | Reject or prescribed policy; no surprise charge | API | blocked: policy missing |
| TC-04 | Timeout after PSP charged, before local acknowledgment | Retry reconciles without second charge | integration/fault injection | not_run |
| TC-05 | PSP declines payment | No paid order, no captured charge; useful error | integration | not_run |
| TC-06 | Duplicate success webhook | One state transition / one downstream notification | integration | not_run |
| TC-07 | Success webhook arrives before pending or after refund | Defined state machine; no invalid downgrade | integration/state | blocked: oracle missing |
| TC-08 | Foreign tenant order ID with valid token | No data leak or payment; no mutation | API/auth | not_run |
| TC-09 | Client alters amount or currency (0, negative, rounding) | Trusted server recalculation/rejection; no undercharge | API/security | not_run |
| TC-10 | DB fails after PSP success | Reconciliation record/alert and eventual consistent state | integration/fault injection | not_run |
| TC-11 | Charge starts twice after race on different app instances | Unique guard at trusted boundary; one captured payment | integration/concurrency | not_run |
| TC-12 | Consumer version n-1 receives new optional webhook field | Backward-compatible parsing, contract verified | contract | not_run |

## Coverage conclusions
Do not falsely declare exactly-once network delivery; specify effective-once financial outcome using idempotency and reconciliation. High-risk test gap exists until PSP sandbox + contract + failure injection hooks are available. **No real charges or external calls performed; no defects confirmed.**
