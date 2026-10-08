# GitHub access and release troubleshooting for all chat agents

## Avoid false access-denied claims

Reading the repository, accessing GitHub Actions results, and updating branches are **different capabilities**. The absence of a conveniently named operation (for example, "run workflow") does **not** imply that GitHub is disconnected or that the agent cannot complete a release.

**Before claiming "no permissions", find the available GitHub actions by their descriptions, call the most relevant one, and record a concrete error.** Prefer the connected GitHub app; do not depend on knowing only a subset of the connector's methods.

Verified connector action types used for `gerlingerstanislav-creator/irlix-services`:

| Task | Permitted operation |
|---|---|
| Confirm connector/repository permission | GitHub `get_repo`, inspect `permissions.push`; this is a preliminary signal, not a replacement for testing a protected ref update |
| Read current `main` and `development` | GitHub `fetch` GET `https://api.github.com/repos/gerlingerstanislav-creator/irlix-services/branches/<branch>` |
| Find CI for exact SHA | GitHub `fetch` GET `https://api.github.com/repos/gerlingerstanislav-creator/irlix-services/actions/runs?branch=development&per_page=30` and select runs with exact `head_sha`, `event=push`, workflow names `CI` and `Development release candidate` |
| Confirm job conclusions | `fetch_workflow_run_jobs` with the selected run id, require `CI verified=success` and `Development release candidate=success` |
| Read actual failure | `fetch_workflow_job_logs` for failed job id, if available |
| Confirm no branch divergence | `compare_commits` with `base=main`, `head=development`; expect `behind_by=0` |
| Promote verified commit | `update_ref` with `branch_name=main`, `sha=<latest verified development SHA>`, `expected_sha=<fresh main SHA>`, `force=false`; the server must enforce required checks |
| Verify production | `fetch` GET `actions/runs?branch=main&per_page=30`, select new run for released SHA; inspect `fetch_workflow_run_jobs` and deployment/smoke results |

For a connector exposing these actions under `functions.exec`, discover them from the available tool catalogue and invoke the matching connected GitHub actions (e.g. `tools.mcp__GitHub__fetch`, `tools.mcp__GitHub__fetch_workflow_run_jobs`, `tools.mcp__GitHub__compare_commits`, `tools.mcp__GitHub__update_ref`). Other environments may expose the same capabilities differently: discover equivalent **permitted** operations; do not invent or require a particular tool name.

## One-command cumulative release

1. User says "кати в прод": **already approved** for the whole current `development`. No additional manual GitHub step or confirmation is necessary.
2. Re-read both branch tips, review cumulative changes and confirm `main` is an ancestor of `development`.
3. Locate both mandatory Actions check runs for **the exact current development SHA**. If an Actions run is missing right after push or is queued/in_progress, poll and wait. Cancelled runs from *older commits* are irrelevant. A failed **current** run requires investigating the logs, correcting the problem in `development`, and waiting for checks of the new head; do not bypass protection.
4. Re-read both refs immediately before updating main. If development changed during the wait, reassess the **full** accumulated release and verify checks of the newer SHA, then resume on the same user command. Use only a protected fast-forward with `expected_sha`, no force-push, squash, cherry-pick or direct code commits to main.
5. Updating main from an external GitHub connection normally triggers **existing** main push CI and production deployment. Wait for that run's `CI verified`, `Pull and deploy affected components`, and `Bootstrap authentication and verify stand` jobs; confirm whether deploy ran or was legitimately skipped. Don't claim prod updated before deploy/smoke succeeds. `GITHUB_TOKEN` pushes from within GitHub Actions are a special case and require the existing release workflow to explicitly dispatch CI.
6. Reply with the **end state** (released SHA; verified deployment, or the concrete blocker). Avoid ending with "CI not confirmed", "I don't have a workflow-dispatch tool", "please run GitHub Actions yourself".

## Distinguish actual blockers

- No `workflow_dispatch` connector action: **not** a blocker if safe ref update + existing main CI works.
- GitHub Actions in progress: **not** a blocker; keep polling.
- Old commit CI cancelled by a newer `development` push: **not** a blocker; check the newest SHA.
- `get_repo` or file reads succeed but branch update fails: inspect the exact error — branch write permissions and required status checks are independent of read permissions.
- A genuinely missing write capability or verified GitHub 403 for all available authorized alternatives: **real** blocker; report the specific operation and error. Never bypass a ruleset, manufacture check success or claim deployment happened.
- If another chat actively modifies the same branch, preserve concurrent changes; use a fresh read + expected-old-head update and never overwrite newer work.

This is an **operations playbook**, not a new GitHub Actions workflow or release pipeline. The release architecture remains `development` CI/build → verified fast-forward `main` → automatic production CI/deploy.
