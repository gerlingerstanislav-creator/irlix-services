# Senior QA Engineer — Offline quality assessment

**Assessment type:** deterministic package checks and five manually authored QA design dry-runs (no live skill invocation, no application runtime). **External repositories changed:** none.

## Practice scenarios reviewed

| Task | Difficult risks considered | Outcome |
|---|---|---|
| Enterprise business workflow | Delegation/revocation, cross-tenant access, immutable approval state, concurrency, audit | Reference casebook created and asserted |
| Payment API | Retries, exactly-once **effective** outcome, duplicate/out-of-order webhooks, timeout after charge, reconciliation | Reference casebook created and asserted |
| Multisheet spreadsheet import | Three-tab identity mapping, unknown transaction semantics, idempotency, oversized and formula cells, direct endpoint access | Reference casebook created and asserted |
| Offline mobile notes | Process death, sync queue, conflict resolution, token expiry, revoked collaborator, privacy on logout | Reference casebook created and asserted |
| Feature flag/release readiness | Unproven CI claim, cross-tenant export, rollback and regression, unknown environment | Result explicitly **insufficient evidence**, not "passed" |

## Issues discovered and corrected during package development

1. **False-positive coverage pass:** a test marked `passed` with empty or whitespace-only evidence was accepted. Now rejected. Regression test: `test_a_pass_requires_specific_evidence`.
2. **Mixed test status could be misleading:** a single passed test could make an entire requirement look covered even if other cases failed, were blocked or never ran. Now requirement-level evidenced-pass requires every listed test to pass with an evidence reference and an approved/verified oracle. Regression test: `test_one_pass_does_not_mask_other_failed_or_unrun_case`.
3. **Missing explicit regression label** in release-readiness practice casebook. Improved case study, then rechecked its required risk dimensions.
4. **Language fit:** added instruction to answer in the user's language, not impose English because authoring documentation is in English.

## Executed checks
- Package structural validator: pass.
- Python regression suite: 9 tests passed.
- Decimal BVA sample: `0.01..0.03, step 0.01` yielded `[0.00, 0.01, 0.02, 0.03, 0.04]` exactly.
- Coverage sample: 2/3 requirements have a designed test; 0/3 have evidenced passes; 2 high/critical gaps. Exit code 1 as expected because of risk, not script failure.
- Archive consistency: verified at packaging step; no external repository operations.

## Limits and follow-up
This is **not** confirmation of installation in ChatGPT Skills or proof of the model following its instructions on real prompts. Skills UI creation/publishing is not exposed to the tools in this session. The archive must be uploaded in the ChatGPT Skills UI; activation and behavioral quality can then be verified using the five prompts in `examples/README.md`. No functional/security tests of any external application were run.
