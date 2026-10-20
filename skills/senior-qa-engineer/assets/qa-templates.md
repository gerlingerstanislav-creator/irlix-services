# Ready-to-use QA deliverables

## A. QA discovery snapshot
```
Scope / feature:
User value and critical journeys:
Repository/build/revision (observed or unavailable):
Environment / permissions (read-only, test execution, writes):
Architecture and component boundaries (evidence links):
Existing test tooling / CI / rules:
Data / privacy constraints:
Interfaces, data stores and asynchronous paths:
Required product decisions / assumptions:
```

## B. Requirement & oracle table
| ID | Rule/invariant | Source/version | Actor & scope | State/preconditions | Expected observable effect | Oracle confidence | Ambiguities |
| --- | --- | --- | --- | --- | --- | --- | --- |
| REQ-01 | Only resource owner may update draft | AC-7 | owner / tenant A | draft | persisted update, audit event | approved | none |
| REQ-02 | Approved record immutable | unknown | owner / tenant A | approved | update denied; unchanged | missing | is correction allowed? |

## C. Risk register
| Risk | Failure mode | Harm | Likelihood signals | Impact | Level | Test IDs | Mitigation/residual risk |
| --- | --- | --- | --- | --- | --- | --- | --- |
| R-01 | Cross-tenant read | confidential data breach | resource IDs predictable | critical | high | TC-004 | deny from server |

## D. Test case (human-readable)
```
ID: TC-xxx
Title:
Risk & linked requirement IDs:
Type and level: functional/API/integration/UI/E2E/security-oriented/... 
Priority: critical/high/medium/low
Environment and actor: 
Fixture and preconditions:
Actions:
1. ...
2. ...
Expected: [precise visible response AND side effect / non-effect]
Oracle source and confidence:
Execution: not_run | passed | failed | blocked | inconclusive
Observed / evidence: [only if actually executed]
Cleanup:
```

## E. State transition table
| Current state | Event/action | Guard/role | Next state | Persistent side effects | Inverse/forbidden test |
|---|---|---|---|---|---|
| draft | submit | owner, valid fields | submitted | version+, audit | submit after approved |

## F. Authorization matrix
| Role | Tenant / ownership | Object state | Action | Policy outcome | Server-level case | Test status |
|---|---|---|---|---|---|---|
| viewer | other tenant | draft | read | deny | TC-11 | not_run |

## G. Exploratory charter
```
Mission / risk:
Timebox:
Starting state and data:
Hypotheses and attack surfaces (authorized only):
Paths explored and timestamps:
Observations (fact vs hypothesis):
New cases or bugs:
Stop conditions and cleanup:
```

## H. Traceability and coverage ledger (JSON)
```json
{
  "scope": "Expense approval, build abc123, staging",
  "requirements": [
    {
      "id": "REQ-01",
      "risk": "high",
      "oracle": "approved",
      "tests": [
        {"id": "TC-01", "status": "passed", "level": "api", "evidence": "CI job link or local log path"},
        {"id": "TC-02", "status": "not_run", "level": "ui"}
      ]
    },
    {"id": "REQ-02", "risk": "high", "oracle": "missing", "tests": []}
  ]
}
```
Run: `python scripts/coverage_audit.py path/to/ledger.json`. It reports explicit coverage counts and gaps but cannot confirm that recorded evidence is genuine.

## I. Change impact matrix
| Modified entity/route/schema/rule | Consumers/dependencies | Risks | Targeted regression IDs | Rollback verification |
| --- | --- | --- | --- | --- |
| Approval status enum | UI, exports, webhook consumers | stale mapping | TC-09, TC-14 | old state readable |

## J. Decision log for missing rules
```
DEC-001 | Requirement source: ...
Ambiguity: ...
Options: A / B (observable consequences)
Risk if unresolved: ...
Current test plan: case for each permitted interpretation, blocked assertion
Owner and decision required: ...
```
