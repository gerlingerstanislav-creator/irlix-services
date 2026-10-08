# CI/CD: service registry and release policy

## Branches and cumulative production releases

| Context | Automatic action |
|---|---|
| Temporary / feature branch | Local checks; no automatic PR image builds |
| Push to `development` | Registry/Compose validation, tests, selective image build and publish to GHCR; **no remote deployment yet** |
| Push to `main` | Gate: pushed SHA must be reachable from `development`; after successful CI, selective deploy/migrations/smoke to production |
| Manual `CI` | Selective/full verification without deploy by default. Only the internal release workflow may request `deploy_release=true` on main. |
| Manual `Cumulative production release` | User instruction is sufficient approval: pin development SHA, **wait for its queued/running CI and release candidate checks** (up to 90 min), fast-forward `main` after success and start production CI |

Every regular change goes into `development`, and stays there until the user explicitly requests a **cumulative** release. `main` must only point at a previously verified commit that is in `development` history. Never selectively cherry-pick or squash tasks into main. If work cannot ship with everything accumulated in development, keep it in a feature branch or feature flag instead.

**Обязательная проверка доступов:** прежде чем заявлять, что нет доступа к GitHub Actions или невозможно выполнить релиз, использовать описанную в [GITHUB_ACCESS.md](GITHUB_ACCESS.md) проверку доступных операций GitHub, результатов runs/jobs и возможностей безопасного fast-forward. Отсутствие отдельной команды запуска workflow не равно отсутствию доступа к CI или невозможности выкладки. Настоящий отказ прав подтверждается ответом конкретной операции, а не предположением.

**Assistant release contract (all chats):** The user command «кати в прод» is sufficient authorization for the *whole* current `development`. Inspect current branches and accumulated changes, then wait until both exact-SHA `CI verified` and `Development release candidate` checks succeed. Pending/queued/running CI means keep waiting, not refuse the task. Investigate and fix recoverable CI errors in `development` and continue under the same authorization. If `development` changes meanwhile, re-evaluate the new full diff and recheck its CI. Fast-forward `main` with an expected-head check; the existing main push automatically starts production CI and deployment. Wait for deploy and smoke verification before reporting the final result. Do not introduce a GitHub Issues trigger, separate release app, or additional deployment workflow. If an operation is genuinely unavailable or blocked, report the specific unresolved blocker without claiming deployment succeeded.

### Release procedure

1. Before release, compare `origin/main..origin/development`: show **all** accumulated changes and risks; capture the precise `development` SHA. If known-incomplete work exists, do not release it accidentally.
2. The user's command to release production is **already final authorization**. If either `CI verified` or `Development release candidate` for the exact SHA is absent/queued/running, **wait and poll** rather than fail immediately, ask for confirmation, or tell the user to do it manually. The `release.yml` workflow waits up to 90 minutes. Only two successfully completed checks from development push permit promotion. If a check fails or is cancelled, investigate and fix the cause in development and continue on the **same user request**; never bypass a failing check.
3. Require `main` to be an ancestor of `development` and recheck HEAD before promotion. If development advances while awaiting CI, inspect the entire new diff, pin the new SHA and await its checks **without requesting release approval again**. Promote using atomic, fast-forward, lease-aware ref update (or the `Cumulative production release` manual workflow); never cherry-pick/rebase/squash/merge into main. Its pinned-SHA workflow stops if HEAD moves to avoid silently releasing unreviewed changes; the operator must continue the same approved request with a freshly verified SHA.
4. The production CI verifies release provenance before deployment. If the push came from an ordinary actor, a main push starts CI normally. **GitHub Actions GITHUB_TOKEN pushes do not automatically start other workflows**; `release.yml` explicitly dispatches `CI` on main with `deploy_release=true` after promotion.
5. Wait for production CI, deployed-state verification and smoke. No successful deploy => do not claim release complete. Failed deployment requires investigation and repair without returning a mere status to the user; do not reset main or wipe persistent data. If only documentation/CI changed and deployment correctly skipped, report this fact and the successful CI run.

The optional `deploy_release` manual CI input is for the release workflow, not general-purpose ad-hoc production deployment. It is limited to `main` and passes the same provenance gate. Administratively restrict who can dispatch workflows.

### Main branch protection without a separate GitHub App (manual admin setup required)

The existing ruleset protects `main` and `development` against deletion and non-fast-forward updates. This does **not** prevent a new direct commit on main. Add **another** active branch ruleset targeting **only `main`** with **Require status checks to pass** and these exact names, using the **GitHub Actions** source where GitHub offers it:

- `CI verified` — all tests and selective builds passed on that SHA, on a push-triggered development CI run.
- `Development release candidate` — a dedicated check created **only** on push to `development` by `.github/workflows/development-release-candidate.yml`. It ensures main is an ancestor and the commit is the current development head when checked.

Use **no bypass entries**. Keep **Do not require branches to be up to date** (loose). **Do not enable `Restrict updates` or `Require a pull request`** for this model: those require a separate authorized push actor or PR integration and would prevent the existing `GITHUB_TOKEN` fast-forward from publishing. Do not select the general `Main commit originates in development` check as a substitute: it runs on main **after** the attempted push and is therefore only a deployment gate.

`CI` must run for **every** development commit, even docs-only changes, so the `paths-ignore` filter was removed. Only a `development` push emits the candidate check, and a manual CI run on an arbitrary branch cannot supply it. GitHub accepts only successful required checks **for the exact SHA**; for the first ruleset configuration, wait for both new checks to appear and pass on development. Keep the expected check source `GitHub Actions`; avoid an ambiguous `Any source` where a trusted source is available.

**Guarantees and limitations:** a brand-new direct commit on main cannot have the development-only candidate check and should be rejected by GitHub **before** changing main; an authorized cumulative release can still fast-forward main to a fully checked development SHA without extra apps/keys. However, GitHub's rule is about **the SHA, not the person or workflow**: someone with ordinary repository write access can manually advance main to an already-checked development SHA. The rule therefore enforces *development-first and CI-first*, but explicit user release approval remains a process requirement and cannot be technically restricted to the release workflow without a separate authenticated actor or a PR-based policy.

**Configuration:** Repository → Settings → Rules → Rulesets → New branch ruleset → include `main` only → active → required checks above → no bypass → save. Preserve the existing deletion/non-fast-forward ruleset. After activation confirm the effective rules and validate publication via the next user-approved cumulative release; do not intentionally push an unverified commit to main as a destructive test.

### Future independent TEST environment

Keep `development` CI-only until a completely separate VM/namespace, URL, SSH deploy identity, data storage and Keycloak realm/instance are supplied. Add a dedicated `development` deploy job with separate `TEST_DEPLOY_*` secrets and GitHub environment `testing`, publishing exactly the CI-verified SHA. Never reuse production deploy secrets, PostgreSQL volumes, Keycloak or URLs for the test stand.

### Existing selective registry and deployed-state baseline

A main release can still require a new build if its final inputs differ from verified development images or a checked image is absent. Builds and deployments use the shared registry, and production deploys finish serially; superseded development CI may be cancelled. For development, comparison baseline is the last successful development CI. For production it is the last ancestor commit with a successful **deploy job**, rather than the last totally green workflow, so a post-deploy smoke failure does not replay unrelated already-deployed components.

## One registry for all services

`infra/ci/services.json` is the source for build selection, image names/tag variables, worker aliases, migrations and smoke endpoints. `scripts/ci/service_plan.py` creates the matrix and `.ci/deploy-plan.json`. The deploy script reads that plan; there are no frontend/backend service flag lists to extend in the workflow or deploy script.

Compose remains the source for build context/Dockerfile/arguments and runtime configuration. The planner renders the current and previous complete stacks using Docker Compose, with the same project directory and safe `.env.example`. It compares individual service definitions. A change to a Compose overlay or environment example does not mean every image needs a rebuild.

- Application source or its explicitly registered shared dependencies → its image and runtime aliases.
- `packages/ui`/browser auth → frontend consumers, not backend images or Keycloak provisioning.
- Runtime configuration only → affected containers, without rebuilding unchanged images.
- Host nginx configuration → routing update and verification, without image rebuilds.
- Keycloak configuration/theme/bootstrap → Keycloak runtime and auth provisioning.
- PostgreSQL schema bootstrap → PostgreSQL/schema initialization.
- Capacity diagnostic script/workflow → diagnostic workflow only.
- Documentation → no image builds.
- Full build → explicit manual request or initial bootstrap with no successful release baseline.

The planner records the reason for each selected image in the job summary. A new Compose build or app/service Dockerfile without a registry entry fails CI with instructions instead of silently forcing a platform rebuild or skipping the service.

## Verified images and backend performance

Images use a `src-<hash>` tag derived from Git blob IDs of registered source/shared paths, build definition, component registration and shared image-check code. The hash is independent of merge commit SHA. The reusable `.github/actions/build-service` action first looks for that verified tag in GHCR. Images are published only after Dockerfile tests and required image checks pass; failed checks cannot create a reusable tag. The same inputs in development and main reuse the same image.

Buildx imports/exports a per-component GitHub Actions cache, version 2, mode max. Stable Laravel bootstrap, dependencies and PHP extensions are reused. PHP extension compilation uses available runner CPUs; Clients' SQLite extension installation precedes application COPY so edits do not recompile it. Offline CV DOCX/PDF rendering and Migration clean SQLite-volume bootstrap checks are part of this common image action, not separate branch/PR builds. Existing backend bootstrap creates Laravel at build time; replacing it with checked-in, locked Composer dependencies is a separate dependency migration, not an implicit CI optimization.

CV backend/frontend use GHCR like every other application. The stand never builds application images. CV model readiness and real-inference smoke run when its backend/model/runtime changes; frontend-only or unrelated releases do not invoke inference. Repeated model container crashes terminate readiness early. Model weights stay in their volume.

Keep image tags immutable within CI. To update a floating base/dependency deliberately, change/pin its build input and verify the resulting image; do not silently overwrite an existing verified source tag. Periodic dependency refresh and image/cache cleanup are separate maintenance operations. Deploy keeps previous image tag references in `.ci/previous-image-tags.env` and does not prune them on every update.

## Adding a service

1. Add its Dockerfile, local/offline tests and Compose definition. For new apps commit dependency lock files and install dependencies before copying source. Backend dependencies must be explicit; no implicit model inference in unrelated checks.
2. Add one component per independently built image to `infra/ci/services.json`: `id`, `kind`, `service`, `image`, `tag_env`, all build input `paths`, and a public health endpoint or frontend page URL. Include shared packages only when actually consumed.
3. Add worker containers to `aliases` of their owning image; they are built once. Set `migrate` for PostgreSQL Laravel services, `image_check` for common offline image checks, and `verify` for extra post-deploy integration checks. New image-check implementations belong in `scripts/ci/image_check.py`; publication must remain after verification.
4. If a new Compose overlay is needed, register it in `compose_files`; no workflow or deployment command list needs editing. Add the required isolated DB/schema/bootstrap credentials and runtime routes using existing platform rules.
5. Run `python3 scripts/ci/service_plan.py images --write` to regenerate `docker-compose.images.yml`. This generated file must not be hand-maintained.
6. Run `python3 -m unittest discover -s scripts/ci/tests -v` and `python3 scripts/ci/service_plan.py validate` (requires Docker Compose v2). Inspect a selective plan with `python3 scripts/ci/service_plan.py plan --base <successful-commit>`.
7. Push the logical task to development and wait for CI verification; leave the tested commit accumulated until a separate user-approved cumulative production release. The shared pipeline automatically builds, checks, publishes, deploys aliases, migrates and checks the registered endpoint.

## Audit of excess builds on 2026-10-01

- Run `36928199988` (`76868d1`) rebuilt all 18 application images. Since its successful baseline `813695a0`, build inputs changed in Portal and Migration; additional image/Compose/.env changes belonged to Migration. Those did not require rebuilding all other services. Broad Compose/.env handling selected full mode.
- Run `36918562113` (`b36b39da`) also rebuilt the platform after CV/Migration additions and their overlays/configuration. Updating affected CV/Migration/Portal components and host routing was necessary; rebuilding every existing business backend was not.
- Run `36902638801` changed a capacity script/workflow and documentation but rebuilt/deployed the platform for 11m16s. The new planner treats it as diagnostic-only.
- A frontend-only Clients correction (`36893913638`) also built/restarted its backend. Independent component selection removes that coupling.
- In the 100 most recent runs sampled during this task, there were 27 CV pull-request checks, 16 Migration pull-request checks and 6 Migration push checks. Examples include the same Migration development SHA `28d68cd` checked on push and release PR, and CV builds on both task PR and development→main PR. The separate build workflows are replaced with one integration pipeline. Notification/diagnostic runs are not image builds; raw run counts alone do not measure wasted build time.

### Follow-up: deployed-state baseline

A later Equipment release exposed a second source of redundant work: an earlier `main` deploy had completed successfully, but the workflow finished red because a post-deploy browser verification failed. The next release compared against an older fully-green workflow and therefore reselected already deployed frontend/backend components. The planner now uses the last **successful deploy job** as the `main` runtime baseline, while still requiring the normal verification job to pass for the current release. This keeps recovery safe without replaying unrelated deployment work.

This optimization initially rebuilds changed Dockerfiles and introduces verified source tags/cache. Measure subsequent warm runs and main image reuse separately from this first cache population; do not promise timings based on a cold rollout.


CI liveness: every CI job has an explicit timeout (changes 10 min, image build 35 min, result gate 3 min, deployment 20 min, post-deploy 75 min including an optional checkpoint). SSH setup is capped at 2 min, upload at 5, deployment step at 15, Keycloak at 8, stand verification at 7, browser smoke at 5, optional checkpoint at 60. SSH uses BatchMode, 15-second connect/keepalive intervals and three missed keepalives; host-key verification remains enabled. Failure gates use `!cancelled()` instead of `always()` so normal cancellation can stop running verification. Tests and success gates remain mandatory; a timeout fails the release. GitHub queue/service availability is outside these running-job limits.

Baseline lookup uses the repository Actions runs collection filtered by the CI workflow path. A successful main CI history with no resolvable successful deploy job fails planning instead of falling back to an implicit full deployment. Initial bootstrap still requires no successful main ancestor.

## Resource Monitor VM RAM gate

A release including `resource-monitor` installs a 10-second host-side RAM sampler through `scripts/install-resource-host-memory.sh` after the container's data volume is created. Selected-component smoke executes a safe in-container check that the resource snapshot carries fresh `memory_source=host` and numeric used RAM. CI on `development` builds/tests without using production SSH; this gate runs on production only after an explicitly authorized cumulative fast-forward release. Host script parsing is covered by `scripts/ci/tests/test_resource_host_memory.py`.


При изменениях migration-ops `CI verified` также зависит от `Migration rollback Docker integration`: изолированный тест настоящих Docker/PostgreSQL без production credentials. Он проверяет runtime-снимок окружения, успешный/повторный rollback, безопасный preflight и диагностику post-COMMIT отказа. Для остальных изменений job пропускается; существующие обязательные release checks и процесс накопительного релиза не меняются.


Optional rollback integration check may be skipped for unrelated releases. Production deploy uses an explicit `!cancelled()` status condition and requires successful `changes` and `verified` jobs, so GitHub's implicit success() over skipped ancestors cannot silently skip deployment. Main/event and deploy-plan gates remain mandatory; failed or cancelled verification never permits deployment. The production baseline continues to use the last successful deploy, rather than a green build-only main run.
