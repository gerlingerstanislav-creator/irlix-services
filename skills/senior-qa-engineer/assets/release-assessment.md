# QA release assessment

**Decision signal:** recommend | conditional | do not recommend | insufficient evidence
**Authority:** advisory QA finding; actual go/no-go remains with designated owner.

**Build/change under assessment:**
**Environment & constraints:**
**Executed evidence:** commands, revisions, CI links, report IDs; no invented runs.

| Risk area | Required proof | Observed proof | Status | Residual risk / owner |
| --- | --- | --- | --- | --- |
| Business critical | key invariants, positive/negative | ... | pass/fail/blocked/not_run | ... |
| Authorization | cross-tenant and role bypass | ... | ... | ... |
| Data safety | migration/retry/rollback | ... | ... | ... |
| External interactions | failure/recovery/idempotency | ... | ... | ... |
| Regression | affected adjacent features | ... | ... | ... |

**Findings:** high severity verified first, then hypothesis/requirements gaps separate.
**Coverage:** counts with scope and denominators; explicitly list critical gaps.
**Release blockers and compensating controls:**
**Recommended retests / monitoring after release:**
**Decision owner / unresolved sign-off:**

Do not turn `not_run` into a green indicator. If high-risk gaps are untested, quality signal must reflect uncertainty.
