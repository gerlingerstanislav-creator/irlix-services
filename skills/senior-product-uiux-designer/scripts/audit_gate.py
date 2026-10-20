#!/usr/bin/env python3
"""Offline structural quality gate for UX audit JSON. Not a WCAG validator.

Usage: python scripts/audit_gate.py path/to/audit.json
Exit 0 if required handoff information exists; exit 1 otherwise.
"""
import json
import sys
from pathlib import Path

REQUIRED_CONTEXT = ("project", "screen", "roles", "platforms", "evidence_sources", "constraints", "unknowns")
REQUIRED_FINDING = ("id", "title", "priority", "confidence", "evidence", "actual", "expected", "impact", "recommendation", "verification", "design_system_impact")
PRIORITY = {"P0", "P1", "P2", "P3"}
CONFIDENCE = {"high", "medium", "low"}


def check(data):
    errors = []
    if not isinstance(data, dict):
        return ["root must be an object"]
    context = data.get("context")
    if not isinstance(context, dict):
        return ["context must be an object"]
    for k in REQUIRED_CONTEXT:
        if k not in context or context[k] is None or context[k] == "":
            errors.append(f"context.{k} missing")
    if not isinstance(context.get("evidence_sources"), list):
        errors.append("context.evidence_sources must be an array")
    findings = data.get("findings")
    if not isinstance(findings, list):
        return errors + ["findings must be an array"]
    ids = set()
    for i, item in enumerate(findings):
        p = f"findings[{i}]"
        if not isinstance(item, dict):
            errors.append(p + " must be object")
            continue
        for key in REQUIRED_FINDING:
            if not isinstance(item.get(key), str) or not item[key].strip():
                errors.append(f"{p}.{key} must be a nonempty string")
        if item.get("id") in ids:
            errors.append(f"{p}.id duplicated")
        ids.add(item.get("id"))
        if item.get("priority") not in PRIORITY:
            errors.append(f"{p}.priority must be P0-P3")
        if item.get("confidence") not in CONFIDENCE:
            errors.append(f"{p}.confidence must be high/medium/low")
        if item.get("confidence") == "high" and not context.get("evidence_sources"):
            errors.append(f"{p} high confidence without sources")
        if item.get("priority") in ("P0", "P1") and len(str(item.get("verification", "")).strip()) < 20:
            errors.append(f"{p} high-priority issue needs specific verification")
    for k in ("state_coverage", "responsive_coverage", "accessibility_coverage", "validation_limits"):
        if not isinstance(data.get(k), str) or not data[k].strip():
            errors.append(f"{k} missing")
    return errors


def main():
    if len(sys.argv) != 2:
        print(__doc__.strip())
        return 2
    try:
        audit = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
    except (OSError, ValueError) as e:
        print("INVALID JSON:", e)
        return 2
    failures = check(audit)
    if failures:
        print("FAIL — structural/traceability issues:")
        for x in failures:
            print(" -", x)
        return 1
    print(f"PASS — {len(audit['findings'])} findings checked for evidence and handoff fields. Not an accessibility certification.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
