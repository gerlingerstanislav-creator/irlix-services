#!/usr/bin/env python3
"""Audit requirement/test traceability from a JSON ledger.

No external dependencies, no repository or network access. This does NOT execute
application tests or verify the truth of linked evidence. See assets/qa-templates.md.
"""
import argparse
import json
import sys
from collections import Counter
from pathlib import Path

STATUSES = {"passed", "failed", "blocked", "not_run", "inconclusive", "skipped"}
RISKS = {"critical", "high", "medium", "low"}
ORACLES = {"approved", "verified", "assumed", "missing", "conflict"}


def audit(data):
    requirements = data.get("requirements")
    if not isinstance(requirements, list):
        raise ValueError("requirements must be an array")
    seen = set()
    errors, uncovered, high_risk_gaps, executions = [], [], [], Counter()
    designed = evidenced = passed = 0
    for i, item in enumerate(requirements):
        if not isinstance(item, dict):
            errors.append(f"requirement[{i}] must be an object")
            continue
        rid = item.get("id")
        if not isinstance(rid, str) or not rid.strip() or rid in seen:
            errors.append(f"requirement[{i}] missing or duplicate ID: {rid!r}")
            continue
        seen.add(rid)
        risk = item.get("risk")
        oracle = item.get("oracle")
        tests = item.get("tests", [])
        if risk not in RISKS:
            errors.append(f"{rid}: risk must be one of {sorted(RISKS)}")
        if oracle not in ORACLES:
            errors.append(f"{rid}: oracle must be one of {sorted(ORACLES)}")
        if not isinstance(tests, list):
            errors.append(f"{rid}: tests must be an array")
            continue
        if tests:
            designed += 1
        else:
            uncovered.append(rid)
        seen_tests = set()
        had_evidence = False
        all_tests_passed_with_evidence = bool(tests)
        for j, test in enumerate(tests):
            if not isinstance(test, dict):
                errors.append(f"{rid}.tests[{j}]: must be an object")
                continue
            tid, status = test.get("id"), test.get("status")
            if not isinstance(tid, str) or not tid.strip() or tid in seen_tests:
                errors.append(f"{rid}: missing/duplicate test ID {tid!r}")
            seen_tests.add(tid)
            if status not in STATUSES:
                errors.append(f"{rid}.{tid}: invalid status {status!r}")
                continue
            executions[status] += 1
            # A passing assertion with no evidence record is not verified.
            evidence = test.get("evidence")
            if status in {"passed", "failed"} and (not isinstance(evidence, str) or not evidence.strip()):
                errors.append(f"{rid}.{tid}: {status} requires an evidence reference")
            if status in {"passed", "failed"} and isinstance(evidence, str) and evidence.strip():
                had_evidence = True
            if status != "passed" or not isinstance(evidence, str) or not evidence.strip():
                all_tests_passed_with_evidence = False
        if had_evidence:
            evidenced += 1
        all_pass = all_tests_passed_with_evidence and oracle in {"approved", "verified"}
        if all_pass:
            passed += 1
        if risk in {"critical", "high"} and not all_pass:
            high_risk_gaps.append(rid)
    if errors:
        raise ValueError("Invalid coverage ledger:\n" + "\n".join(errors))
    n = len(requirements)
    return {
        "scope": data.get("scope", "unspecified"), "total_requirements": n,
        "requirements_with_designed_tests": designed,
        "requirements_with_recorded_execution_evidence": evidenced,
        "requirements_with_all_tests_evidenced_pass": passed,
        "coverage_percent_designed": round(100 * designed / n, 1) if n else None,
        "coverage_percent_evidenced_pass": round(100 * passed / n, 1) if n else None,
        "test_status_counts": dict(executions),
        "requirements_without_tests": uncovered,
        "critical_or_high_risk_gaps": high_risk_gaps,
        "note": "Does not verify evidence authenticity, product behavior or readiness for release."
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("ledger", help="path to coverage JSON")
    args = parser.parse_args()
    try:
        data = json.loads(Path(args.ledger).read_text(encoding="utf-8"))
        result = audit(data)
    except (OSError, ValueError, json.JSONDecodeError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(2)
    print(json.dumps(result, ensure_ascii=False, indent=2))
    # Exit 1 on unverified high-risk gap or confirmed test failure.
    sys.exit(1 if result["critical_or_high_risk_gaps"] or result["test_status_counts"].get("failed", 0) else 0)


if __name__ == "__main__":
    main()
