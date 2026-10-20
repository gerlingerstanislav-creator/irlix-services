# Synthetic scenario tests and limitations

Fixtures are intentionally flawed standalone screens authored only to test detection and reporting. They are **not** customer apps or an independently conducted end-user study.

- `dense-timesheet.html`: compact B2B grid, clipped horizontal area, tiny targets, color-only status.
- `mobile-preorder.html`: allergy conflict, selected table state without semantics, ergonomically small pay action.
- `transfer-form.html`: blank amount, missing label, false success on click.
- `warehouse-tablet.html`: touch target, clickable non-semantic div, status color dot.

Run `python scripts/inspect_fixtures.py` in an environment with Playwright and Chromium at `/usr/bin/chromium` (edit executable path if different). This performs DOM and screenshot smoke checks, not a comprehensive axe/WCAG audit. The script has no network access and doesn't interact with real users. Run `python scripts/validate_package.py` for structural checks and `python scripts/audit_gate.py tests/results/sample-audit.json` for handoff quality.

Examples in `assets/examples/` document the actual fixture observations and separately mark untested user-flow risks. For real projects always reconstruct actual tokens/components; the example CSS must not be copied to production.
