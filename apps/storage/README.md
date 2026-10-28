# Storage Drive frontend

The independent Storage UI lives in `apps/storage` and uses `UiAppShell` from `@irlix/ui`. It remains an **administrator-only pilot**, independently gated by Employees access, the edge proxy and Storage API. UI navigation does not grant object permissions.

## Sections and navigation

- `/storage/`: **Мои файлы** — existing server-backed folder listing, upload, download and preview. `?folder=<encoded path>` is the current folder. The shared topbar receives folder breadcrumbs, and browser Back/Forward restores the selected folder.
- `/storage/available`: **Доступные мне** — an explicitly unavailable section until shared-file ACLs and backend contracts are approved and implemented. It does not request or reveal other users' files.
- Other `/storage/*` paths render the shared 404 state, rather than silently opening the personal drive.

Inside the file view, filters and compact shared `UiButton` actions share one toolbar. Success/errors are temporary bottom-right notifications; actionable authentication errors retain the dashboard link. The 10 MiB upload pilot limit is unchanged.

## Checks

- `node --test apps/storage/tests/contracts.test.mjs` from repository root.
- `docker build -f apps/storage/Dockerfile .` for compilation and the standalone nginx image.
- Browser smoke: direct/deep URL, refresh, Back/Forward, folder path breadcrumb links, unauthorized pilot access, unknown path 404, upload/folder success/errors and toast expiration. Do not claim these checks passed unless actually run.

This UI update does not implement sharing, delegated access, versions, public links or a production Storage rollout.
