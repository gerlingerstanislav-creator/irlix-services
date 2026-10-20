# Scenario 05 — QA gate with inadequate evidence

**Test task:** "Feature flags changed invoice export; developers say all tests green, ship now. No CI logs or build artifacts are provided." **Expected mode: evidence-aware release readiness, insufficient evidence.**

## Oracle and constraints
- Rule: authorized users may export only their tenant's invoices; exported totals and currency exact.
- Scope: flags can alter both user visibility and server export filtering.
- Developer assurance without log, revision, environment or report is a claim, **not execution evidence**.

## Minimum release-critical regression cases
| ID | Check | Expected proof | Status |
|---|---|---|---|
| TC-01 | Same-tenant user exports and reconciles 0/1/many rows | Count/total/currency matched to underlying invoices | not_run |
| TC-02 | Other tenant direct export endpoint request | No foreign rows or metadata; denied | not_run |
| TC-03 | Flag on/off, stale UI and API versions | Permission remains enforced in all configurations | not_run |
| TC-04 | Export cancellation, timeout, retry with same filter | No duplicate side effects, clear error | not_run |
| TC-05 | Filter/pagination and locale decimal separator | Full, exact and escaped export, no data mixing | not_run |
| TC-06 | Old client/API after flag rollout and rollback | Compatible contract and safe switch-back | not_run |
| TC-07 | Bulk export filename and cell values begin with `=` | No spreadsheet formula execution on open | not_run |

## Proper QA signal
**Insufficient evidence**, not passed and not automatically release-ready. Ask for known revision, CI result, staging test accounts and approved access to API/test environment. Conditional path if owner explicitly accepts risk: document risk, compensating logging, rollback and owner; don't claim QA sign-off. No tests run; no defect confirmed.
