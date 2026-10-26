"""CI regression: verify production browser smoke after releases without new images."""

from pathlib import Path
import re
import unittest


WORKFLOW = Path(__file__).resolve().parents[3] / ".github/workflows/ci.yml"


class BrowserSmokeContractTest(unittest.TestCase):
    def test_browser_smoke_runs_after_successful_or_skipped_deployment(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        step = re.search(
            r"(?m)^      - name: Verify deployed Employees navigation in Chromium\n"
            r"(?P<body>(?:^        .*\n)*)", workflow
        )
        self.assertIsNotNone(step, "Employees production smoke step must exist")
        condition = next(
            line.strip() for line in step.group("body").splitlines()
            if line.strip().startswith("if:")
        )
        self.assertIn("needs.deploy.result == 'success'", condition)
        self.assertIn("needs.deploy.result == 'skipped'", condition)
        self.assertIn("||", condition)


if __name__ == "__main__":
    unittest.main()
