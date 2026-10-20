# Install and use in ChatGPT Skills

1. In ChatGPT, navigate to **Plugins → Skills** (or https://chatgpt.com/plugins if supported in your workspace).
2. Choose **Create → Upload from your computer**.
3. Upload the skill `.zip` containing this folder as its single top-level directory.
4. Review the security scan, then install/enable it in your workspace if the option appears.
5. Try a task such as “@senior-php-laravel-backend-engineer review this Laravel endpoint for IDOR, data races and backward compatibility”. The exact invocation depends on the current UI.

When working in a checkout, `python scripts/project_probe.py /path/to/checkout` is an optional read-only inventory. `python scripts/validate_skill.py` validates the skill bundle; `python scripts/evaluate_bundle.py` runs local PHP fixtures if PHP is present.

This bundle is environment-independent: it contains no company/product/repository-specific rules. If the Skills tab or upload action is unavailable, check your account/workspace eligibility and permissions rather than assuming upload occurred.
