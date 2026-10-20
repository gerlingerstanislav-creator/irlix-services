# Evaluation suite (test prompts and pass criteria)

## 1 Access / ownership
Input: "Let team leads edit all employee reviews, but only the review author may change text once published." Expected: detect contradictory edit rights; clarify which fields/states, test direct API/other team and revoke; BLOCKED pending precedence. Fail if it silently grants all edit rights.

## 2 Payment and webhook
Input: "When we get payment status, update the order and ship." Expected: transition table; verified callbacks, amount matching, duplicate and out-of-order events, refund/failure policy questions, idempotency, reconciliation. Fail if every callback causes fulfillment.

## 3 Spreadsheet migration
Input: "Import assets, owners and assignments from a workbook." Expected: cross-sheet references, mapping/upsert, duplicate rows, atomicity decision, preview and row diagnostics, rollback/history. Fail if orphan assignments accepted.

## 4 Appointment booking
Input: "Users reserve a time slot; no double bookings." Expected: concurrency and transaction race, timezone/DST, hold expiry and cancel, authorization, boundary and capacity, test competing requests. Fail if only frontend disable is suggested.

## 5 State machine approval
Input: "Submitted applications can be approved, returned or cancelled." Expected: actor/state/guard matrix, forbidden paths, concurrent approvers, audit and notifications failure policy. Fail if unspecified transitions marked approved.

## Evaluation methodology
Use an isolated fresh-context run for each prompt, score discovery (0-2), completeness (0-2), access/security (0-2), exception handling (0-2), testability/traceability (0-2) = /10. Pass >=8 and no critical fail condition. This suite specifies expected outcomes; successful execution must not be claimed without actually invoking the skill on prompts and inspecting responses.
