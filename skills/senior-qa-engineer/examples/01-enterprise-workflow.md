# Scenario 01 — Enterprise workflow, changing roles and business state

**Test task:** "Employees submit monthly timesheets; a manager sends and a client approves. Accountants can adjust only specific fields, delegations are temporary. Review and test a change to the approval flow." No repository/runtime is provided. **Expected mode: design-only, not_run.**

## Extracted rules / questions
- REQ-01: approved data is immutable for ordinary actors; exact accounting correction policy **MISSING**.
- REQ-02: delegate has limited, time-bound permissions; grant expiry and effect on active tokens **MISSING**.
- REQ-03: cross-client/cross-tenant records are isolated even via direct endpoints.
- REQ-04: status transition `draft -> submitted -> approved/rejected`; resubmission semantics **MISSING**.
- REQ-05: accounting override, if approved, must preserve approval audit and who changed what.

## Risk-directed cases (hypotheses until rule sources confirmed)
| ID | Case / setup and action | Expected oracle or question | Level | Status |
|---|---|---|---|---|
| TC-01 | Owner edits a draft, reloads | Saved field and audit event; require approved spec | integration/UI | not_run |
| TC-02 | Owner edits approved record via direct API | Denied with no persisted changes if immutable | API/auth | not_run |
| TC-03 | Accountants change approved billable count | Block or scoped override; product decision required | API | blocked: oracle missing |
| TC-04 | Manager attempts transition `draft -> approved` | Forbidden transition; no state or audit mutation | API/state | not_run |
| TC-05 | Submit concurrently in two browser tabs; version stale | Conflict or safe idempotency per spec; no duplicate event | integration | blocked: concurrency oracle |
| TC-06 | Delegate approval just after delegation revoked, old token/tab | Server recomputes grant and refuses mutation | API/auth | not_run |
| TC-07 | Owner guesses another client's record ID; direct GET and PATCH | No foreign read/write and no leaked content | API/auth | not_run |
| TC-08 | Client approves twice; retry after lost response | One effective transition/audit notification | integration | not_run |
| TC-09 | Month boundary, timezone offset, leap day | Day counts correct for declared timezone; ambiguity flagged | domain | blocked: timezone spec |
| TC-10 | Report/export after client approval and accountant adjustment | Consistent representation and actor attribution | E2E | not_run |
| TC-11 | Revoke delegate while approval request in progress | Policy rechecked at mutation/commit boundary | integration/auth | not_run |
| TC-12 | Concurrent data edit and approval | Version guard / no lost update | integration/state | not_run |

## Coverage conclusions
High-risk missing: correction policy, delegation expiry/token semantics, concurrency guard and timezone contract. Need server-level authorization, not only hidden buttons; approval audit must not be silently overwritten. **No tests executed; no defects confirmed.**

## What a weak QA plan would miss
- UI hides approval action but API still accepts it.
- Delegation revoked but stale tab token remains valid.
- Approved data is overwritten through accountant endpoint without attribution.
- Concurrent submit/approve causes lost update or duplicate notification.
