# 06 — Evidence discipline, reports, quality gates

## Truthfulness taxonomy
- **Confirmed defect:** observed actual != justified expected on an identified build/environment; reproducible or backed by reliable trace.
- **Likely defect / needs validation:** credible code/contract discrepancy or one-time incident, insufficient reproducibility.
- **Requirement gap / conflict:** cannot specify expected behavior without product decision.
- **Test failure:** test failed; may be product defect, test defect, environment error or inconclusive.
- **Passed:** executed assertion succeeded on scoped environment/revision; never extrapolate to other builds.
- **Blocked:** couldn't execute; list root blocker and missing proof.
- **Not run:** designed only. **Not applicable:** surface absent.

## Bug report quality standard
Minimum report: compact title with failure, severity + impact, environment/build, source requirement/oracle, preconditions and synthetic test data, deterministic steps, expected result, actual result, exact evidence (request/response redacted, logs, trace, video, screenshot, test ID), reproducibility, containment/workaround if known, regression test idea, assumptions. Severity = impact of failure; priority = scheduling/urgency; distinguish. A suggestion or suspected root cause must be labeled hypothesis, not fact.

Evidence guidelines: prevent leaks in logs/screenshots; strip tokens, phone/email/account IDs, vendor keys, user content. Never invent ticket URLs or screenshots. Avoid exposing confidential test data in deliverables; provide sanitized minimal reproduction. Check whether test data can be safely deleted.

## Requirement and risk coverage
Report denominators by category:
- Rules covered by at least one meaningful case / total in scope.
- Critical rules with executed and passing evidence / total critical rules.
- Role-permission combinations exercised / in-scope combinations.
- Allowed and forbidden transitions exercised / defined transitions.
- API operations/error modes, target platforms, integration failure modes separately.
- Statement/branch/path code coverage **only if measured** by a tool; do not equate statement coverage with correctness.

Mark uncovered, failed, blocked and `oracle_unknown` explicitly; do not turn a case with an unknown expected result into a pass. Avoid averaging risk buckets: a 95% overall rate does not offset an untested authorization bypass path.

## Quality gate rubric
- **Recommend:** critical flows and access boundaries have credible evidence, no unresolved blocker in agreed scope, residual risks accepted by owner.
- **Conditional:** meaningful gaps remain but are bounded, mitigations and owners recorded, stakeholder decision needed.
- **Do not recommend:** confirmed release-blocking failure, uncontrolled data/permission issue, untested critical migration/reconciliation, destructive uncertainty.
- **Insufficient evidence:** environment unavailable, important cases not run, requirements/oracles missing. This is not a pass.

For each gate, say what changed, evidence scope, blocking findings, what has not been checked, and risk owner. Do not claim release approval as QA. Prior release quality doesn't automatically imply current build.

## Final report outline
1. `Scope and baseline`: requested change, version, environment, authorization.
2. `Summary`: quality signal and 2-5 most important findings.
3. `Findings`: defects (verified or hypothetical label) and requirement gaps.
4. `Coverage`: concise matrix, counts/denominators and high-risk gaps.
5. `Executed`: exact run and evidence, skipped/not_run distinction.
6. `Next`: mitigation, targeted retests, regression and stakeholder decisions.

If task is limited to one failing test, shorten this structure; don't force a long report.
