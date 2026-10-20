# Senior QA Engineer — ChatGPT Skill

A universal, technology-agnostic, risk-based professional QA workflow for designing, executing (when authorized) and reporting software tests.

## Install in ChatGPT
1. Navigate to **Plugins → Skills** in ChatGPT, if your account/workspace supports Skills.
2. Select **Create → Upload from your computer** and choose `Senior_QA_Engineer_Skill.zip`.
3. Review the package, confirm installation, and enable/use the skill for a test review.

The zip contains exactly one root folder `senior-qa-engineer/` with `SKILL.md` and its supporting files. Nothing is written to your GitHub/GitLab repositories by package creation or validation.

## Contents
- `SKILL.md`: project discovery, mode routing, safety, risk-based QA workflow and deliverables.
- `references/`: detailed manuals covering reconnaissance, design techniques, surfaces, permissions, automation, evidence and source links.
- `assets/`: test cases, state and role matrices, requirement traceability, bug reports, release assessments, checklists.
- `examples/`: five realistic design-only practice tasks with concrete risk-first test cases.
- `scripts/generate_boundaries.py`: numeric boundary candidate generator.
- `scripts/coverage_audit.py`: JSON requirements-test-ledger audit, distinguishes design coverage from reported passes backed by evidence references.
- `scripts/validate_skill.py`: offline packaging/structure validator.
- `scripts/test_skill_package.py`: offline regression tests for package, helper scripts, and QA case study presence.

## Local verification
Run from the extracted folder:
```
python scripts/validate_skill.py
python scripts/test_skill_package.py
python scripts/generate_boundaries.py --minimum 1 --maximum 10 --step 1
python scripts/coverage_audit.py examples/sample-coverage-ledger.json
```
`coverage_audit.py` deliberately exits with status **1** when high-risk unverified items or failed tests exist; this is not a crash.

## What these checks do not prove
Offline tests do **not** demonstrate that ChatGPT has installed or invoked the skill, and do **not** demonstrate application tests executed. Full behavioral activation should be checked with five prompts in `examples/README.md` after installation.

## Design limits
Security-oriented testing is scoped to authorized low-impact environments; no default production penetration testing. No stack, framework, database, repository, provider or company is hardcoded. External tool installation and product modifications require authorization.
