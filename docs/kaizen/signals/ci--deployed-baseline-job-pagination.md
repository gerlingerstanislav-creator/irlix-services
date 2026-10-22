# Production planner repeatedly selects an obsolete deployed baseline

- **Scope / tags:** CI, deployment planner, cumulative releases
- **Fingerprint:** successful later production runs ignored when resolving previous deployed SHA, expanding unrelated services in subsequent release plans
- **First noted:** 2026-10-22
- **Status:** confirmed planner behavior and excess plan; exact API response/pagination remains to be verified with a dedicated regression

## Evidence

- [Successful production CI #39826828711](https://github.com/gerlingerstanislav-creator/irlix-services/actions/runs/39826828711) deployed and smoke-checked commit `5ba3112e` (over 30 workflow jobs).
- [Production CI #39834095335](https://github.com/gerlingerstanislav-creator/irlix-services/actions/runs/39834095335) at commit `985652c7` selected earlier `750bd967` as `base` rather than `5ba3112e`. Detect Changes logs list 25 images and many previously deployed services, although the main code changes were CV local LLM lifecycle/Service Map.
- In `scripts/ci/service_plan.py`, `previous_success()` reads `jobs_url` without pagination and searches the returned jobs for `Pull and deploy affected components`. The GitHub REST jobs endpoint defaults to 30 jobs per page; recent CI runs have 31+ jobs. The deploy job may lie outside the first page.

## Impact and analysis

- Observed: CI calculated an unnecessary broad, cumulative release. Content-hash reuse avoided many new builds, and source-hash image transfer skipped already-present images, but it still created broad runtime replacement risk and wasted runner/stand time.
- **Likely mechanism, not yet a proven regression test:** job pagination causes a successful deployed run to be treated as not deployed. Do not assume the next earlier run is the correct baseline when a successful deployment job is absent from a truncated response.
- **Proposed follow-up, NOT implemented in this signal:** paginate all pages of the jobs endpoint when deciding the last successful deployment; test a run with >30 jobs and confirm exactly one previous deployed SHA. Consider a dedicated immutable deployed-state marker after confirmed smoke, without allowing failed or skipped deploys to advance the baseline.
- No production network, data, auth or workload config changes are proposed here.
