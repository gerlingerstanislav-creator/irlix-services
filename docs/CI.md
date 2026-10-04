# CI/CD: service registry and release policy

## Branches and automatic runs

| Context | Automatic action |
|---|---|
| Local / temporary task branch | Local checks. No automatic PR image builds. |
| Push to `development` | Validate the entire Compose stack/registry; test the planner; build and verify only changed components; publish verified images. No stand access. |
| Push to `main` | Select changes since the last successfully completed deploy to the stand, reuse verified images from development, build only missing images, deploy affected containers, migrate and verify. |
| Manual `CI` run | Selective build/check on the selected branch; `full` explicitly selects all components. Manual runs never deploy. |

Integrate a completed logical task into the latest development once, wait for `CI verified`, then release development into main. Avoid pushing intermediate edits one at a time, redundant CI dispatches and service-specific PR workflows. Temporary branches remain useful for isolating parallel work, but automatic checks happen at the integration boundary. Fixes must follow development → verification → main too.

A main release can legitimately need a new build if its final inputs differ from the development image or the verified image is missing. It must not blindly reuse an image by branch/commit name. Main builds/deploys finish serially; obsolete development checks may be cancelled.

For `development`, the comparison baseline is the last successful CI verification on that branch. For `main`, the comparison baseline is the latest ancestor commit whose `Pull and deploy affected components` job completed successfully, because that commit describes the state actually applied to the server. If deployment itself fails, that commit does **not** become a baseline and its changes remain in the next deployment range. If deployment succeeds but a later stand/browser verification fails, the deployed commit **does** become the runtime baseline: the next release must verify the stand again, but it must not pull/restart unrelated services whose code was already deployed successfully. This separation prevents a post-deploy smoke failure from turning the next small release into a broad platform redeploy.

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
7. Push the logical task to development, wait for CI verification, then release through main. The shared pipeline automatically builds, checks, publishes, deploys aliases, migrates and checks the registered endpoint.

## Audit of excess builds on 2026-10-01

- Run `36928199988` (`76868d1`) rebuilt all 18 application images. Since its successful baseline `813695a0`, build inputs changed in Portal and Migration; additional image/Compose/.env changes belonged to Migration. Those did not require rebuilding all other services. Broad Compose/.env handling selected full mode.
- Run `36918562113` (`b36b39da`) also rebuilt the platform after CV/Migration additions and their overlays/configuration. Updating affected CV/Migration/Portal components and host routing was necessary; rebuilding every existing business backend was not.
- Run `36902638801` changed a capacity script/workflow and documentation but rebuilt/deployed the platform for 11m16s. The new planner treats it as diagnostic-only.
- A frontend-only Clients correction (`36893913638`) also built/restarted its backend. Independent component selection removes that coupling.
- In the 100 most recent runs sampled during this task, there were 27 CV pull-request checks, 16 Migration pull-request checks and 6 Migration push checks. Examples include the same Migration development SHA `28d68cd` checked on push and release PR, and CV builds on both task PR and development→main PR. The separate build workflows are replaced with one integration pipeline. Notification/diagnostic runs are not image builds; raw run counts alone do not measure wasted build time.

### Follow-up: deployed-state baseline

A later Equipment release exposed a second source of redundant work: an earlier `main` deploy had completed successfully, but the workflow finished red because a post-deploy browser verification failed. The next release compared against an older fully-green workflow and therefore reselected already deployed frontend/backend components. The planner now uses the last **successful deploy job** as the `main` runtime baseline, while still requiring the normal verification job to pass for the current release. This keeps recovery safe without replaying unrelated deployment work.

This optimization initially rebuilds changed Dockerfiles and introduces verified source tags/cache. Measure subsequent warm runs and main image reuse separately from this first cache population; do not promise timings based on a cold rollout.
