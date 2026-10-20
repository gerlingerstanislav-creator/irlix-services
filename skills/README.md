# Senior JavaScript / Vue Frontend Engineer — Skill package

Reusable and project-independent Agent Skill for senior Vue 3/JavaScript/TypeScript frontend engineering.

This is an **Agent Skills-compatible directory**, not a plugin, GitHub repository edit or automatically installed ChatGPT Skill. The `SKILL.md` frontmatter name is machine-safe; the human-facing display name can be **Senior JavaScript / Vue Frontend Engineer**.

## Contents

- `SKILL.md`: core method and on-demand reference router.
- `references/`: specialized knowledge, official references, and checklists.
- `assets/`: working templates, task and delivery reports.
- `scripts/audit_frontend.py`: safe read-only project inventory and heuristic risks (no network/no changes).
- `scripts/validate_skill.py`: portable structural validator (Python 3 stdlib only).
- `validation/`: three independently developed scenarios, executable behavior-model checks and outcomes. **Behavior-model checks are not Vue/browser tests.**

## Installing to ChatGPT Skills

For eligible ChatGPT workspaces, open **Plugins → Skills → Create → Upload from your computer**, then upload this archive. Alternatively select **Create with editor** and copy this directory. The ChatGPT Skills page and installation availability depend on plan/workspace; this package is not installed by downloading it. If your account includes `skill-creator`, it may also assist in importing/refining the package. Reference: https://help.openai.com/en/articles/20001066-skills-in-chatgpt

## Validating locally

```bash
python3 scripts/validate_skill.py .
python3 scripts/audit_frontend.py /path/to/vue/project
python3 -m unittest discover -s validation -p 'test_*.py' -v
node --test validation/behavior-models.test.mjs
```

No npm installation is required for these **package-level** checks. To verify against a live Vue project, install the project's existing dependencies and run its own lint/typecheck/Vitest/Playwright commands, after inspecting `package.json` and lockfile.

## Constraints

No project-specific rules; no forced Pinia/TypeScript/new dependency; no hidden external calls. It does not contain credentials or modify files outside its package. It never establishes production or visual verification by itself.
