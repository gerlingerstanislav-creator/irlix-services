# Storage Drive frontend

The independent Storage UI lives in `apps/storage` and uses `UiAppShell` from `@irlix/ui`. It remains an **administrator-only pilot**, independently gated by Employees access, the edge proxy and Storage API. UI navigation does not grant object permissions.

## Sections and navigation

- `/storage/`: **Мои файлы** — existing server-backed folder listing, upload, download and preview. `?folder=<encoded path>` is the current folder. The shared topbar receives folder breadcrumbs, and browser Back/Forward restores the selected folder.
- `/storage/available`: **Доступные мне** — an explicitly unavailable section until shared-file ACLs and backend contracts are approved and implemented. It does not request or reveal other users' files.
- Other `/storage/*` paths render the shared 404 state, rather than silently opening the personal drive.

Inside the file view, filters and compact shared `UiButton` actions share one toolbar. The table fills the workspace edge-to-edge without rounded corners. The list/tile control uses two explicit icon buttons. A shared `UiActionMenu` is available on rows and in the top-right corner of tiles. Success/errors are temporary bottom-right notifications; actionable authentication errors retain the dashboard link. The 10 MiB upload pilot limit is unchanged.

## Pilot rename and thumbnails

- `POST /api/storage/rename` edits the **display name** of a file/folder, not its original immutable S3 object key. Display aliases live in private per-parent-folder JSON metadata objects under `pilot-ui-meta/` (not exposed via the file API); the file bytes and folder markers are not moved or deleted. Existing stored folder URLs therefore remain stable even when their visible breadcrumbs change.
- Name validation, unchanged file extensions, same-folder collision checks and the administrator-only cookie + origin/CSRF guard apply on the server. Files may be downloaded with their edited display name; their original MIME type still governs preview safety.
- `GET /api/storage/thumbnails?key=` returns a reduced 320×240 maximum JPEG, with a source-size/pixel budget and the normal authorization guard. Image tiles use browser lazy loading, fall back to icons if decoding fails, and never receive object-store credentials.
- **Known pilot constraints:** metadata writes are not concurrency-safe for simultaneous edits in the same folder; rename is a display-label change rather than physical object relocation. There is no delete/trash operation, version history, audit event stream or shared-file permissions yet. These require a persisted metadata/ACL architecture before expanding beyond the pilot.

## Checks

- `node --test apps/storage/tests/contracts.test.mjs` from repository root.
- `docker build -f apps/storage/Dockerfile .` for compilation and the standalone nginx image.
- `docker build -f services/storage-api/Dockerfile .` for the FastAPI tests (including rename safety and image thumbnails).
- Browser smoke: direct/deep URL, refresh, Back/Forward, folder path breadcrumb links, unauthorized pilot access, unknown path 404, upload/folder success/errors and toast expiration. Do not claim these checks passed unless actually run.

This UI update does not implement sharing, delegated access, versions, public links or a production Storage rollout.

## Pilot recycle bin (P0 foundation)

The admin-only pilot supports **reversible** moves to the bin:
- `POST /trash` accepts validated `kind=file + key` or `kind=folder + path` with the existing server-side cookie gate and CSRF/origin policy. It writes a separate metadata marker, never deleting or relocating the S3 content. A trashed folder implicitly hides every descendant.
- The list endpoint `GET /trash` shows active trash markers, and `POST /restore` clears the hidden state with another marker write. Original object keys remain stable. The UI supplies a confirmation dialog and a dedicated sidebar page.
- Direct GET/download/preview/thumbnail and folder listing of trashed objects are revoked on the server, not just visually hidden. Uploading to a hidden folder is rejected, and a deleted folder name is reserved for recovery.
- Markers live under `pilot-ui-trash/` and are invisible to user file reads. Restored markers are retained to avoid physical deletion.
- **No permanent deletion, no automatic retention/purge, no non-admin sharing** is enabled. This is not yet the product-wide trash architecture. Retention, identity-bound audit, per-object ACLs, cross-service attachments, transactional metadata, version history and backup/restore remain separate release gates.

Regression tests: `services/storage-api/tests/test_main.py` covers non-destructive file and folder recovery and direct-link denial. Do not publish outside the pilot or interpret a successful build as a security signoff.

## Storage V1 ACL candidate — disabled until infrastructure readiness

A new **isolated** V1 implementation resides in `services/storage-api/app/access_policy.py`, `access_service.py`, `metadata_repository.py` and `v1_api.py`. It must **not** be confused with the legacy admin-only `pilot-ui/` files.

- `STORAGE_V1_ENABLED=0` by default; `STORAGE_V1_DB_DSN` must point to Storage's own PostgreSQL database/schema. The V1 API denies with 503 when disabled or its database is unreachable.
- Migration `services/storage-api/migrations/001_storage_acl_audit.sql` creates objects, grants and append-only audit. Run it using a dedicated migration role, not the API role. Provision a runtime role with SELECT/INSERT/UPDATE on objects/grants, SELECT/INSERT on audit (no UPDATE/DELETE on audit or content objects), and USAGE on the schema; configure the runtime role outside Git. **Do not run SQL in a real environment before creating and successfully restoring a separate backup of existing S3 metadata.**
- Employees emits an authoritative active employee ID only after validating its short-lived encrypted cookie on the server. The new metadata repository stores **scalar** Employee IDs; it never accesses Employees database tables. Grant recipients are validated against an internal Employees endpoint on each request; client-supplied employee IDs or role names are never accepted as actor authentication.
- V1 API `/api/storage/v1/` uses a **separate** short-lived active Employees cookie and server-to-server revalidation on every request; the legacy `/api/storage/` remains platform-admin only. Vue static assets at `/storage/` contain no business data and are no longer gated as admin-only; the SPA Keycloak checks enforce service admission and show 403 for non-admin legacy deep links. `GET /api/storage/v1/*` does not trust any browser employee ID or role header.
- Ordinary staff access requires **both** `STORAGE_V1_ACCESS_ENABLED=1` on Employees and `STORAGE_V1_ENABLED=1` plus Storage's provisioned DB connection on storage-api. Both flags are **0 by default**. Turning on these flags without completed negative multi-user security tests and backup+restore is prohibited.
- UI `/storage/v1` can create V1 files/folders, browse direct and shared grants, download, revoke, move to the reversible trash and restore without touching legacy object keys. Directory picker only receives minimal authorized employee names/IDs and department tree from Employees. No non-admin production rollout is implied by this candidate.
- All V1 S3 bytes use the separate `v1/` prefix. Existing `pilot-ui/` objects are never imported or automatically shared. Ownership and grants apply only to registered V1 metadata objects.
- Retention target is 30 days, but **no automated purge, physical deletion or irreversible cleanup** is enabled until backup AND restore are independently demonstrated and any legal hold policy is approved.
- Remaining production gates: independent DB provisioning, negative role+scope integration tests including two real test users, race/revocation checks, identity offboarding, concurrent writes, backup/restore of S3 plus metadata, capacity and audit verification, and non-admin gateway + UI authorization. Do not claim these checks passed based on unit tests or CI build alone.
- The new V1 UI shares company navigation components (`UiAppShell`, `UiButton`, `UiSearchSelect`, `UiActionMenu`). The employee directory tree uses authenticated Employees API data; the server rechecks eligibility independently.
