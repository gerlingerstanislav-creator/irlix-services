## Miniatures in Storage V1 tile view

The tile view of “Мои файлы” and “Доступные мне” loads true, resized
320×240 JPEG thumbnails of PNG/JPEG/GIF/WebP from the private
`/api/storage/v1/thumbnail/{object_id}` route. It does not download
full-resolution originals just to shrink them in CSS. The original
authenticated `/api/storage/v1/preview/{object_id}` route remains
used only when opening the full image viewer.

The thumbnail route reuses the same owner/grant READ authorization,
file-state, content-signature and 10 MiB bound as the full preview.
No permanent publicly accessible thumbnail link, S3 key, or disk cache
is created. The browser uses lazy loading, and the response is
`private, no-store` so permission revocations cannot be bypassed via
a persistent thumbnail cache. Large pixel dimensions and malformed
image formats are rejected rather than decoded indefinitely.

## Просмотр изображений в интерфейсе Storage V1

В разделах «Мои файлы» и «Доступные мне» можно просматривать PNG, JPEG, GIF и WebP
без скачивания: нажать на название файла в списке, плитку либо «Просмотреть»
в меню изображения. Просмотрщик открывает крупное изображение, показывает
имя файла, предоставляет скачивание, листание текущего списка изображений
(кнопки или стрелки клавиатуры), масштаб 100–300%, закрытие по Esc/крестику.
Интерфейс адаптируется к узкому экрану.

Во время загрузки отображается индикатор, при ошибке или отказе доступа —
сообщение внутри окна. Устаревшие асинхронные ответы игнорируются, временные
object URL освобождаются при закрытии или переключении.

Просмотр разрешён только через авторизованный Storage V1 API: сервер проверяет
READ на конкретный файл и не выдаёт браузеру прямые S3-ссылки. HTML, SVG
и другие активные форматы не отображаются как изображения. Лимиты, квоты
и существующие роли не меняются.

# Storage Drive frontend

## Restricted SQLite debugging on the current server

For development and hands-on acceptance before full production launch, Storage
V1 can use a **transactional SQLite file** on a persistent Docker named volume
`storage_v1_metadata` under the current host filesystem. The SeaweedFS S3
bucket already deployed continues to store file bytes. No PostgreSQL service,
extra physical partition or new S3 instance is required for this stage.

- `STORAGE_V1_METADATA_BACKEND=sqlite` selects SQLite; PostgreSQL remains an
  alternative backend requiring separately provisioned Storage-owned schema.
- `STORAGE_V1_ENABLED=1` and `STORAGE_V1_ACCESS_ENABLED=1` are enabled **once**
  by the scoped debug release. An operator can subsequently set either to
  `0`; later releases do not silently re-enable it.
- Only Employees `platform-admin` and `top-management` roles can enter.
  All object rights still require owner/ACL authorization on every API call.
- The debug backend uses WAL/full fsync, foreign keys, single-writer
  transactions, immutable audit and version triggers. It **does not** provide
  high-availability or verified disaster recovery.
- Each owner is limited to 512 MiB by default, and uploads/copies are denied
  when fewer than 2 GiB would remain on the current host filesystem.
- The old administrator pilot namespace and all its files remain in S3,
  untouched. This is a **restricted debugging deployment**, not acceptance
  of the final Storage launch or a permit for physical deletion.
- Prior to wider launch decide capacity, separate volumes, PostgreSQL migration,
  backup/restore, retention and security/ACL acceptance.

## Drag-and-drop upload in Storage V1

Files from the desktop may be dropped into the **current folder's file area**
(including an empty folder), or onto a **writable folder** in either the list or
tile view. The current directory shows a drop hint; a folder target highlights.
Dropping on an existing file uploads alongside it, never overwrites the file or
creates a new version. The ordinary upload button uses the same uploader.

- Multi-file drops are uploaded individually through the existing authenticated
  `/api/storage/v1/files` endpoint with an explicit target `parent_id`.
- Storage verifies parent WRITE permission, filename, quota and per-file size.
  The browser prevents drops into Trash, onto non-editable folders or into a
  read-only current directory. The API remains the authoritative check.
- Success count and failed filenames are displayed separately: successful
  uploads remain saved, and failed uploads are not automatically retried.
- Dragging complete desktop directories (as opposed to their files) is not
  supported; show an error rather than silently creating an empty object.
- Changes are UI-only; no new backend permissions, tables, or file storage
  infrastructure are introduced.

## Canonical Storage V1 shell — staged replacement

The user-facing Drive keeps the same shared `UiAppShell` and three sidebar
sections as the original pilot: **Мои файлы**, **Доступные мне**, **Корзина**.
Each route opens the V1 browser with the original compact search/sort/list/tile
toolbar, file menus, selection and reversible trash. The old `/storage/v1`
bookmark redirects to `/storage/`; it is not an extra user-facing section.
V1 listing includes server-authorized size and modified date.

**Access boundary:** Employees grants Storage entry exclusively to assigned
`platform-admin` and `top-management` roles. Top management requires
`STORAGE_V1_ACCESS_ENABLED=1`; ordinary employees and role-matrix grants
cannot widen this eligibility. The V1 session, internal nginx gate and
directory APIs independently recheck effective access. Individual file
permissions still come exclusively from the Storage API (owner/grants).

**Release gate:** The code/UI release does not authorize enabling the V1 API
or deleting the legacy object namespace. If the server returns V1 HTTP 503,
only an authenticated platform administrator sees the previously working
pilot explorer as a temporary fallback. Top management cannot access
those legacy objects. Pilot endpoints and bytes must be retired only **after**
database provisioning, isolated capacity, verified backup and restore,
real-user ACL negative tests, and explicit legacy-object reconciliation.
No physical deletion or automatic purge is introduced. A successful
anonymous smoke or static UI build is not V1 production acceptance.


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
- **No permanent deletion, no automatic retention/purge, no non-admin sharing** is enabled. The intended recycle-bin lifetime is 30 days, but purge remains disabled until independently verified backup AND restore (including metadata). This is not yet the product-wide trash architecture. Retention, identity-bound audit, per-object ACLs, cross-service attachments, transactional metadata, version history and backup/restore remain separate release gates.

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

## P1/P2 extensions (candidate, not production-enabled)

- P1: `/v1/objects/{id}/move` changes *metadata parent* only and disallows moving across owners or into itself; file copy creates a new S3 object and independent File ID; rename preserves original bytes and file extension.
- P1: `/v1/objects/{id}/versions` and restore append immutable version records in `storage.file_versions` and preserve previous bytes. **No automatic version pruning**; old versions do count against quota and backup requirements.
- P1: `/v1/search` searches filenames with server-side ownership, inherited-grant and active-state ACL rechecks. No full text extraction, no Office conversion, no unauthorized result counts.
- P2: bulk trash/restore/move runs bounded per-object operations with individual server authorization and per-item failure messages. Physical deletion remains unavailable.
- P2: `/v1/stats` shows owner-scoped logical size including file versions. `STORAGE_V1_QUOTA_BYTES` (default `0`, unlimited) optionally limits new version/upload/copy writes. This **does not measure actual S3 replicated bytes or physical disk usage**. Capacity and disk metrics require separate monitoring.
- Apply `001_storage_acl_audit.sql` followed by `002_file_versions.sql` in a disposable environment after S3+metadata backup. V1 remains disabled by both service admission flags until credentials, backup/restore, ACL, security and real-user QA are verified.
- Authenticated V1 preview supports only bounded, whitelisted PDF, PNG/JPEG/GIF/WebP, UTF-8 text, CSV/log, and short MP4/WebM using a private no-store response and client-managed blob URLs. No active HTML, SVG or Office document is rendered inline. The same server-side file ACL applies to preview and download; antivirus/quarantine is still a release prerequisite.
- Office document preview/conversion, automated retention, full-text document indexing and integrations with business service attachment histories are **not** part of these opt-in features. Do not enable public or external links.

## Permanent file deletion in Storage V1 (activation gated)

The owner or platform administrator can explicitly purge **individual files
from Trash** with a filename confirmation. All application-level S3 working
versions are deleted and verified missing; immutable audit and version
descriptors remain as technical tombstones. Existing file names become
available again and bytes are excluded from quota accounting. No folder
or bulk irreversible deletion is provided.

A durable purge request is committed before touching S3, immediately
denying restore and all direct reads, and allowing reattempt after partial
S3 failure. The UI offers "Повторить полное удаление" on a pending file.
No background automatic purge is introduced. S3 native versioning must be
disabled, or the request fails closed without deleting bytes; all existing
application version keys are individually removed.

The feature is **disabled by default** outside the restricted
test deployment: `STORAGE_V1_PERMANENT_DELETE_ENABLED=0` (or unset).
For the currently authorized **Storage V1 SQLite debug pilot only**, the
deploy script enables the flag once when `STORAGE_V1_ENABLED=1` and
`STORAGE_V1_ACCESS_ENABLED=1`, recording
`STORAGE_V1_TEST_PURGE_BOOTSTRAPPED=1`. A later operator override
to disable the feature is preserved across subsequent deploys.
The UI displays `Удалить навсегда` inside the Trash only while the
backend returns `permanent_delete_enabled=true`.

**TEST MODE DATA LOSS:** working files and their app-level history cannot
be recovered without external backups; the owner explicitly accepts this
limitation for the restricted test service. There is no automatic purge.
Server-side ownership/admin checks, exact filename confirmation, S3 native
versioning refusal and pending-delete retry protection remain mandatory.
For any future production-ready Storage rollout, disable this test flag
and require verified backup/restore and the approved retention policy.

Only live working-copy bytes are deleted. Backups and replicas are not
modified; their expiration depends on separately configured retention
policies, which are not implemented or validated by this feature.
