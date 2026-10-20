#!/usr/bin/env python3
"""Offline regression of skill package, auxiliary scripts and 5 QA case studies.

Does not execute a model, connect to a project or validate real application behavior.
"""
import sys
import unittest
from decimal import Decimal
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent
sys.path.insert(0, str(HERE))
from coverage_audit import audit
from generate_boundaries import values
from validate_skill import main as validate


class TestSkillPackage(unittest.TestCase):
    def test_structural_integrity(self):
        self.assertEqual(validate(), 0)

    def test_integer_boundaries(self):
        result = values(Decimal(1), Decimal(10), Decimal(1))
        self.assertEqual(result, ["0", "1", "2", "9", "10", "11"])

    def test_precise_money_boundaries(self):
        result = values(Decimal("0.01"), Decimal("0.03"), Decimal("0.01"))
        self.assertEqual(result, ["0.00", "0.01", "0.02", "0.03", "0.04"])

    def test_invalid_boundary_input(self):
        with self.assertRaises(ValueError):
            values(Decimal(10), Decimal(1), Decimal(1))
        with self.assertRaises(ValueError):
            values(Decimal(1), Decimal(10), Decimal(0))

    def test_unverified_critical_rule_is_not_green(self):
        result = audit({"scope": "test", "requirements": [
            {"id": "REQ-1", "risk": "critical", "oracle": "missing", "tests": []},
            {"id": "REQ-2", "risk": "high", "oracle": "approved", "tests": [
                {"id": "TC-2", "status": "not_run", "level": "api"}]}]})
        self.assertEqual(result["critical_or_high_risk_gaps"], ["REQ-1", "REQ-2"])
        self.assertEqual(result["requirements_with_all_tests_evidenced_pass"], 0)

    def test_a_pass_requires_specific_evidence(self):
        for evidence in (None, "", "   "):
            with self.subTest(evidence=evidence):
                with self.assertRaises(ValueError):
                    audit({"requirements": [{"id": "REQ-1", "risk": "high",
                        "oracle": "approved", "tests": [{"id": "TC-1", "status": "passed",
                        "evidence": evidence}]}]})

    def test_one_pass_does_not_mask_other_failed_or_unrun_case(self):
        for remaining in ("failed", "blocked", "not_run"):
            with self.subTest(remaining=remaining):
                extra = {"id": "TC-2", "status": remaining}
                if remaining == "failed":
                    extra["evidence"] = "failure-log"
                result = audit({"requirements": [{"id": "REQ-1", "risk": "high",
                    "oracle": "approved", "tests": [
                    {"id": "TC-1", "status": "passed", "evidence": "report-1"}, extra]}]})
                self.assertEqual(result["requirements_with_all_tests_evidenced_pass"], 0)
                self.assertEqual(result["critical_or_high_risk_gaps"], ["REQ-1"])

    def test_verified_pass_separate_from_design_coverage(self):
        result = audit({"requirements": [{"id": "REQ-1", "risk": "high",
            "oracle": "approved", "tests": [{"id": "TC-1", "status": "passed",
            "evidence": "test-log-123", "level": "api"}]},
            {"id": "REQ-2", "risk": "medium", "oracle": "approved", "tests": []}]})
        self.assertEqual(result["coverage_percent_designed"], 50.0)
        self.assertEqual(result["coverage_percent_evidenced_pass"], 50.0)
        self.assertEqual(result["critical_or_high_risk_gaps"], [])

    def test_scenarios_are_risk_driven_and_do_not_invent_runs(self):
        expectations = {
            "01-enterprise-workflow.md": ["delegate", "revoked", "approved", "concurrent", "tenant", "TC-12"],
            "02-payments-api.md": ["idempotency", "webhook", "timeout", "reconciliation", "cross-tenant", "TC-12"],
            "03-spreadsheet-import.md": ["three", "sheet", "atomic", "formula", "Unauthorized", "TC-12"],
            "04-mobile-offline.md": ["offline", "conflict", "logout", "reconnect", "revoke", "TC-10"],
            "05-release-review.md": ["evidence", "not_run", "flag", "tenant", "regression", "TC-07"]
        }
        for filename, terms in expectations.items():
            with self.subTest(filename=filename):
                path = ROOT / "examples" / filename
                content = path.read_text(encoding="utf-8").lower()
                self.assertIn("not_run", content)
                self.assertGreaterEqual(content.count("tc-"), 7)
                for term in terms:
                    self.assertIn(term.lower(), content)
                self.assertTrue("no defects confirmed" in content or "no bug confirmed" in content
                                or "no defect confirmed" in content,
                                f"example {filename} must disclaim unobserved bugs")


if __name__ == "__main__":
    unittest.main(verbosity=2)
