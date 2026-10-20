# Scenario 03 — Spreadsheet import across related sheets

**Test task:** "A system imports three tabs (dictionary, devices, assignments) from an XLSX with a button in the app. Existing data must not disappear." Only the intended input shape is provided. **Expected mode: requirement review + fixture design, not_run.**

## Requirements & ambiguities
- REQ-01: dictionary entities resolve references from devices and assignments.
- REQ-02: existing records remain safe; upsert identity rules and partial-import semantics **MISSING**.
- REQ-03: bad rows yield actionable row/sheet errors, without silent skip.
- REQ-04: uploads only by authorized role; oversized/malformed files have bounded resource consumption.
- REQ-05: import can be retried safely and its effects are auditable.

## Cases
| ID | Fixture/action | Expected or question | Level | Status |
|---|---|---|---|---|
| TC-01 | Valid 3-sheet file, 1 device assigned | Correct foreign key and visible assignment | integration/UI | not_run |
| TC-02 | File missing assignments sheet | Error without partial destructive updates unless explicitly permitted | integration | blocked: atomicity |
| TC-03 | Duplicate device key within one sheet | Conflict report or deterministic chosen policy; no silent duplicates | domain | blocked: key policy |
| TC-04 | Two sheets disagree on reference IDs | Row-level source-aware diagnostics | integration | not_run |
| TC-05 | Re-import identical file | Idempotent, no duplicate assignments | integration | not_run |
| TC-06 | Existing device with edited model; failed second sheet | Existing record unchanged if atomic; else documented partial result | integration | blocked: transaction |
| TC-07 | Empty file, extra sheet, reordered columns | Validated schema and recognized headers, documented behavior | parser | not_run |
| TC-08 | Formula cell instead of expected text or reference | No accidental evaluation/trusted execution; safe reject/normalize | parser/security | not_run |
| TC-09 | Huge file, very long cell, Unicode, blank vs null | Bounded CPU/memory, correct mapping, useful errors | parser/performance | not_run |
| TC-10 | Unauthorized manager opens import endpoint directly | Server denies and no data mutation | API/auth | not_run |
| TC-11 | Exported error report includes cells starting =/+/-/@ | Spreadsheet-safe output without formula execution | integration/security | not_run |
| TC-12 | Race: two imports of same data | Uniqueness/integrity preserved | integration/concurrency | not_run |

## Coverage conclusions
The most dangerous unresolved choice is whole-file rollback vs partial rows, and identity mapping for upserts. Use **synthetic XLSX fixtures**, never upload actual private table data to an external service. No real import performed; no bug confirmed.
