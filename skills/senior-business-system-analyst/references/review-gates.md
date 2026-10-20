# Review gates and impact analysis

## Gates
- G1 source/provenance: facts vs assumptions tagged; stakeholder/authority and objective identified.
- G2 scope: current/target behavior; explicit exclusions, dependencies and affected actors.
- G3 consistency: no contradictory BR, state transitions, permission rules or acceptance results; version and vocabulary coherent.
- G4 completeness: critical normal/error/negative/denied paths; boundaries/concurrency; data and external failures; audit/privacy as relevant.
- G5 verifiability: every high-risk requirement has objective oracle, AC and trace ID; no unquantified NFR.
- G6 delivery: interface/data migration if needed; backward compatibility, rollback, observability, release notes and impacted regression suites.

Outcomes: `READY` (all applicable gates pass), `CONDITIONAL` (non-blocking assumptions noted), `BLOCKED` (security, critical rules, data correctness or high-impact decision unresolved). Do not report false numerical coverage without a defined denominator.

## Impact lens
Map direct vs downstream effects for actor/process, UX, authorization, API/contracts, storage/schema, events/jobs, third parties, reporting/billing, audit/privacy, data migration, deployment/rollback, support, testing. For each: affected component, nature, certainty, owner, test consequence. Explicitly document no-impact only when checked.

## Independent adversarial review
Attempt to falsify spec: another actor bypasses UI; retry duplicates action; two concurrent users conflict; legacy data differs; integration delivers events out of order; permission revoked mid-session; import half-fails; timestamp crosses DST; administrator edits already finalized records. Turn discoveries into requirement/AC or decision question.
